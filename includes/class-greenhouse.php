<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skillsaw_Greenhouse {

	const API_BASE = 'https://harvest.greenhouse.io/v1';

	private $api_key;
	private $on_behalf_of;

	public function __construct() {
		require_once SKILLSAW_PLUGIN_DIR . 'includes/class-settings.php';
		$this->api_key      = Skillsaw_Settings::get_greenhouse_key();
		$this->on_behalf_of = Skillsaw_Settings::get_greenhouse_user_id();
	}

	/**
	 * Push a completed session to Greenhouse.
	 *
	 * Creates the candidate (Greenhouse deduplicates by email), links them to the
	 * job if the role has a greenhouse_job_id, and adds a private note containing
	 * the skill ratings.
	 *
	 * Returns the Greenhouse candidate ID on success, WP_Error on failure.
	 */
	public function push_session( array $session, array $role, array $skill_ratings ) {
		if ( ! $this->api_key ) {
			return new WP_Error( 'no_api_key', 'Greenhouse API key not configured.' );
		}

		if ( ! $this->on_behalf_of ) {
			return new WP_Error( 'no_user_id', 'Greenhouse User ID is required to post notes. Please add it in Skillsaw Settings.' );
		}

		list( $first, $last ) = $this->split_name( $session['candidate_name'] );

		$candidate_payload = array(
			'first_name'      => $first,
			'last_name'       => $last,
			'email_addresses' => array(
				array( 'value' => $session['candidate_email'], 'type' => 'personal' ),
			),
		);

		$has_job = ! empty( $role['greenhouse_job_id'] );
		if ( $has_job ) {
			$candidate_payload['applications'] = array(
				array( 'job_id' => (int) $role['greenhouse_job_id'] ),
			);
		}

		$response = $this->request( 'POST', '/candidates', $candidate_payload );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$candidate_id = $response['id'] ?? null;
		if ( ! $candidate_id ) {
			return new WP_Error( 'no_candidate_id', 'Greenhouse did not return a candidate ID.' );
		}

		$transcript  = $this->fetch_transcript( $session['id'] );
		$note        = $this->build_note( $session, $skill_ratings, $transcript );
		$note_payload = array( 'body' => $note, 'visibility' => 'private' );

		// Post to the application activity feed if we linked a job — that's
		// where the hiring team looks. Fall back to candidate feed otherwise.
		$application_id = null;
		if ( $has_job && ! empty( $response['applications'] ) ) {
			$application_id = $response['applications'][0]['id'] ?? null;
		}

		if ( $application_id ) {
			$note_result = $this->request( 'POST', "/applications/{$application_id}/activity_feed/notes", $note_payload );
		} else {
			$note_result = $this->request( 'POST', "/candidates/{$candidate_id}/activity_feed/notes", $note_payload );
		}

		if ( is_wp_error( $note_result ) ) {
			error_log( 'Skillsaw: Greenhouse note failed — ' . $note_result->get_error_message() );
		}

		return $candidate_id;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function build_note( array $session, array $skill_ratings, array $transcript = array() ) {
		$label_map = array(
			'obvious_success'   => 'Clearly demonstrated ✓✓',
			'provided_response' => 'Demonstrated ✓',
			'no_response'       => 'Not demonstrated —',
			'obvious_failure'   => 'Below threshold ✗',
		);

		$lines   = array();
		$lines[] = '=== Skillsaw Assessment ===';
		$lines[] = 'Role: ' . ( $session['role_title'] ?? '' );
		$lines[] = 'Mode: ' . ucfirst( $session['mode'] ?? '' );
		$lines[] = '';

		if ( ! empty( $skill_ratings ) ) {
			$lines[] = '--- Skill Ratings ---';
			foreach ( $skill_ratings as $r ) {
				$label   = $label_map[ $r['rating'] ] ?? $r['rating'];
				$lines[] = $r['skill_name'] . ': ' . $label;
			}
			$lines[] = '';
		}

		if ( ! empty( $transcript ) ) {
			$lines[] = '--- Chat Transcript ---';
			foreach ( $transcript as $msg ) {
				$speaker = $msg['role'] === 'bot' ? 'Interviewer' : 'Candidate';
				$lines[] = $speaker . ': ' . $msg['content'];
				$lines[] = '';
			}
		}

		return implode( "\n", $lines );
	}

	private function fetch_transcript( $session_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT role, content FROM {$wpdb->prefix}skillsaw_messages
				 WHERE session_id = %d ORDER BY created_at ASC",
				$session_id
			),
			ARRAY_A
		) ?: array();
	}

	private function split_name( $full_name ) {
		$parts = explode( ' ', trim( (string) $full_name ), 2 );
		return array( $parts[0] ?? '', $parts[1] ?? '' );
	}

	private function request( $method, $path, $body = null ) {
		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $this->api_key . ':' ),
				'Content-Type'  => 'application/json',
			),
			'timeout' => 15,
		);

		if ( $this->on_behalf_of ) {
			$args['headers']['On-Behalf-Of'] = $this->on_behalf_of;
		}

		if ( $body !== null ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API_BASE . $path, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			if ( is_array( $data ) ) {
				if ( ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
					$msgs = array_map( function( $e ) {
						return is_array( $e ) ? ( $e['message'] ?? json_encode( $e ) ) : (string) $e;
					}, $data['errors'] );
					$msg = implode( '; ', $msgs );
				} elseif ( ! empty( $data['message'] ) ) {
					$msg = $data['message'];
				} else {
					$msg = "HTTP {$code}: " . wp_remote_retrieve_body( $response );
				}
			} else {
				$msg = "Greenhouse API error (HTTP {$code})";
			}
			return new WP_Error( 'greenhouse_api_error', $msg );
		}

		return $data ?: array();
	}
}
