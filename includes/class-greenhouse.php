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

		list( $first, $last ) = $this->split_name( $session['candidate_name'] );

		$candidate_payload = array(
			'first_name'      => $first,
			'last_name'       => $last,
			'email_addresses' => array(
				array( 'value' => $session['candidate_email'], 'type' => 'personal' ),
			),
		);

		if ( ! empty( $role['greenhouse_job_id'] ) ) {
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

		$note = $this->build_note( $session, $skill_ratings );
		$this->request( 'POST', "/candidates/{$candidate_id}/activity_feed/notes", array(
			'body'       => $note,
			'visibility' => 'private',
		) );

		return $candidate_id;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function build_note( array $session, array $skill_ratings ) {
		$label_map = array(
			'obvious_success'   => 'Clearly demonstrated ✓✓',
			'provided_response' => 'Demonstrated ✓',
			'no_response'       => 'Not demonstrated —',
			'obvious_failure'   => 'Below threshold ✗',
		);

		$lines   = array();
		$lines[] = 'Skillsaw Assessment — ' . ( $session['role_title'] ?? '' );
		$lines[] = 'Mode: ' . ucfirst( $session['mode'] ?? '' );
		$lines[] = '';

		if ( ! empty( $skill_ratings ) ) {
			$lines[] = 'Skill Ratings:';
			foreach ( $skill_ratings as $r ) {
				$label   = $label_map[ $r['rating'] ] ?? $r['rating'];
				$lines[] = '  ' . $r['skill_name'] . ': ' . $label;
			}
		}

		return implode( "\n", $lines );
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
			$msg = ( is_array( $data ) && isset( $data['message'] ) ) ? $data['message'] : "Greenhouse API error (HTTP {$code})";
			return new WP_Error( 'greenhouse_api_error', $msg );
		}

		return $data ?: array();
	}
}
