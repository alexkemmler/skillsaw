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
			$session,
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

		// Hold off until the candidate has identified themselves via the application form.
		// endSession() fires finalize with an empty name; the form submit provides the real one.
		if ( empty( $session['candidate_name'] ) ) {
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
	private function build_eval_content( array $session, array $skills, array $ref_docs, array $candidate_uploads, $transcript ) {
		$role_title  = $session['role_title'] ?? '';
		$mode        = $session['mode'] ?? 'upload';
		$is_critique = $mode === 'critique';
		$has_ref_docs = ! empty( $ref_docs );
		$has_uploads  = ! empty( $candidate_uploads );
		$has_docs     = $has_ref_docs || $has_uploads;
		$content      = array();

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
			if ( ! empty( $upload['skills'] ) ) {
				$skills_str = implode( ', ', $upload['skills'] );
				$label      = "Candidate's work sample: \"{$upload['filename']}\" — candidate indicated this document demonstrates: {$skills_str}";
			} else {
				$label = "Candidate's work sample: \"{$upload['filename']}\"";
			}
			$content[] = array( 'type' => 'text', 'text' => $label );
			$content[] = $this->file_to_content_block( $upload['file_path'], $upload['ext'] );
		}

		// Load the prompt template.
		$template_file = $is_critique
			? SKILLSAW_PLUGIN_DIR . 'includes/eval-prompt-critique.md'
			: SKILLSAW_PLUGIN_DIR . 'includes/eval-prompt-upload.md';
		$text = file_get_contents( $template_file );
		if ( $text === false ) {
			error_log( 'Skillsaw: missing evaluator prompt template: ' . $template_file );
			return array();
		}

		// Build skill → reference document mapping.
		$skill_doc_map_text = '';
		if ( ! empty( $skills ) && $has_ref_docs ) {
			if ( $is_critique ) {
				// For critique mode, use the critique document's skill tags.
				$critique_skills = $this->get_critique_doc_skills( $session['role_id'] );
				$skill_doc_map   = array();
				foreach ( $skills as $s ) {
					// If critique has no skill tags, all skills are in scope.
					if ( empty( $critique_skills ) || in_array( $s, $critique_skills, true ) ) {
						$skill_doc_map[ $s ] = 'the critique document';
					} else {
						$skill_doc_map[ $s ] = null; // out of scope for this critique
					}
				}
			} else {
				// For upload mode, map each skill to its assigned reference document(s).
				$skill_doc_map = array_fill_keys( $skills, array() );
				foreach ( $ref_docs as $doc ) {
					// Empty skills on a document = legacy / applies to all skills.
					$doc_skills = ( is_array( $doc['skills'] ) && ! empty( $doc['skills'] ) )
						? $doc['skills']
						: $skills;
					foreach ( $doc_skills as $s ) {
						if ( array_key_exists( $s, $skill_doc_map ) ) {
							$skill_doc_map[ $s ][] = $doc['name'];
						}
					}
				}
			}

			$map_lines = array();
			foreach ( $skill_doc_map as $skill => $docs ) {
				if ( $is_critique ) {
					if ( $docs === null ) {
						$map_lines[] = "- \"{$skill}\": not in scope for this critique document — do not rate this skill.";
					} else {
						$map_lines[] = "- \"{$skill}\": evaluate using the candidate's critique of the provided document.";
					}
				} else {
					if ( empty( $docs ) ) {
						$map_lines[] = "- \"{$skill}\": No reference document assigned — evaluate based on any available evidence.";
					} else {
						$doc_names = '"' . implode( '", "', array_map( 'esc_html', $docs ) ) . '"';
						$map_lines[] = "- \"{$skill}\": benchmark using reference document(s) {$doc_names}.";
					}
				}
			}
			$skill_doc_map_text = implode( "\n", $map_lines );
		}

		// Build context sections (structural — not editable prose).
		$context = '';
		if ( ! $has_uploads ) {
			$context .= "No document was submitted by the candidate.\n\n";
		}
		if ( $transcript ) {
			$context .= "## Chat transcript (supplementary context only)\n";
			if ( $has_uploads ) {
				$context .= "Use the transcript solely to resolve ambiguities in the submitted document — ";
				$context .= "for example, which skill a section was intended to address. ";
				$context .= "The transcript is not evidence of a skill. A skill absent from the document cannot be rescued by what the candidate said.\n\n";
			} else {
				$context .= "No document was submitted. The transcript is the only available signal. ";
				$context .= "Rate conservatively — a written deliverable is the expected submission and its absence should be reflected in the ratings.\n\n";
			}
			$context .= $transcript . "\n";
		}
		if ( ! $has_docs && ! $transcript ) {
			$context .= "Nothing was submitted. Rate all skills as no_response.\n\n";
		}

		// Calibration note varies by whether a reference doc exists (upload path only).
		$calibration = $has_ref_docs
			? 'Use the reference document to calibrate what strong work looks like for this role — not as a template to match exactly.'
			: 'No reference document was provided; evaluate the work sample on its absolute merits for this role.';

		// Substitute all placeholders.
		$text = str_replace(
			array( '{{ROLE_TITLE}}', '{{SKILL_LIST}}', '{{CONTEXT_SECTIONS}}', '{{CALIBRATION_NOTE}}', '{{SKILL_DOCUMENT_MAP}}' ),
			array( $role_title, implode( ', ', $skills ), $context, $calibration, $skill_doc_map_text ),
			$text
		);

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

	private function get_critique_doc_skills( $role_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT skills FROM {$wpdb->prefix}skillsaw_documents
				 WHERE role_id = %d AND is_critique_version = 1 AND attachment_id IS NOT NULL
				 ORDER BY created_at DESC LIMIT 1",
				$role_id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return array();
		}
		$skills = json_decode( $row['skills'], true );
		return ( is_array( $skills ) && ! empty( $skills ) ) ? $skills : array();
	}

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
