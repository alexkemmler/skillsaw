<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skillsaw_Evaluator {

	const VALID_RATINGS = array( 'obvious_success', 'provided_response', 'no_response', 'obvious_failure' );

	/**
	 * Read the full transcript for a session, ask Claude to rate each skill,
	 * and persist the results to wp_skillsaw_skill_ratings.
	 *
	 * Safe to call more than once — deletes existing ratings before inserting.
	 */
	public function evaluate_session( $session_id ) {
		global $wpdb;

		$session = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT s.*, r.title as role_title
				 FROM {$wpdb->prefix}skillsaw_sessions s
				 JOIN {$wpdb->prefix}skillsaw_roles r ON r.id = s.role_id
				 WHERE s.id = %d",
				$session_id
			),
			ARRAY_A
		);

		if ( ! $session ) {
			return;
		}

		$skills = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT name FROM {$wpdb->prefix}skillsaw_skills
				 WHERE role_id = %d ORDER BY sort_order ASC",
				$session['role_id']
			)
		);

		if ( empty( $skills ) ) {
			return;
		}

		$messages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT role, content FROM {$wpdb->prefix}skillsaw_messages
				 WHERE session_id = %d ORDER BY created_at ASC",
				$session_id
			),
			ARRAY_A
		);

		if ( empty( $messages ) ) {
			return;
		}

		$transcript = '';
		foreach ( $messages as $msg ) {
			$label       = $msg['role'] === 'bot' ? 'Interviewer' : 'Candidate';
			$transcript .= "{$label}: {$msg['content']}\n\n";
		}

		$prompt = $this->build_eval_prompt( $session['role_title'], $skills, $transcript );

		$claude   = new Skillsaw_Claude();
		$response = $claude->complete( $prompt, 512, 'claude-sonnet-4-6' );

		if ( is_wp_error( $response ) ) {
			return;
		}

		$ratings = $this->parse_ratings( $response, $skills );

		if ( empty( $ratings ) ) {
			return;
		}

		// Idempotent — clear any previous run before saving.
		$wpdb->delete( "{$wpdb->prefix}skillsaw_skill_ratings", array( 'session_id' => $session_id ) );

		foreach ( $ratings as $skill_name => $rating ) {
			$wpdb->insert( "{$wpdb->prefix}skillsaw_skill_ratings", array(
				'session_id' => $session_id,
				'skill_name' => $skill_name,
				'rating'     => $rating,
			) );
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function build_eval_prompt( $role_title, array $skills, $transcript ) {
		$skill_list = implode( ', ', $skills );

		$prompt  = "You are evaluating a candidate assessment transcript for the role of \"{$role_title}\" at Automattic.\n\n";
		$prompt .= "Skills assessed: {$skill_list}\n\n";
		$prompt .= "Rating scale — use exactly these values:\n";
		$prompt .= "- obvious_success: Candidate clearly demonstrated mastery\n";
		$prompt .= "- provided_response: Candidate engaged but didn't distinguish themselves\n";
		$prompt .= "- no_response: Skill was not meaningfully addressed\n";
		$prompt .= "- obvious_failure: Response was clearly below the expected threshold\n\n";
		$prompt .= "Transcript:\n{$transcript}\n";
		$prompt .= "Respond with a JSON object only — no explanation, no markdown fences. ";
		$prompt .= "Use the exact skill names as keys:\n{\"skill_name\": \"rating\", ...}";

		return $prompt;
	}

	private function parse_ratings( $response, array $skills ) {
		// Strip markdown code fences if Claude included them.
		$json = preg_replace( '/^```(?:json)?\s*/i', '', trim( $response ) );
		$json = preg_replace( '/\s*```$/i', '', $json );

		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$ratings = array();
		foreach ( $skills as $skill ) {
			$raw             = $decoded[ $skill ] ?? 'no_response';
			$ratings[ $skill ] = in_array( $raw, self::VALID_RATINGS, true ) ? $raw : 'no_response';
		}

		return $ratings;
	}
}
