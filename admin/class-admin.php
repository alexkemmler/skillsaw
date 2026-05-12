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
		// React bundles will be enqueued here in Phase 2.
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

		$fields = array( 'anthropic_key', 'greenhouse_key', 'greenhouse_board_token' );
		foreach ( $fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				Skillsaw_Settings::set( $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=skillsaw-settings&saved=1' ) );
		exit;
	}
}
