<?php
/**
 * Plugin Name: Skillsaw
 * Plugin URI:  https://automattic.com
 * Description: Candidate evaluation chatbot for Automattic's hiring workflow.
 * Version:     0.3.0
 * Author:      Automattic
 * License:     GPL-2.0-or-later
 * Text Domain: skillsaw
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SKILLSAW_VERSION', '0.3.0' );
define( 'SKILLSAW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SKILLSAW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SKILLSAW_PLUGIN_FILE', __FILE__ );

require_once SKILLSAW_PLUGIN_DIR . 'includes/class-activator.php';
require_once SKILLSAW_PLUGIN_DIR . 'includes/class-skillsaw.php';

register_activation_hook( __FILE__, array( 'Skillsaw_Activator', 'activate' ) );

add_action( 'plugins_loaded', function () {
	if ( get_option( 'skillsaw_db_version' ) !== SKILLSAW_VERSION ) {
		Skillsaw_Activator::activate();
	}
} );

function skillsaw_run() {
	$plugin = new Skillsaw();
	$plugin->run();
}
skillsaw_run();
