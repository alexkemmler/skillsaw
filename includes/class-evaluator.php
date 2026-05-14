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

		// Determine which skills the candidate explicitly tagged on at least one
		// document. Skills not tagged on any document are automatically no_response —
		// the candidate chose not to be evaluated on them.
		$tagged_skills = array();
		foreach ( $candidate_uploads as $upload ) {
			foreach ( ( $upload['skills'] ?: array() ) as $skill ) {
				$tagged_skills[ $skill ] = true;
			}
		}

		$skills_to_evaluate = array_values( array_filter(
			$skills,
			fn( $s ) => isset( $tagged_skills[ $s ] )
		) );

		$auto_no_response = array_values( array_filter(
			$skills,
			fn( $s ) => ! isset( $tagged_skills[ $s ] )
		) );

		// If nothing was tagged, fall back to evaluating all skills (e.g. no
		// uploads at all — transcript-only session).
		if ( empty( $skills_to_evaluate ) ) {
			$skills_to_evaluate = $skills;
			$auto_no_response   = array();
		}

		// Build a user message (may contain PDF document blocks).
		$content = $this->build_eval_content(
			$session,
			$skills_to_evaluate,
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

		$ratings = $this->parse_ratings( $response, $skills_to_evaluate );

		// Merge auto no_response for untagged skills.
		foreach ( $auto_no_response as $skill ) {
			$ratings[ $skill ] = 'no_response';
		}

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
	private function build_eval_content( array $session, array $skills, array $ref_docs, array $candidate_uploads, $transcript ) {
		$role_title = $session['role_title'] ?? '';
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
			if ( ! empty( $upload['skills'] ) ) {
				$skills_str = implode( ', ', $upload['skills'] );
				$label      = "Candidate's work sample: \"{$upload['filename']}\" — evaluate this document only for: {$skills_str}";
			} else {
				$label = "Candidate's work sample: \"{$upload['filename']}\" — candidate did not tag any skills for this document; do not use it as evidence for any skill";
			}
			$content[] = array( 'type' => 'text', 'text' => $label );
			$content[] = $this->file_to_content_block( $upload['file_path'], $upload['ext'] );
		}

		// Instructions text block.
		$skill_list   = implode( ', ', $skills );
		$has_ref_docs = ! empty( $ref_docs );
		$has_uploads  = ! empty( $candidate_uploads );
		$has_docs     = $has_ref_docs || $has_uploads;
		$mode         = $session['mode'] ?? 'upload';
		$is_critique  = $mode === 'critique';

		$text  = "You are a hiring evaluator assessing a candidate for the role of \"{$role_title}\".\n\n";

		$text .= "## Evaluation approach\n";
		$text .= "The candidate's submitted document is the deliverable being assessed. ";
		$text .= "It should demonstrate each skill on its own merits. ";
		$text .= "Read it as a hiring manager would read a cold submission — no benefit of the doubt for what the candidate might have meant.\n\n";

		if ( $is_critique ) {
			// Critique mode: ref doc is the subject; candidate upload is their written critique.
			$text .= "## Evaluation path: Critique\n";
			$text .= "The candidate was asked to write a critique of the reference document provided above. ";
			$text .= "Their submitted critique is the deliverable. ";
			$text .= "Assess how well the critique demonstrates each skill — through the depth, accuracy, and quality of their written analysis. ";
			$text .= "The reference document is the subject of the critique, not a quality benchmark.\n\n";
		} else {
			// Upload mode: ref doc sets the standard; candidate upload is their own work.
			$text .= "## Evaluation path: Work sample\n";
			$text .= "The candidate submitted their own work sample. ";
			$text .= "Assess how well it demonstrates each skill relative to the standard set by the reference document. ";
			if ( $has_ref_docs ) {
				$text .= "Use the reference document to calibrate what strong work looks like for this role — not as a template to match exactly.\n\n";
			} else {
				$text .= "No reference document was provided; evaluate the work sample on its absolute merits for this role.\n\n";
			}
		}

		if ( ! $has_uploads ) {
			$text .= "No document was submitted by the candidate.\n\n";
		}

		if ( $transcript ) {
			$text .= "## Chat transcript (supplementary context only)\n";
			if ( $has_uploads ) {
				$text .= "Use the transcript solely to resolve ambiguities in the submitted document — ";
				$text .= "for example, which skill a section was intended to address. ";
				$text .= "The transcript is not evidence of a skill. A skill absent from the document cannot be rescued by what the candidate said.\n\n";
			} else {
				$text .= "No document was submitted. The transcript is the only available signal. ";
				$text .= "Rate conservatively — a written deliverable is the expected submission and its absence should be reflected in the ratings.\n\n";
			}
			$text .= $transcript . "\n";
		}

		if ( ! $has_docs && ! $transcript ) {
			$text .= "Nothing was submitted. Rate all skills as no_response.\n\n";
		}

		$text .= "## How to evaluate skills\n";
		$text .= "- Evaluate each skill independently.\n";
		$text .= "- Only evaluate a skill against a document if the candidate explicitly tagged that skill on that document. ";
		$text .= "A skill may appear incidentally in a document, but if the candidate did not tag it, do not use that document as evidence for it. ";
		$text .= "The skills listed below are only those the candidate has opted into being evaluated on — all other skills have already been set to no_response.\n";
		$text .= "- If multiple documents are tagged for the same skill and suggest different ratings, apply the better rating. The strongest evidence takes precedence.\n";
		$text .= "- Ratings are intended to guide human reviewers, not to make hiring decisions. ";
		$text .= "\"Obvious success\" and \"obvious failure\" are signals to accelerate human review, not substitutes for it.\n\n";

		$text .= "## Skills to rate\n";
		$text .= "{$skill_list}\n\n";

		if ( $is_critique ) {
			$text .= "## Rating scale — Critique path\n";
			$text .= "\"Senior professional level\" means the depth, accuracy, and analytical quality of critique a senior practitioner in this field would produce.\n\n";
			$text .= "- obvious_success: The critique leaves no doubt that the candidate has a high level of ability in this skill. ";
			$text .= "The analysis is sophisticated and demonstrates the depth of understanding one would expect from someone capable of producing the reference document themselves. ";
			$text .= "The skill is compellingly evidenced through the quality of the written analysis.\n\n";
			$text .= "- provided_response: The skill is plausibly demonstrated through the critique, but there is room for doubt as to whether it reaches senior professional level. ";
			$text .= "There is evidence of competence but the volume or character of the analysis falls short of definitively reaching that level. ";
			$text .= "The critique must be at least marginally competent — not necessarily impressive, but not incompetent.\n\n";
			$text .= "- no_response: No attempt has been made to address this skill in the critique, or the critique completely lacks content that could possibly demonstrate it.\n\n";
			$text .= "- obvious_failure: An attempt was made to demonstrate this skill through the critique, but it revealed dramatic incompetence — ";
			$text .= "for example, fundamental misunderstanding of the concepts being critiqued, catastrophically poor analytical quality, glaring factual errors, ";
			$text .= "major logical contradictions, or flagrant ignorance of basic concepts in the field. ";
			$text .= "The bar is high: the critique must make it impossible to believe a professional in the field produced it. ";
			$text .= "Do not use this rating simply because the critique is weak or thin — use no_response or provided_response instead.\n\n";
		} else {
			$text .= "## Rating scale — Work sample path\n";
			$text .= "\"Senior professional level\" means the level of skill demonstrated in the reference document for this role.\n\n";
			$text .= "- obvious_success: The document leaves no doubt that the candidate has a high level of ability in this skill. ";
			$text .= "It demonstrates competence, sophistication, and experience at or beyond senior professional level. ";
			$text .= "The bottom line: could this candidate plausibly have produced the reference document themselves? If clearly yes, use this rating.\n\n";
			$text .= "- provided_response: The skill is plausibly demonstrated, but there is room for doubt as to whether it reaches the level of the reference document. ";
			$text .= "There may be evidence of senior professional level work, but the volume or character of that evidence falls short of definitively reaching or surpassing the reference. ";
			$text .= "The work must be at least marginally competent — not necessarily impressive, but not incompetent.\n\n";
			$text .= "- no_response: No documents were provided to demonstrate this skill, or documents were provided but completely lack content that could possibly demonstrate it. ";
			$text .= "Use this when no apparent attempt has been made to demonstrate the skill. ";
			$text .= "Do not use this when an attempt was made but failed — use obvious_failure for that.\n\n";
			$text .= "- obvious_failure: An attempt was made to demonstrate this skill, but it demonstrated dramatic incompetence. ";
			$text .= "This includes: stubborn misunderstanding of how the skill works, catastrophically low quality work, glaring errors, major logical contradictions, ";
			$text .= "aggressively incorrect positions, decades-out-of-date practices, or flagrant ignorance of basic concepts. ";
			$text .= "The bar is high: the document must make it impossible to believe a professional in the field produced it. ";
			$text .= "This is not simply a failure to reach a mediocre standard — it is overwhelming evidence that the candidate has no idea what they are doing with respect to this skill. ";
			$text .= "If irrelevant work was submitted for a skill, use no_response, not obvious_failure.\n\n";
		}

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
