<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skillsaw_Settings {

	public static function get( $key, $default = '' ) {
		return get_option( 'skillsaw_' . $key, $default );
	}

	public static function set( $key, $value ) {
		update_option( 'skillsaw_' . $key, $value );
	}

	public static function get_anthropic_key() {
		return self::get( 'anthropic_key' );
	}

	public static function get_greenhouse_key() {
		return self::get( 'greenhouse_key' );
	}

	public static function get_greenhouse_board_token() {
		return self::get( 'greenhouse_board_token' );
	}
}
