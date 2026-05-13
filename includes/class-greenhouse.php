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

		$transcript   = $this->fetch_transcript( $session['id'] );
		$note         = $this->build_note( $session, $skill_ratings, $transcript );
		$note_payload = array( 'body' => $note, 'visibility' => 'admin_only' );

		// Post to the candidate activity feed (the supported Harvest API endpoint).
		// The note appears under the Activity Feed tab on the candidate's profile.
		$application_id = null;
		if ( $has_job && ! empty( $response['applications'] ) ) {
			foreach ( $response['applications'] as $app ) {
				if ( ! empty( $app['id'] ) ) {
					$application_id = $app['id'];
					break;
				}
			}
		}

		$note_result = $this->request( 'POST', "/candidates/{$candidate_id}/activity_feed/notes", $note_payload );

		$note_error = '';
		if ( is_wp_error( $note_result ) ) {
			$note_error = $note_result->get_error_message();
			error_log( 'Skillsaw: Greenhouse note failed — ' . $note_error );
		}

		// Attach the assessment as a PDF to the candidate.
		$pdf          = $this->build_pdf( $session, $skill_ratings, $transcript );
		$pdf_result   = $this->request( 'POST', "/candidates/{$candidate_id}/attachments", array(
			'filename'     => 'skillsaw_assessment.pdf',
			'type'         => 'other',
			'content_type' => 'application/pdf',
			'content'      => base64_encode( $pdf ),
		) );

		if ( is_wp_error( $pdf_result ) ) {
			error_log( 'Skillsaw: Greenhouse PDF attach failed — ' . $pdf_result->get_error_message() );
			if ( ! $note_error ) {
				$note_error = 'PDF attach: ' . $pdf_result->get_error_message();
			}
		}

		// Return array so the evaluator can store both IDs and any note error.
		return array(
			'candidate_id'   => $candidate_id,
			'application_id' => $application_id,
			'note_error'     => $note_error,
		);
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

	/**
	 * Generate a minimal but valid PDF containing the assessment report.
	 * Uses built-in Type1 fonts (Courier/Courier-Bold) — no external libraries.
	 */
	private function build_pdf( array $session, array $skill_ratings, array $transcript ) {
		// ── Collect lines ─────────────────────────────────────────────────────
		// Courier 10pt: each char = 6pt. Content width = 612 - 2*72 = 468pt → 78 chars/line.
		$lines = array(); // each item: [ 'text' => string, 'bold' => bool ]

		$add = function( $text, $bold = false ) use ( &$lines ) {
			if ( $text === '' ) { $lines[] = array( 'text' => '', 'bold' => false ); return; }
			foreach ( explode( "\n", wordwrap( (string) $text, 78, "\n", true ) ) as $l ) {
				$lines[] = array( 'text' => $l, 'bold' => $bold );
			}
		};

		$label_map = array(
			'obvious_success'   => 'Clearly demonstrated',
			'provided_response' => 'Demonstrated',
			'no_response'       => 'Not demonstrated',
			'obvious_failure'   => 'Below threshold',
		);

		$add( 'SKILLSAW ASSESSMENT REPORT', true );
		$add( str_repeat( '-', 40 ) );
		$add( '' );
		$add( 'Role:      ' . ( $session['role_title'] ?? '' ) );
		$add( 'Candidate: ' . ( $session['candidate_name'] ?? '' ) );
		$add( 'Email:     ' . ( $session['candidate_email'] ?? '' ) );
		$add( 'Mode:      ' . ucfirst( $session['mode'] ?? '' ) );
		$add( 'Date:      ' . ( $session['completed_at'] ?: $session['started_at'] ?? '' ) );
		$add( '' );

		if ( ! empty( $skill_ratings ) ) {
			$add( 'SKILL RATINGS', true );
			$add( str_repeat( '-', 40 ) );
			foreach ( $skill_ratings as $r ) {
				$add( ( $r['skill_name'] ?? '' ) . ': ' . ( $label_map[ $r['rating'] ] ?? $r['rating'] ) );
			}
			$add( '' );
		}

		if ( ! empty( $transcript ) ) {
			$add( 'CHAT TRANSCRIPT', true );
			$add( str_repeat( '-', 40 ) );
			foreach ( $transcript as $msg ) {
				$speaker = $msg['role'] === 'bot' ? 'Interviewer' : 'Candidate';
				$add( $speaker . ':', true );
				$add( $msg['content'] );
				$add( '' );
			}
		}

		// ── PDF layout constants ──────────────────────────────────────────────
		$pw      = 612; $ph = 792; // Letter
		$margin  = 72;  // 1 inch
		$fs      = 10;  // font size pt
		$lh      = 14;  // line height pt
		$lpp     = (int) floor( ( $ph - 2 * $margin ) / $lh ); // lines per page

		$pages   = array_chunk( $lines, $lpp ) ?: array( array() );
		$np      = count( $pages );

		// Object numbering:
		//  1 = catalog, 2 = pages,
		//  3…2+np = page dicts, 3+np…2+2np = content streams,
		//  3+2np = Courier, 4+2np = Courier-Bold
		$fn_reg  = 3 + 2 * $np;
		$fn_bold = 4 + 2 * $np;

		// ── Build content streams ─────────────────────────────────────────────
		$esc = function( $s ) {
			return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $s );
		};

		$streams = array();
		foreach ( $pages as $pg ) {
			$s        = "BT\n/F1 {$fs} Tf\n{$margin} " . ( $ph - $margin ) . " Td\n";
			$cur_bold = false;
			foreach ( $pg as $item ) {
				if ( $item['bold'] !== $cur_bold ) {
					$fn       = $item['bold'] ? '/F2' : '/F1';
					$s       .= "{$fn} {$fs} Tf\n";
					$cur_bold = $item['bold'];
				}
				$s .= '(' . $esc( $item['text'] ) . ") Tj\n0 -{$lh} Td\n";
			}
			$streams[] = $s . "ET\n";
		}

		// ── Assemble PDF objects ──────────────────────────────────────────────
		$page_refs = implode( ' ', array_map( fn( $i ) => ( 3 + $i ) . ' 0 R', range( 0, $np - 1 ) ) );

		$obj_bodies = array();
		$obj_bodies[] = "<</Type /Catalog /Pages 2 0 R>>";
		$obj_bodies[] = "<</Type /Pages /Kids [{$page_refs}] /Count {$np}>>";

		for ( $i = 0; $i < $np; $i++ ) {
			$cs = ( 3 + $np + $i );
			$obj_bodies[] = "<</Type /Page /Parent 2 0 R /MediaBox [0 0 {$pw} {$ph}] "
				. "/Contents {$cs} 0 R /Resources <</Font <</F1 {$fn_reg} 0 R /F2 {$fn_bold} 0 R>>>>>>";
		}

		foreach ( $streams as $s ) {
			$len          = strlen( $s );
			$obj_bodies[] = "<</Length {$len}>>\nstream\n{$s}endstream";
		}

		$obj_bodies[] = "<</Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding>>";
		$obj_bodies[] = "<</Type /Font /Subtype /Type1 /BaseFont /Courier-Bold /Encoding /WinAnsiEncoding>>";

		// ── Write bytes ───────────────────────────────────────────────────────
		$pdf     = "%PDF-1.4\n";
		$offsets = array();

		foreach ( $obj_bodies as $i => $body ) {
			$offsets[] = strlen( $pdf );
			$pdf      .= ( $i + 1 ) . " 0 obj\n{$body}\nendobj\n";
		}

		$xref_pos = strlen( $pdf );
		$total    = count( $obj_bodies );
		$pdf     .= "xref\n0 " . ( $total + 1 ) . "\n";
		$pdf     .= "0000000000 65535 f \n";
		foreach ( $offsets as $off ) {
			$pdf .= sprintf( "%010d 00000 n \n", $off );
		}

		$pdf .= "trailer\n<</Size " . ( $total + 1 ) . " /Root 1 0 R>>\n";
		$pdf .= "startxref\n{$xref_pos}\n%%EOF\n";

		return $pdf;
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
