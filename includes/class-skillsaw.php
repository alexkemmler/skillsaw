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
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_embed_assets' ) );

		add_action( 'skillsaw_evaluate_session', function ( $session_id ) {
			$evaluator = new Skillsaw_Evaluator();
			$evaluator->evaluate_session( $session_id );
		} );
	}

	// -------------------------------------------------------------------------
	// Shortcode: [skillsaw role="123"]
	// -------------------------------------------------------------------------

	public function render_shortcode( $atts ) {
		$atts    = shortcode_atts( array( 'role' => '' ), $atts );
		$role_id = intval( $atts['role'] );

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

		$has_critique = $active && (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}skillsaw_documents
				 WHERE role_id = %d AND is_critique_version = 1",
				$role_id
			)
		);

		$skills = $active ? $wpdb->get_col(
			$wpdb->prepare(
				"SELECT name FROM {$wpdb->prefix}skillsaw_skills WHERE role_id = %d ORDER BY sort_order ASC",
				$role_id
			)
		) : array();

		self::$embed_on_page = true;

		return sprintf(
			'<div class="skillsaw-embed" data-role-id="%d" data-active="%s" data-has-critique="%s" data-role-title="%s" data-role-skills="%s"></div>',
			esc_attr( $role_id ),
			$active ? 'true' : 'false',
			$has_critique ? 'true' : 'false',
			esc_attr( $role['title'] ),
			esc_attr( wp_json_encode( $skills ) )
		);
	}

	// -------------------------------------------------------------------------
	// Frontend asset enqueue
	// -------------------------------------------------------------------------

	public function enqueue_embed_assets() {
		// Only enqueue if this page actually has the shortcode.
		global $post;
		if ( ! $post || ! has_shortcode( $post->post_content, 'skillsaw' ) ) {
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
	}
}
