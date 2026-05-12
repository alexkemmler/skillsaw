<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skillsaw {

	public function run() {
		$this->load_dependencies();
		$this->define_admin_hooks();
	}

	private function load_dependencies() {
		require_once SKILLSAW_PLUGIN_DIR . 'includes/class-settings.php';
		require_once SKILLSAW_PLUGIN_DIR . 'includes/class-claude.php';
		require_once SKILLSAW_PLUGIN_DIR . 'includes/class-api.php';
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
}
