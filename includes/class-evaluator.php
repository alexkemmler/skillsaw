<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skillsaw_Evaluator {

	const VALID_RATINGS = array( 'obvious_success', 'provided_response', 'no_response', 'obvious_failure' );

	/**
	 * Evaluate a completed session by comparing candidate uploads against
	 * reference documents. Chat transcript is supplementary context only.
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

		// Build the chat transcript (supplementary context only).
		$messages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT role, content FROM {$wpdb->prefix}skillsaw_messages
				 WHERE session_id = %d ORDER BY created_at ASC",
				$session_id
			),
			ARRAY_A
		);

		$transcript = '';
		foreach ( $messages as $msg ) {
			$label       = $msg['role'] === 'bot' ? 'Interviewer' : 'Candidate';
			$transcript .= "{$label}: {$msg['content']}\n\n";
		}

		// Primary evaluation inputs: reference docs and candidate uploads.
		$ref_docs          = $this->get_reference_docs( $session['role_id'] );
		$candidate_uploads = $this->get_candidate_uploads( $session_id );

		// Build a user message (may contain PDF document blocks).
		$content = $this->build_eval_content(
			$session['role_title'],
			$skills,
			$ref_docs,
			$candidate_uploads,
			$transcript
		);

		$claude   = new Skillsaw_Claude();
		$response = $claude->chat(
			array( array( 'role' => 'user', 'content' => $content ) ),
			'',
			1024,
			'claude-sonnet-4-6'
		);

		if ( is_wp_error( $response ) ) {
			return;
		}

		$ratings = $this->parse_ratings( $response, $skills );

		if ( empty( $ratings ) ) {
			return;
		}

		// Idempotent — clear any previous run before saving.
		$wpdb->delete( "{$wpdb->prefix}skillsaw_skill_ratings", array( 'session_id' => $session_id ) );

		$rating_rows = array();
		foreach ( $ratings as $skill_name => $rating ) {
			$wpdb->insert( "{$wpdb->prefix}skillsaw_skill_ratings", array(
				'session_id' => $session_id,
				'skill_name' => $skill_name,
				'rating'     => $rating,
			) );
			$rating_rows[] = array( 'skill_name' => $skill_name, 'rating' => $rating );
		}

		$this->maybe_push_to_greenhouse( $session, $rating_rows );
	}

	/**
	 * Push the session to Greenhouse if the API key is configured.
	 * Runs silently — errors are logged but don't surface to the user.
	 */
	private function maybe_push_to_greenhouse( array $session, array $rating_rows ) {
		global $wpdb;

		if ( ! Skillsaw_Settings::get_greenhouse_key() ) {
			return;
		}

		// Don't push if already succeeded with a name present.
		if ( ! empty( $session['gh_pushed_at'] ) && ! empty( $session['candidate_name'] ) ) {
			return;
		}

		$role = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}skillsaw_roles WHERE id = %d",
				$session['role_id']
			),
			ARRAY_A
		);

		if ( ! $role ) {
			return;
		}

		$greenhouse = new Skillsaw_Greenhouse();
		$result     = $greenhouse->push_session( $session, $role, $rating_rows );

		if ( is_wp_error( $result ) ) {
			$wpdb->update(
				"{$wpdb->prefix}skillsaw_sessions",
				array( 'gh_push_error' => $result->get_error_message() ),
				array( 'id' => $session['id'] )
			);
			error_log( 'Skillsaw Greenhouse push failed: ' . $result->get_error_message() );
			return;
		}

		$note_error = ! empty( $result['note_error'] ) ? 'Note: ' . $result['note_error'] : '';

		$wpdb->update(
			"{$wpdb->prefix}skillsaw_sessions",
			array(
				'greenhouse_candidate_id' => (string) $result['candidate_id'],
				'gh_pushed_at'            => current_time( 'mysql' ),
				'gh_push_error'           => $note_error,
			),
			array( 'id' => $session['id'] )
		);
	}

	// -------------------------------------------------------------------------
	// Document helpers
	// -------------------------------------------------------------------------

	/**
	 * Fetch the original (non-critique) reference documents for a role.
	 */
	private function get_reference_docs( $role_id ) {
		global $wpdb;

		$docs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}skillsaw_documents
				 WHERE role_id = %d AND is_critique_version = 0 AND attachment_id IS NOT NULL
				 ORDER BY created_at ASC",
				$role_id
			),
			ARRAY_A
		);

		$result = array();
		foreach ( $docs as $doc ) {
			$file_path = get_attached_file( $doc['attachment_id'] );
			if ( ! $file_path || ! file_exists( $file_path ) ) {
				continue;
			}
			$result[] = array(
				'name'      => $doc['name'],
				'skills'    => json_decode( $doc['skills'], true ) ?: array(),
				'file_path' => $file_path,
				'ext'       => strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) ),
			);
		}
		return $result;
	}

	/**
	 * Fetch files uploaded by the candidate during the session.
	 */
	private function get_candidate_uploads( $session_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT attachment_id, candidate_skills FROM {$wpdb->prefix}skillsaw_messages
				 WHERE session_id = %d AND attachment_id IS NOT NULL
				 ORDER BY created_at ASC",
				$session_id
			),
			ARRAY_A
		);

		$result = array();
		foreach ( $rows as $row ) {
			$file_path = get_attached_file( $row['attachment_id'] );
			if ( ! $file_path || ! file_exists( $file_path ) ) {
				continue;
			}
			$result[] = array(
				'file_path' => $file_path,
				'filename'  => basename( $file_path ),
				'ext'       => strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) ),
				'skills'    => $row['candidate_skills'] ? json_decode( $row['candidate_skills'], true ) : array(),
			);
		}
		return $result;
	}

	// -------------------------------------------------------------------------
	// Prompt builder
	// -------------------------------------------------------------------------

	/**
	 * Build the user message content array.
	 * PDFs are passed as base64 document blocks; text files are inlined.
	 * The evaluation instructions are always the final text block.
	 */
	private function build_eval_content( $role_title, array $skills, array $ref_docs, array $candidate_uploads, $transcript ) {
		$content = array();

		// Reference documents.
		foreach ( $ref_docs as $doc ) {
			$content[] = array(
				'type' => 'text',
				'text' => "The following is an example reference document for this role: \"{$doc['name']}\"",
			);
			$content[] = $this->file_to_content_block( $doc['file_path'], $doc['ext'] );
		}

		// Candidate uploads.
		foreach ( $candidate_uploads as $upload ) {
			$skills_str = ! empty( $upload['skills'] )
				? implode( ', ', $upload['skills'] )
				: 'not specified by candidate';

			$content[] = array(
				'type' => 'text',
				'text' => "The following is the candidate's uploaded work sample: \"{$upload['filename']}\" — candidate indicated it demonstrates: {$skills_str}",
			);
			$content[] = $this->file_to_content_block( $upload['file_path'], $upload['ext'] );
		}

		// Instructions text block.
		$skill_list        = implode( ', ', $skills );
		$has_ref_docs      = ! empty( $ref_docs );
		$has_uploads       = ! empty( $candidate_uploads );
		$has_docs          = $has_ref_docs || $has_uploads;

		$text  = "You are a hiring evaluator assessing a candidate for the role of \"{$role_title}\".\n\n";

		$text .= "## Evaluation philosophy\n";
		$text .= "The candidate's submitted document(s) are the sole basis for your ratings. ";
		$text .= "A strong submission should demonstrate each skill on its own merits, without requiring any explanation from the candidate. ";
		$text .= "Read the documents exactly as a hiring manager would read a cold submission.\n\n";

		if ( $has_docs ) {
			if ( $has_ref_docs ) {
				$text .= "Reference document(s) are provided above to establish the expected standard and context for this role. ";
				$text .= "Use them to calibrate what \"good\" looks like — not as a checklist to match exactly.\n\n";
			}
			if ( $has_uploads ) {
				$text .= "The candidate's uploaded work sample(s) are provided above. Evaluate them directly.\n\n";
			}
		}

		if ( $transcript ) {
			$text .= "## Transcript (supplementary context only)\n";
			if ( $has_docs ) {
				$text .= "The chat transcript below is provided for context only. ";
				$text .= "Use it solely to clarify ambiguities in the documents — for example, understanding which skill a document was intended to address. ";
				$text .= "Do not use the transcript as evidence of a skill. If a skill is not demonstrated in the submitted documents, the transcript cannot substitute for it.\n\n";
			} else {
				$text .= "No documents were submitted. The transcript is the only available signal. ";
				$text .= "Rate conservatively — written work is the expected deliverable for this role and its absence should weigh against the candidate.\n\n";
			}
			$text .= $transcript . "\n";
		} elseif ( ! $has_docs ) {
			$text .= "No documents and no transcript are available. Rate all skills as no_response.\n\n";
		}

		$text .= "## Skills to rate\n";
		$text .= "{$skill_list}\n\n";

		$text .= "## Rating scale\n";
		$text .= "- obvious_success: The document clearly and convincingly demonstrates this skill at or above the expected level\n";
		$text .= "- provided_response: The document shows some evidence of this skill but not at a distinguishing level\n";
		$text .= "- no_response: The document contains no meaningful evidence of this skill\n";
		$text .= "- obvious_failure: The document contains work that actively contradicts or falls well below the expected standard for this skill\n\n";

		$text .= "## Output\n";
		$text .= "Respond with a JSON object only — no explanation, no markdown fences. Use the exact skill names as keys:\n";
		$text .= "{\"skill_name\": \"rating\", ...}";

		$content[] = array( 'type' => 'text', 'text' => $text );

		return $content;
	}

	/**
	 * Convert a local file into a Claude API content block.
	 * PDFs → base64 document block. Text files → inlined text block.
	 */
	private function file_to_content_block( $file_path, $ext ) {
		if ( $ext === 'pdf' ) {
			return array(
				'type'   => 'document',
				'source' => array(
					'type'       => 'base64',
					'media_type' => 'application/pdf',
					'data'       => base64_encode( file_get_contents( $file_path ) ),
				),
			);
		}

		if ( $ext === 'docx' ) {
			$text = $this->extract_docx_text( $file_path );
			return array(
				'type' => 'text',
				'text' => $text ?: '(empty document)',
			);
		}

		// Plain text / markdown.
		$text = file_get_contents( $file_path );
		return array(
			'type' => 'text',
			'text' => $text ?: '(empty file)',
		);
	}

	private function extract_docx_text( $file_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return '';
		}
		$zip = new ZipArchive();
		if ( $zip->open( $file_path ) !== true ) {
			return '';
		}
		$xml = $zip->getFromName( 'word/document.xml' );
		$zip->close();
		if ( ! $xml ) {
			return '';
		}
		$dom = new DOMDocument();
		@$dom->loadXML( $xml );
		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main' );
		$paragraphs = $xpath->query( '//w:p' );
		$lines      = array();
		foreach ( $paragraphs as $para ) {
			$runs = $xpath->query( './/w:t', $para );
			$line = '';
			foreach ( $runs as $run ) {
				$line .= $run->textContent;
			}
			$lines[] = $line;
		}
		return implode( "\n", $lines );
	}

	// -------------------------------------------------------------------------
	// Response parser
	// -------------------------------------------------------------------------

	private function parse_ratings( $response, array $skills ) {
		$json = preg_replace( '/^```(?:json)?\s*/i', '', trim( $response ) );
		$json = preg_replace( '/\s*```$/i', '', $json );

		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$ratings = array();
		foreach ( $skills as $skill ) {
			$raw              = $decoded[ $skill ] ?? 'no_response';
			$ratings[ $skill ] = in_array( $raw, self::VALID_RATINGS, true ) ? $raw : 'no_response';
		}

		return $ratings;
	}
}
