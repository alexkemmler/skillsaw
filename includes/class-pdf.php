<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal PDF generator — no external libraries.
 * Uses built-in Type1 fonts (Courier / Courier-Bold).
 * Letter page, 1-inch margins, 10pt monospace body text.
 */
class Skillsaw_PDF {

	/**
	 * Build a PDF assessment report and return the raw bytes.
	 *
	 * @param array $session       Session row (candidate_name, candidate_email, mode, etc.)
	 * @param array $skill_ratings Array of ['skill_name' => …, 'rating' => …]
	 * @param array $transcript    Array of ['role' => 'bot'|'user', 'content' => …]
	 * @return string Raw PDF bytes.
	 */
	public static function build( array $session, array $skill_ratings, array $transcript ) {
		// ── Collect lines ─────────────────────────────────────────────────
		// Courier 10pt: 6pt/char, content width 468pt → 78 chars/line.
		$lines = array();

		$add = function ( $text, $bold = false ) use ( &$lines ) {
			if ( $text === '' ) {
				$lines[] = array( 'text' => '', 'bold' => false );
				return;
			}
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
		$add( 'Date:      ' . ( $session['completed_at'] ?: ( $session['started_at'] ?? '' ) ) );
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

		// ── PDF layout ────────────────────────────────────────────────────
		$pw     = 612; $ph = 792;
		$margin = 72;
		$fs     = 10; $lh = 14;
		$lpp    = (int) floor( ( $ph - 2 * $margin ) / $lh );

		$pages = array_chunk( $lines, $lpp ) ?: array( array() );
		$np    = count( $pages );

		$fn_reg  = 3 + 2 * $np;
		$fn_bold = 4 + 2 * $np;

		// ── Content streams ───────────────────────────────────────────────
		$esc = function ( $s ) {
			return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $s );
		};

		$streams = array();
		foreach ( $pages as $pg ) {
			$s        = "BT\n/F1 {$fs} Tf\n{$margin} " . ( $ph - $margin ) . " Td\n";
			$cur_bold = false;
			foreach ( $pg as $item ) {
				if ( $item['bold'] !== $cur_bold ) {
					$s       .= ( $item['bold'] ? '/F2' : '/F1' ) . " {$fs} Tf\n";
					$cur_bold = $item['bold'];
				}
				$s .= '(' . $esc( $item['text'] ) . ") Tj\n0 -{$lh} Td\n";
			}
			$streams[] = $s . "ET\n";
		}

		// ── Object bodies ─────────────────────────────────────────────────
		$page_refs  = implode( ' ', array_map( fn( $i ) => ( 3 + $i ) . ' 0 R', range( 0, $np - 1 ) ) );
		$obj_bodies = array();

		$obj_bodies[] = "<</Type /Catalog /Pages 2 0 R>>";
		$obj_bodies[] = "<</Type /Pages /Kids [{$page_refs}] /Count {$np}>>";

		for ( $i = 0; $i < $np; $i++ ) {
			$cs           = 3 + $np + $i;
			$obj_bodies[] = "<</Type /Page /Parent 2 0 R /MediaBox [0 0 {$pw} {$ph}] "
				. "/Contents {$cs} 0 R /Resources <</Font <</F1 {$fn_reg} 0 R /F2 {$fn_bold} 0 R>>>>>>";
		}

		foreach ( $streams as $s ) {
			$obj_bodies[] = "<</Length " . strlen( $s ) . ">>\nstream\n{$s}endstream";
		}

		$obj_bodies[] = "<</Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding>>";
		$obj_bodies[] = "<</Type /Font /Subtype /Type1 /BaseFont /Courier-Bold /Encoding /WinAnsiEncoding>>";

		// ── Write ─────────────────────────────────────────────────────────
		$pdf     = "%PDF-1.4\n";
		$offsets = array();

		foreach ( $obj_bodies as $i => $body ) {
			$offsets[] = strlen( $pdf );
			$pdf      .= ( $i + 1 ) . " 0 obj\n{$body}\nendobj\n";
		}

		$xref_pos = strlen( $pdf );
		$total    = count( $obj_bodies );
		$pdf     .= "xref\n0 " . ( $total + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $offsets as $off ) {
			$pdf .= sprintf( "%010d 00000 n \n", $off );
		}

		$pdf .= "trailer\n<</Size " . ( $total + 1 ) . " /Root 1 0 R>>\nstartxref\n{$xref_pos}\n%%EOF\n";

		return $pdf;
	}
}
