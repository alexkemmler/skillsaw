<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skillsaw {

	private static $embed_on_page = false;

	public function run() {
		$this->load_dependencies();
		$this->define_admin_hooks();
		$this->define_public_hooks();
	}

	private function load_dependencies() {
		require_once SKILLSAW_PLUGIN_DIR . 'includes/class-settings.php';
		require_once SKILLSAW_PLUGIN_DIR . 'includes/class-pdf.php';
		require_once SKILLSAW_PLUGIN_DIR . 'includes/class-claude.php';
		require_once SKILLSAW_PLUGIN_DIR . 'includes/class-greenhouse.php';
		require_once SKILLSAW_PLUGIN_DIR . 'includes/class-api.php';
		require_once SKILLSAW_PLUGIN_DIR . 'includes/class-sessions.php';
		require_once SKILLSAW_PLUGIN_DIR . 'includes/class-evaluator.php';
		require_once SKILLSAW_PLUGIN_DIR . 'admin/class-admin.php';
	}

	private function define_admin_hooks() {
		$admin = new Skillsaw_Admin();
		add_action( 'admin_menu', array( $admin, 'add_menu_pages' ) );
		add_action( 'admin_enqueue_scripts', array( $admin, 'enqueue_scripts' ) );
		add_action( 'admin_post_skillsaw_save_settings', array( $admin, 'save_settings' ) );

		$api = new Skillsaw_API();
		add_action( 'rest_api_init', array( $api, 'register_routes' ) );
	}

	private function define_public_hooks() {
		add_shortcode( 'skillsaw', array( $this, 'render_shortcode' ) );
		add_shortcode( 'skillsaw_apply', array( $this, 'render_apply_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_embed_assets' ) );

		add_action( 'skillsaw_evaluate_session', function ( $session_id ) {
			$evaluator = new Skillsaw_Evaluator();
			$evaluator->evaluate_session( $session_id );
		} );

		add_action( 'skillsaw_expire_sessions', function () {
			$sessions = new Skillsaw_Sessions();
			$sessions->expire_old_sessions();
		} );
	}

	// -------------------------------------------------------------------------
	// Shortcode: [skillsaw role="123"]
	// -------------------------------------------------------------------------

	public function render_shortcode( $atts ) {
		$atts    = shortcode_atts( array( 'role' => '' ), $atts );
		$role_id = (int) preg_replace( '/\D/', '', $atts['role'] );

		if ( ! $role_id ) {
			return '';
		}

		global $wpdb;

		$role = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, title, status FROM {$wpdb->prefix}skillsaw_roles WHERE id = %d",
				$role_id
			),
			ARRAY_A
		);

		if ( ! $role || $role['status'] === 'draft' ) {
			return '';
		}

		$active = $role['status'] === 'active';

		$critique_docs = array();
		if ( $active ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT c.id, COALESCE(p.name, c.name) as display_name
					 FROM {$wpdb->prefix}skillsaw_documents c
					 LEFT JOIN {$wpdb->prefix}skillsaw_documents p ON p.id = c.parent_document_id
					 WHERE c.role_id = %d AND c.is_critique_version = 1
					 ORDER BY c.created_at ASC",
					$role_id
				),
				ARRAY_A
			);
			$critique_docs = $rows ?: array();
		}
		$has_critique = ! empty( $critique_docs );

		$skills = $active ? $wpdb->get_col(
			$wpdb->prepare(
				"SELECT name FROM {$wpdb->prefix}skillsaw_skills WHERE role_id = %d ORDER BY sort_order ASC",
				$role_id
			)
		) : array();

		self::$embed_on_page = true;

		return sprintf(
			'<div class="skillsaw-embed" data-role-id="%d" data-active="%s" data-has-critique="%s" data-role-title="%s" data-role-skills="%s" data-critique-docs="%s"></div>',
			esc_attr( $role_id ),
			$active ? 'true' : 'false',
			$has_critique ? 'true' : 'false',
			esc_attr( $role['title'] ),
			esc_attr( wp_json_encode( $skills ) ),
			esc_attr( wp_json_encode( $critique_docs ) )
		);
	}

	// -------------------------------------------------------------------------
	// Shortcode: [skillsaw_apply role="123"]
	// -------------------------------------------------------------------------

	public function render_apply_shortcode( $atts ) {
		$atts    = shortcode_atts( array( 'role' => '' ), $atts );
		$role_id = (int) preg_replace( '/\D/', '', $atts['role'] );

		if ( ! $role_id ) {
			return '';
		}

		global $wpdb;

		$role = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, title, division, team, status FROM {$wpdb->prefix}skillsaw_roles WHERE id = %d",
				$role_id
			),
			ARRAY_A
		);

		if ( ! $role || $role['status'] === 'draft' ) {
			return '';
		}

		self::$embed_on_page = true;

		// Handle POST submission.
		if ( isset( $_POST['skillsaw_apply_nonce'] ) ) {
			if ( ! wp_verify_nonce( sanitize_key( $_POST['skillsaw_apply_nonce'] ), 'skillsaw_apply_' . $role_id ) ) {
				return '<p>' . esc_html__( 'Security check failed. Please try again.', 'skillsaw' ) . '</p>';
			}
			$this->process_apply_submission( $role );
			return $this->render_apply_thankyou( $role );
		}

		ob_start();
		?>
		<div class="skillsaw-apply-page">
			<h1 class="skillsaw-apply-role-title"><?php echo esc_html( $role['title'] ); ?></h1>
			<?php
			$subtitle = implode( ' · ', array_filter( array( $role['division'], $role['team'] ) ) );
			if ( $subtitle ) : ?>
				<p class="skillsaw-apply-location"><?php echo esc_html( $subtitle ); ?></p>
			<?php endif; ?>

			<div class="skillsaw-apply-chat">
				<?php echo $this->render_shortcode( array( 'role' => $role_id ) ); ?>
			</div>

			<div class="skillsaw-apply-form">
				<h3><?php esc_html_e( 'Complete your application', 'skillsaw' ); ?></h3>

				<form id="application_form" method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'skillsaw_apply_' . $role_id, 'skillsaw_apply_nonce' ); ?>
					<input type="hidden" name="skillsaw_role_id" value="<?php echo esc_attr( $role_id ); ?>">

					<div class="skillsaw-form-row skillsaw-form-row--half">
						<div class="skillsaw-form-group">
							<label for="first_name"><?php esc_html_e( 'First name', 'skillsaw' ); ?> <span aria-hidden="true">*</span></label>
							<input type="text" id="first_name" name="first_name" required autocomplete="given-name">
						</div>
						<div class="skillsaw-form-group">
							<label for="last_name"><?php esc_html_e( 'Last name', 'skillsaw' ); ?> <span aria-hidden="true">*</span></label>
							<input type="text" id="last_name" name="last_name" required autocomplete="family-name">
						</div>
					</div>

					<div class="skillsaw-form-row">
						<div class="skillsaw-form-group">
							<label for="preferred_name"><?php esc_html_e( 'Preferred first name', 'skillsaw' ); ?></label>
							<input type="text" id="preferred_name" name="preferred_name" autocomplete="nickname">
							<p class="skillsaw-form-help"><?php esc_html_e( 'If different from your legal first name — this is how we\'ll address you.', 'skillsaw' ); ?></p>
						</div>
					</div>

					<div class="skillsaw-form-row skillsaw-form-row--half">
						<div class="skillsaw-form-group">
							<label for="email"><?php esc_html_e( 'Email', 'skillsaw' ); ?> <span aria-hidden="true">*</span></label>
							<input type="email" id="email" name="email" required autocomplete="email">
						</div>
						<div class="skillsaw-form-group">
							<label for="phone"><?php esc_html_e( 'Phone', 'skillsaw' ); ?></label>
							<input type="tel" id="phone" name="phone" autocomplete="tel">
						</div>
					</div>

					<div class="skillsaw-form-row">
						<div class="skillsaw-form-group">
							<label for="location"><?php esc_html_e( 'City, State / Country', 'skillsaw' ); ?> <span aria-hidden="true">*</span></label>
							<input type="text" id="location" name="location" required autocomplete="address-level2">
						</div>
					</div>

					<div class="skillsaw-form-row">
						<div class="skillsaw-form-group">
							<label for="resume"><?php esc_html_e( 'Resume / CV', 'skillsaw' ); ?> <span aria-hidden="true">*</span></label>
							<input type="file" id="resume" name="resume" accept=".pdf,.docx,.doc,.txt" required>
							<p class="skillsaw-form-help"><?php esc_html_e( 'PDF, DOCX, or TXT. Max 5 MB.', 'skillsaw' ); ?></p>
						</div>
					</div>

					<div class="skillsaw-form-row">
						<div class="skillsaw-form-group">
							<label for="cover_letter"><?php esc_html_e( 'Cover letter', 'skillsaw' ); ?></label>
							<textarea id="cover_letter" name="cover_letter" rows="6" placeholder="<?php esc_attr_e( 'Tell us about yourself and why you\'re interested in this role…', 'skillsaw' ); ?>"></textarea>
						</div>
					</div>

					<div class="skillsaw-form-row">
						<div class="skillsaw-form-group">
							<label for="essay_craft"><?php esc_html_e( 'What\'s an idea, book, blog post, or talk that recently changed how you think about your craft?', 'skillsaw' ); ?> <span aria-hidden="true">*</span></label>
							<p class="skillsaw-form-help"><?php esc_html_e( 'What is it? How did it change the way you build, collaborate, or make decisions?', 'skillsaw' ); ?></p>
							<textarea id="essay_craft" name="essay_craft" rows="6" required></textarea>
						</div>
					</div>

					<hr class="skillsaw-form-divider">

					<p class="skillsaw-form-section-title"><?php esc_html_e( 'Links', 'skillsaw' ); ?></p>

					<div class="skillsaw-form-row">
						<div class="skillsaw-form-group">
							<label for="linkedin"><?php esc_html_e( 'LinkedIn profile URL', 'skillsaw' ); ?></label>
							<input type="url" id="linkedin" name="linkedin" placeholder="https://linkedin.com/in/…" autocomplete="url">
						</div>
					</div>

					<div class="skillsaw-form-row">
						<div class="skillsaw-form-group">
							<label for="website"><?php esc_html_e( 'Website / portfolio URL', 'skillsaw' ); ?></label>
							<input type="url" id="website" name="website" placeholder="https://…" autocomplete="url">
						</div>
					</div>

					<hr class="skillsaw-form-divider">

					<p class="skillsaw-form-section-title"><?php esc_html_e( 'Additional information', 'skillsaw' ); ?></p>

					<div class="skillsaw-form-row">
						<div class="skillsaw-form-group">
							<label for="heard_about"><?php esc_html_e( 'How did you hear about this role?', 'skillsaw' ); ?></label>
							<select id="heard_about" name="heard_about">
								<option value=""><?php esc_html_e( '— Select —', 'skillsaw' ); ?></option>
								<option value="linkedin"><?php esc_html_e( 'LinkedIn', 'skillsaw' ); ?></option>
								<option value="referral"><?php esc_html_e( 'Employee referral', 'skillsaw' ); ?></option>
								<option value="job_board"><?php esc_html_e( 'Job board', 'skillsaw' ); ?></option>
								<option value="company_website"><?php esc_html_e( 'Company website', 'skillsaw' ); ?></option>
								<option value="other"><?php esc_html_e( 'Other', 'skillsaw' ); ?></option>
							</select>
						</div>
					</div>

					<div class="skillsaw-form-row skillsaw-form-row--half">
						<div class="skillsaw-form-group">
							<label for="salary_expectation"><?php esc_html_e( 'Salary expectation', 'skillsaw' ); ?></label>
							<input type="text" id="salary_expectation" name="salary_expectation" placeholder="<?php esc_attr_e( 'e.g. $120,000–$140,000', 'skillsaw' ); ?>">
						</div>
					</div>

					<div class="skillsaw-form-row">
						<div class="skillsaw-form-group">
							<label for="additional_context"><?php esc_html_e( 'Anything else you\'d like us to know?', 'skillsaw' ); ?></label>
							<textarea id="additional_context" name="additional_context" rows="4"></textarea>
						</div>
					</div>

					<div class="skillsaw-form-submit">
						<button type="submit" class="skillsaw-apply-submit"><?php esc_html_e( 'Submit application', 'skillsaw' ); ?></button>
					</div>
				</form>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	private function process_apply_submission( $role ) {
		$str  = function( $key ) { return sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) ); };
		$area = function( $key ) { return sanitize_textarea_field( wp_unslash( $_POST[ $key ] ?? '' ) ); };
		$url  = function( $key ) { return esc_url_raw( wp_unslash( $_POST[ $key ] ?? '' ) ); };

		$fields = array(
			'First name'       => $str( 'first_name' ),
			'Last name'        => $str( 'last_name' ),
			'Preferred name'   => $str( 'preferred_name' ),
			'Email'            => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
			'Phone'            => $str( 'phone' ),
			'Location'         => $str( 'location' ),
			'LinkedIn'         => $url( 'linkedin' ),
			'Website'          => $url( 'website' ),
			'How they heard'   => $str( 'heard_about' ),
			'Salary expectation' => $str( 'salary_expectation' ),
			'Cover letter'     => $area( 'cover_letter' ),
			'Essay (craft)'    => $area( 'essay_craft' ),
			'Additional notes' => $area( 'additional_context' ),
		);

		$resume_name = '';
		if ( ! empty( $_FILES['resume']['name'] ) ) {
			$resume_name = sanitize_file_name( $_FILES['resume']['name'] );
		}

		$body  = "New application received via Skillsaw.\n\n";
		$body .= 'Role: ' . $role['title'] . "\n\n";
		foreach ( $fields as $label => $value ) {
			if ( $value !== '' ) {
				$body .= $label . ":\n" . $value . "\n\n";
			}
		}
		if ( $resume_name ) {
			$body .= "Resume file: " . $resume_name . "\n(File was submitted with the form — retrieve from Greenhouse or WP media if needed.)\n";
		}

		$to      = get_option( 'admin_email' );
		$subject = '[Skillsaw] Application: ' . $role['title'] . ' — ' . $fields['First name'] . ' ' . $fields['Last name'];
		wp_mail( $to, $subject, $body );
	}

	private function render_apply_thankyou( $role ) {
		ob_start();
		?>
		<div class="skillsaw-apply-thankyou">
			<h2><?php esc_html_e( 'Application received!', 'skillsaw' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: role title */
					esc_html__( 'Thank you for applying for %s. We\'ll be in touch if your application moves forward.', 'skillsaw' ),
					'<strong>' . esc_html( $role['title'] ) . '</strong>'
				);
				?>
			</p>
		</div>
		<?php
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Frontend asset enqueue
	// -------------------------------------------------------------------------

	public function enqueue_embed_assets() {
		// Only enqueue if this page actually has the shortcode.
		global $post;
		$has_chat  = $post && has_shortcode( $post->post_content, 'skillsaw' );
		$has_apply = $post && has_shortcode( $post->post_content, 'skillsaw_apply' );

		if ( ! $has_chat && ! $has_apply ) {
			return;
		}

		wp_enqueue_script(
			'skillsaw-embed',
			SKILLSAW_PLUGIN_URL . 'assets/js/embed.js',
			array(),
			SKILLSAW_VERSION,
			true
		);

		wp_localize_script(
			'skillsaw-embed',
			'skillsawEmbed',
			array(
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'rootUrl' => rest_url(),
			)
		);

		wp_enqueue_style(
			'skillsaw-embed',
			SKILLSAW_PLUGIN_URL . 'assets/css/embed.css',
			array(),
			SKILLSAW_VERSION
		);

		if ( $has_apply ) {
			wp_enqueue_style(
				'skillsaw-apply',
				SKILLSAW_PLUGIN_URL . 'assets/css/apply.css',
				array(),
				SKILLSAW_VERSION
			);
		}
	}
}
