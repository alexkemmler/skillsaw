<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skillsaw_Sessions {

	const RATE_LIMIT  = 5;
	const RATE_WINDOW = DAY_IN_SECONDS;

	/**
	 * Create a new session row after checking rate limits.
	 * Returns the session token string, or WP_Error on failure.
	 */
	public function create_session( $role_id, $mode, $ip ) {
		global $wpdb;

		$ip_hash = hash( 'sha256', $ip );

		if ( $this->is_rate_limited( $role_id, $ip_hash ) ) {
			return new WP_Error(
				'rate_limited',
				'Too many sessions started. Please try again later.',
				array( 'status' => 429 )
			);
		}

		$token = wp_generate_uuid4();

		$wpdb->insert( "{$wpdb->prefix}skillsaw_sessions", array(
			'role_id'       => $role_id,
			'session_token' => $token,
			'mode'          => $mode,
			'status'        => 'in_progress',
			'ip_hash'       => $ip_hash,
		) );

		$this->increment_rate_limit( $role_id, $ip_hash );

		return $token;
	}

	/**
	 * Fetch a session (with role data joined) by its token.
	 */
	public function get_session_by_token( $token ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT s.*, r.title as role_title, r.instructions, r.division, r.team
				 FROM {$wpdb->prefix}skillsaw_sessions s
				 JOIN {$wpdb->prefix}skillsaw_roles r ON r.id = s.role_id
				 WHERE s.session_token = %s",
				$token
			),
			ARRAY_A
		);
	}

	/**
	 * Persist a single chat message.
	 */
	public function save_message( $session_id, $role, $content, $attachment_id = null ) {
		global $wpdb;

		$data = array(
			'session_id' => $session_id,
			'role'       => $role,
			'content'    => $content,
		);

		if ( $attachment_id ) {
			$data['attachment_id'] = $attachment_id;
		}

		$wpdb->insert( "{$wpdb->prefix}skillsaw_messages", $data );

		return $wpdb->insert_id;
	}

	/**
	 * Return all messages for a session formatted for the Claude API.
	 *
	 * Claude requires conversations to start with a user turn, but we store
	 * the bot's opening message first. We prepend a synthetic user seed so
	 * the history is always well-formed for the API.
	 */
	public function get_messages_for_claude( $session_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT role, content FROM {$wpdb->prefix}skillsaw_messages
				 WHERE session_id = %d ORDER BY created_at ASC",
				$session_id
			),
			ARRAY_A
		);

		$messages = array_map( function ( $row ) {
			$msg = array(
				'role'    => $row['role'] === 'bot' ? 'assistant' : 'user',
				'content' => $row['content'],
			);
			if ( $row['attachment_id'] ) {
				$msg['_attachment_id'] = (int) $row['attachment_id'];
			}
			return $msg;
		}, $rows );

		// Ensure the conversation starts with a user turn.
		if ( empty( $messages ) || $messages[0]['role'] === 'assistant' ) {
			array_unshift( $messages, array( 'role' => 'user', 'content' => 'Please begin.' ) );
		}

		return $messages;
	}

	/**
	 * Count messages in a session (used to enforce per-session cap).
	 */
	public function get_message_count( $session_id ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}skillsaw_messages WHERE session_id = %d",
				$session_id
			)
		);
	}

	/**
	 * Attach skill tags to an upload message.
	 */
	public function save_upload_skills( $message_id, $skills ) {
		global $wpdb;
		$wpdb->update(
			"{$wpdb->prefix}skillsaw_messages",
			array( 'candidate_skills' => wp_json_encode( $skills ) ),
			array( 'id' => $message_id )
		);
	}

	/**
	 * Mark a single session as expired.
	 */
	public function expire_session( $session_id ) {
		global $wpdb;
		$wpdb->update(
			"{$wpdb->prefix}skillsaw_sessions",
			array( 'status' => 'expired' ),
			array( 'id' => $session_id )
		);
	}

	/**
	 * Bulk-expire sessions that have been in_progress for more than 4 hours.
	 * Called by the hourly cron job.
	 */
	public function expire_old_sessions() {
		global $wpdb;
		$wpdb->query(
			"UPDATE {$wpdb->prefix}skillsaw_sessions
			 SET status = 'expired'
			 WHERE status = 'in_progress'
			 AND started_at < DATE_SUB( NOW(), INTERVAL 4 HOUR )"
		);
	}

	/**
	 * Mark a session complete and record the candidate's name and email.
	 */
	public function complete_session( $session_id, $name, $email ) {
		global $wpdb;

		$wpdb->update(
			"{$wpdb->prefix}skillsaw_sessions",
			array(
				'candidate_name'  => sanitize_text_field( $name ),
				'candidate_email' => sanitize_email( $email ),
				'status'          => 'complete',
				'completed_at'    => current_time( 'mysql' ),
			),
			array( 'id' => $session_id )
		);
	}

	/**
	 * Update candidate name/email on an already-complete session.
	 * Used when the form submit fires after endSession() already finalized.
	 */
	public function update_identity( $session_id, $name, $email ) {
		global $wpdb;

		$data = array();
		if ( $name )  $data['candidate_name']  = sanitize_text_field( $name );
		if ( $email ) $data['candidate_email'] = sanitize_email( $email );

		if ( $data ) {
			$wpdb->update( "{$wpdb->prefix}skillsaw_sessions", $data, array( 'id' => $session_id ) );
		}
	}

	// -------------------------------------------------------------------------
	// Rate limiting
	// -------------------------------------------------------------------------

	private function is_rate_limited( $role_id, $ip_hash ) {
		$key   = "skillsaw_rl_{$ip_hash}_{$role_id}";
		$count = (int) get_transient( $key );
		return $count >= self::RATE_LIMIT;
	}

	private function increment_rate_limit( $role_id, $ip_hash ) {
		$key   = "skillsaw_rl_{$ip_hash}_{$role_id}";
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, self::RATE_WINDOW );
	}
}
