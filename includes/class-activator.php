<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skillsaw_Activator {

	public static function activate() {
		self::create_tables();
	}

	private static function create_tables() {
		global $wpdb;
		$c = $wpdb->get_charset_collate();

		$sql = array(
			"CREATE TABLE {$wpdb->prefix}skillsaw_roles (
				id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				title varchar(255) NOT NULL,
				division varchar(100) NOT NULL DEFAULT '',
				team varchar(100) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'draft',
				instructions longtext NOT NULL DEFAULT '',
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id)
			) $c;",

			"CREATE TABLE {$wpdb->prefix}skillsaw_skills (
				id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				role_id bigint(20) UNSIGNED NOT NULL,
				name varchar(255) NOT NULL,
				sort_order int(11) NOT NULL DEFAULT 0,
				PRIMARY KEY (id),
				KEY role_id (role_id)
			) $c;",

			"CREATE TABLE {$wpdb->prefix}skillsaw_documents (
				id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				role_id bigint(20) UNSIGNED NOT NULL,
				attachment_id bigint(20) UNSIGNED DEFAULT NULL,
				name varchar(255) NOT NULL,
				type varchar(20) NOT NULL DEFAULT '',
				skills longtext NOT NULL DEFAULT '',
				is_critique_version tinyint(1) NOT NULL DEFAULT 0,
				parent_document_id bigint(20) UNSIGNED DEFAULT NULL,
				critique_text longtext DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY role_id (role_id)
			) $c;",

			"CREATE TABLE {$wpdb->prefix}skillsaw_sessions (
				id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				role_id bigint(20) UNSIGNED NOT NULL,
				session_token varchar(64) NOT NULL,
				candidate_name varchar(255) NOT NULL DEFAULT '',
				candidate_email varchar(255) NOT NULL DEFAULT '',
				mode varchar(20) NOT NULL DEFAULT '',
				status varchar(30) NOT NULL DEFAULT 'in_progress',
				started_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				completed_at datetime DEFAULT NULL,
				ip_hash varchar(64) NOT NULL DEFAULT '',
				greenhouse_candidate_id varchar(100) NOT NULL DEFAULT '',
				gh_pushed_at datetime DEFAULT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY session_token (session_token),
				KEY role_id (role_id),
				KEY candidate_email (candidate_email)
			) $c;",

			"CREATE TABLE {$wpdb->prefix}skillsaw_messages (
				id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				session_id bigint(20) UNSIGNED NOT NULL,
				role varchar(10) NOT NULL,
				content longtext NOT NULL,
				attachment_id bigint(20) UNSIGNED DEFAULT NULL,
				candidate_skills text DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY session_id (session_id)
			) $c;",

			"CREATE TABLE {$wpdb->prefix}skillsaw_skill_ratings (
				id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				session_id bigint(20) UNSIGNED NOT NULL,
				skill_name varchar(255) NOT NULL,
				rating varchar(30) NOT NULL,
				PRIMARY KEY (id),
				KEY session_id (session_id)
			) $c;",
		);

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( $sql as $query ) {
			dbDelta( $query );
		}

		update_option( 'skillsaw_db_version', SKILLSAW_VERSION );
	}
}
