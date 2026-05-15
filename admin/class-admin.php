<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skillsaw_Admin {

	public function add_menu_pages() {
		add_menu_page(
			'Skillsaw',
			'Skillsaw',
			'manage_options',
			'skillsaw',
			array( $this, 'render_dashboard_page' ),
			'dashicons-format-chat',
			30
		);

		add_submenu_page(
			'skillsaw',
			'Skillsaw — Dashboard',
			'Dashboard',
			'manage_options',
			'skillsaw',
			array( $this, 'render_dashboard_page' )
		);

		add_submenu_page(
			'skillsaw',
			'Skillsaw — Settings',
			'Settings',
			'manage_options',
			'skillsaw-settings',
			array( $this, 'render_settings_page' )
		);
	}

	public function enqueue_scripts( $hook ) {
		if ( strpos( $hook, 'skillsaw' ) === false ) {
			return;
		}

		$asset_file = SKILLSAW_PLUGIN_DIR . 'assets/js/index.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array( 'wp-element', 'wp-components', 'wp-api-fetch' ),
			'version'      => SKILLSAW_VERSION,
		);

		wp_enqueue_script(
			'skillsaw-admin',
			SKILLSAW_PLUGIN_URL . 'assets/js/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_localize_script(
			'skillsaw-admin',
			'skillsawData',
			array(
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'rootUrl' => rest_url(),
				'version' => SKILLSAW_VERSION,
			)
		);

		wp_enqueue_style( 'wp-components' );

		wp_enqueue_style(
			'skillsaw-admin',
			SKILLSAW_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			SKILLSAW_VERSION
		);
	}

	public function render_dashboard_page() {
		require_once SKILLSAW_PLUGIN_DIR . 'admin/views/page-dashboard.php';
	}

	public function render_settings_page() {
		require_once SKILLSAW_PLUGIN_DIR . 'admin/views/page-settings.php';
	}

	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}
		check_admin_referer( 'skillsaw_settings' );

		$masked_fields  = array( 'anthropic_key', 'greenhouse_key' );
		$plain_fields   = array( 'greenhouse_board_token', 'greenhouse_user_id' );

		foreach ( $masked_fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$value = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
				// Only update if the submitted value is not the masked placeholder.
				if ( $value !== '••••••••' ) {
					Skillsaw_Settings::set( $field, $value );
				}
			}
		}

		foreach ( $plain_fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				Skillsaw_Settings::set( $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=skillsaw-settings&saved=1' ) );
		exit;
	}
}
