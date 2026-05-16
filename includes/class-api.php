<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skillsaw_API {

	const NAMESPACE = 'skillsaw/v1';

	public function register_routes() {
		// Roles
		register_rest_route( self::NAMESPACE, '/roles', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_roles' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_role' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
		) );

		register_rest_route( self::NAMESPACE, '/roles/(?P<id>\d+)/duplicate', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'duplicate_role' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( self::NAMESPACE, '/roles/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_role' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_role' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_role' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
		) );

		// Documents
		register_rest_route( self::NAMESPACE, '/roles/(?P<role_id>\d+)/documents', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'upload_document' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( self::NAMESPACE, '/roles/(?P<role_id>\d+)/documents/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_document' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_document' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
		) );

		register_rest_route( self::NAMESPACE, '/roles/(?P<role_id>\d+)/documents/(?P<id>\d+)/generate-critique', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'generate_critique' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/roles/(?P<role_id>\d+)/documents/(?P<id>\d+)/suggest-mistakes', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'suggest_mistakes' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/roles/(?P<role_id>\d+)/documents/(?P<id>\d+)/critique-upload', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'upload_critique_doc' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// Settings
		register_rest_route( self::NAMESPACE, '/settings', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_settings' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_settings' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			),
		) );

		// Candidates
		register_rest_route( self::NAMESPACE, '/candidates', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_candidates' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( self::NAMESPACE, '/candidates/(?P<id>\d+)/archive', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'archive_candidate' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/candidates/(?P<id>\d+)/unarchive', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'unarchive_candidate' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/candidates/(?P<id>\d+)/transcript', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_transcript' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( self::NAMESPACE, '/candidates/(?P<id>\d+)/pdf', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_candidate_pdf' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		// Public session routes
		register_rest_route( self::NAMESPACE, '/sessions/start', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'start_session' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NAMESPACE, '/sessions/(?P<token>[a-zA-Z0-9\-]+)/message', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'send_message' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NAMESPACE, '/sessions/(?P<token>[a-zA-Z0-9\-]+)/upload', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'upload_file' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NAMESPACE, '/sessions/(?P<token>[a-zA-Z0-9\-]+)/messages/(?P<message_id>\d+)/skills', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'save_message_skills' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NAMESPACE, '/sessions/(?P<token>[a-zA-Z0-9\-]+)/finalize', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'finalize_session' ),
			'permission_callback' => '__return_true',
		) );
	}

	// -------------------------------------------------------------------------
	// Roles
	// -------------------------------------------------------------------------

	public function get_roles( $request ) {
		global $wpdb;

		$roles = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}skillsaw_roles ORDER BY created_at DESC",
			ARRAY_A
		);

		foreach ( $roles as &$role ) {
			$role['skills']    = $this->get_role_skills( $role['id'] );
			$role['documents'] = $this->get_role_documents( $role['id'] );
			$role['applicants'] = $this->get_role_applicant_count( $role['id'] );
		}

		return rest_ensure_response( $roles );
	}

	public function get_role( $request ) {
		global $wpdb;

		$role = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}skillsaw_roles WHERE id = %d", $request['id'] ),
			ARRAY_A
		);

		if ( ! $role ) {
			return new WP_Error( 'not_found', 'Role not found.', array( 'status' => 404 ) );
		}

		$role['skills']    = $this->get_role_skills( $role['id'] );
		$role['documents'] = $this->get_role_documents( $role['id'] );
		$role['applicants'] = $this->get_role_applicant_count( $role['id'] );

		return rest_ensure_response( $role );
	}

	public function create_role( $request ) {
		global $wpdb;

		$data = array(
			'title'        => sanitize_text_field( $request->get_param( 'title' ) ),
			'division'     => sanitize_text_field( $request->get_param( 'division' ) ?? '' ),
			'team'         => sanitize_text_field( $request->get_param( 'team' ) ?? '' ),
			'status'       => 'draft',
			'instructions' => sanitize_textarea_field( $request->get_param( 'instructions' ) ?? '' ),
		);

		if ( empty( $data['title'] ) ) {
			return new WP_Error( 'missing_title', 'Title is required.', array( 'status' => 400 ) );
		}

		$wpdb->insert( "{$wpdb->prefix}skillsaw_roles", $data );
		$role_id = $wpdb->insert_id;

		$skills = $request->get_param( 'skills' ) ?? array();
		$this->sync_skills( $role_id, $skills );

		$get_request = new WP_REST_Request( 'GET' );
		$get_request->set_url_params( array( 'id' => $role_id ) );
		return $this->get_role( $get_request );
	}

	public function update_role( $request ) {
		global $wpdb;

		$role = $wpdb->get_row(
			$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}skillsaw_roles WHERE id = %d", $request['id'] )
		);

		if ( ! $role ) {
			return new WP_Error( 'not_found', 'Role not found.', array( 'status' => 404 ) );
		}

		$data = array();

		if ( null !== $request->get_param( 'title' ) ) {
			$data['title'] = sanitize_text_field( $request->get_param( 'title' ) );
		}
		if ( null !== $request->get_param( 'division' ) ) {
			$data['division'] = sanitize_text_field( $request->get_param( 'division' ) );
		}
		if ( null !== $request->get_param( 'team' ) ) {
			$data['team'] = sanitize_text_field( $request->get_param( 'team' ) );
		}
		if ( null !== $request->get_param( 'status' ) ) {
			$allowed = array( 'active', 'inactive', 'draft' );
			$status  = $request->get_param( 'status' );
			if ( in_array( $status, $allowed, true ) ) {
				$data['status'] = $status;
			}
		}
		if ( null !== $request->get_param( 'instructions' ) ) {
			$data['instructions'] = sanitize_textarea_field( $request->get_param( 'instructions' ) );
		}
		if ( null !== $request->get_param( 'candidate_note' ) ) {
			$data['candidate_note'] = sanitize_textarea_field( $request->get_param( 'candidate_note' ) );
		}
		if ( null !== $request->get_param( 'greenhouse_job_id' ) ) {
			$data['greenhouse_job_id'] = sanitize_text_field( $request->get_param( 'greenhouse_job_id' ) );
		}

		if ( ! empty( $data ) ) {
			$wpdb->update( "{$wpdb->prefix}skillsaw_roles", $data, array( 'id' => $request['id'] ) );
		}

		if ( null !== $request->get_param( 'skills' ) ) {
			$this->sync_skills( $request['id'], $request->get_param( 'skills' ) );
		}

		return $this->get_role( $request );
	}

	public function duplicate_role( $request ) {
		global $wpdb;

		$original = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}skillsaw_roles WHERE id = %d", $request['id'] ),
			ARRAY_A
		);

		if ( ! $original ) {
			return new WP_Error( 'not_found', 'Role not found.', array( 'status' => 404 ) );
		}

		// Insert new role — same content, draft status, "Copy of" prefix.
		$wpdb->insert( "{$wpdb->prefix}skillsaw_roles", array(
			'title'             => 'Copy of ' . $original['title'],
			'division'          => $original['division'],
			'team'              => $original['team'],
			'status'            => 'draft',
			'instructions'      => $original['instructions'],
			'candidate_note'    => $original['candidate_note'],
			'greenhouse_job_id' => $original['greenhouse_job_id'],
		) );
		$new_role_id = $wpdb->insert_id;

		// Copy skills.
		$skills = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT name, sort_order FROM {$wpdb->prefix}skillsaw_skills WHERE role_id = %d ORDER BY sort_order ASC",
				$original['id']
			),
			ARRAY_A
		);
		foreach ( $skills as $skill ) {
			$wpdb->insert( "{$wpdb->prefix}skillsaw_skills", array(
				'role_id'    => $new_role_id,
				'name'       => $skill['name'],
				'sort_order' => $skill['sort_order'],
			) );
		}

		// Copy documents. Track old-id → new-id so critique parent links stay intact.
		$docs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}skillsaw_documents WHERE role_id = %d ORDER BY id ASC",
				$original['id']
			),
			ARRAY_A
		);

		$id_map = array(); // old doc id => new doc id

		// First pass: non-critique docs.
		foreach ( $docs as $doc ) {
			if ( $doc['is_critique_version'] ) {
				continue;
			}
			$wpdb->insert( "{$wpdb->prefix}skillsaw_documents", array(
				'role_id'            => $new_role_id,
				'attachment_id'      => $doc['attachment_id'],
				'name'               => $doc['name'],
				'type'               => $doc['type'],
				'skills'             => $doc['skills'],
				'is_critique_version' => 0,
				'parent_document_id' => null,
				'critique_text'      => null,
			) );
			$id_map[ $doc['id'] ] = $wpdb->insert_id;
		}

		// Second pass: critique versions with remapped parent_document_id.
		foreach ( $docs as $doc ) {
			if ( ! $doc['is_critique_version'] ) {
				continue;
			}
			$new_parent = $id_map[ $doc['parent_document_id'] ] ?? null;
			$wpdb->insert( "{$wpdb->prefix}skillsaw_documents", array(
				'role_id'             => $new_role_id,
				'attachment_id'       => $doc['attachment_id'],
				'name'                => $doc['name'],
				'type'                => $doc['type'],
				'skills'              => $doc['skills'],
				'is_critique_version' => 1,
				'parent_document_id'  => $new_parent,
				'critique_text'       => $doc['critique_text'],
			) );
		}

		$get_request = new WP_REST_Request( 'GET' );
		$get_request->set_url_params( array( 'id' => $new_role_id ) );
		return $this->get_role( $get_request );
	}

	public function delete_role( $request ) {
		global $wpdb;
		$role_id = (int) $request['id'];

		// Delete media attachments for all documents belonging to this role.
		$attachment_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT attachment_id FROM {$wpdb->prefix}skillsaw_documents
			 WHERE role_id = %d AND attachment_id IS NOT NULL",
			$role_id
		) );
		foreach ( $attachment_ids as $att_id ) {
			wp_delete_attachment( (int) $att_id, true );
		}

		// Cascade-delete sessions, messages, and ratings.
		$session_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}skillsaw_sessions WHERE role_id = %d",
			$role_id
		) );
		foreach ( $session_ids as $sid ) {
			$wpdb->delete( "{$wpdb->prefix}skillsaw_messages", array( 'session_id' => (int) $sid ) );
			$wpdb->delete( "{$wpdb->prefix}skillsaw_skill_ratings", array( 'session_id' => (int) $sid ) );
		}

		$wpdb->delete( "{$wpdb->prefix}skillsaw_sessions", array( 'role_id' => $role_id ) );
		$wpdb->delete( "{$wpdb->prefix}skillsaw_documents", array( 'role_id' => $role_id ) );
		$wpdb->delete( "{$wpdb->prefix}skillsaw_skills", array( 'role_id' => $role_id ) );
		$wpdb->delete( "{$wpdb->prefix}skillsaw_roles", array( 'id' => $role_id ) );

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	// -------------------------------------------------------------------------
	// Documents
	// -------------------------------------------------------------------------

	public function upload_document( $request ) {
		global $wpdb;

		if ( empty( $_FILES['file'] ) ) {
			return new WP_Error( 'no_file', 'No file uploaded.', array( 'status' => 400 ) );
		}

		$allowed_exts = array( 'pdf', 'docx', 'doc', 'txt', 'md' );
		$ext_check    = strtolower( pathinfo( $_FILES['file']['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext_check, $allowed_exts, true ) ) {
			return new WP_Error( 'invalid_type', 'Unsupported file type.', array( 'status' => 400 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_handle_upload( 'file', 0 );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$file     = get_attached_file( $attachment_id );
		$filename = basename( $file );
		$ext      = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$skills   = $request->get_param( 'skills' ) ?? array();

		$wpdb->insert( "{$wpdb->prefix}skillsaw_documents", array(
			'role_id'       => $request['role_id'],
			'attachment_id' => $attachment_id,
			'name'          => sanitize_text_field( $request->get_param( 'name' ) ?: $filename ),
			'type'          => $ext,
			'skills'        => wp_json_encode( $skills ),
		) );

		return rest_ensure_response( array(
			'id'            => $wpdb->insert_id,
			'attachment_id' => $attachment_id,
			'name'          => sanitize_text_field( $request->get_param( 'name' ) ?: $filename ),
			'type'          => $ext,
			'skills'        => $skills,
			'is_critique_version' => false,
			'critique'      => null,
		) );
	}

	public function delete_document( $request ) {
		global $wpdb;

		$doc = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}skillsaw_documents WHERE id = %d AND role_id = %d",
			$request['id'],
			$request['role_id']
		) );

		if ( ! $doc ) {
			return new WP_Error( 'not_found', 'Document not found.', array( 'status' => 404 ) );
		}

		if ( $doc->attachment_id ) {
			wp_delete_attachment( $doc->attachment_id, true );
		}

		// Also delete any critique versions linked to this document.
		$wpdb->delete( "{$wpdb->prefix}skillsaw_documents", array( 'parent_document_id' => $request['id'] ) );
		$wpdb->delete( "{$wpdb->prefix}skillsaw_documents", array( 'id' => $request['id'] ) );

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	public function update_document( $request ) {
		global $wpdb;

		$doc = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}skillsaw_documents WHERE id = %d AND role_id = %d",
			$request['id'],
			$request['role_id']
		) );

		if ( ! $doc ) {
			return new WP_Error( 'not_found', 'Document not found.', array( 'status' => 404 ) );
		}

		$skills = $request->get_param( 'skills' );
		if ( null !== $skills ) {
			$wpdb->update(
				"{$wpdb->prefix}skillsaw_documents",
				array( 'skills' => wp_json_encode( array_values( (array) $skills ) ) ),
				array( 'id' => (int) $request['id'] )
			);
		}

		$updated = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}skillsaw_documents WHERE id = %d",
			$request['id']
		), ARRAY_A );
		$updated['skills'] = json_decode( $updated['skills'], true ) ?: array();

		return rest_ensure_response( $updated );
	}

	public function generate_critique( $request ) {
		global $wpdb;

		$doc = $wpdb->get_row( $wpdb->prepare(
			"SELECT d.*, r.title as role_title FROM {$wpdb->prefix}skillsaw_documents d
			 JOIN {$wpdb->prefix}skillsaw_roles r ON r.id = d.role_id
			 WHERE d.id = %d AND d.role_id = %d",
			$request['id'],
			$request['role_id']
		) );

		if ( ! $doc ) {
			return new WP_Error( 'not_found', 'Document not found.', array( 'status' => 404 ) );
		}

		require_once SKILLSAW_PLUGIN_DIR . 'includes/class-claude.php';

		$file_path = get_attached_file( $doc->attachment_id );

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error( 'unreadable', 'Could not find document file.', array( 'status' => 500 ) );
		}

		$ext       = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		$skills    = json_decode( $doc->skills, true ) ?: array();
		$skill_str = implode( ', ', $skills );

		$instruction = "You are preparing a document to be shared with job candidates as a skills evaluation exercise. Candidates applying for the role of \"{$doc->role_title}\" will be asked to critique this document. The skills being evaluated are: {$skill_str}.\n\nStep 1 — Sanitize: Replace all proprietary or identifying information with realistic but fictional equivalents:\n- Company and product names → generic or fictional alternatives (e.g. \"Acme Corp\", \"Project Phoenix\")\n- Real people's names → fictional names\n- Specific internal metrics, revenue figures, and user counts → plausible but fictional numbers\n- Specific dates → approximate or generic timeframes (e.g. \"Q3 2024\" or \"last year\")\n- Internal codenames, team names, or org-specific jargon → neutral equivalents\n\nStep 2 — Introduce weaknesses: Weave in several realistic but non-obvious weaknesses that a strong candidate should be able to identify — strategic blind spots, flawed assumptions, missed considerations, or subtle errors relevant to the skills being evaluated. The weaknesses should feel like genuine oversights, not obvious mistakes.\n\nPreserve the document's overall structure and tone. Return only the revised document text, nothing else.";

		$claude = new Skillsaw_Claude();

		if ( $ext === 'pdf' ) {
			$user_content = array(
				array(
					'type'   => 'document',
					'source' => array(
						'type'       => 'base64',
						'media_type' => 'application/pdf',
						'data'       => base64_encode( file_get_contents( $file_path ) ),
					),
				),
				array( 'type' => 'text', 'text' => $instruction ),
			);
			$response = $claude->chat(
				array( array( 'role' => 'user', 'content' => $user_content ) ),
				'',
				4096,
				'claude-opus-4-7'
			);
		} elseif ( $ext === 'docx' ) {
			$text = $this->extract_docx_text( $file_path );
			if ( ! $text ) {
				return new WP_Error( 'unreadable', 'Could not extract text from the DOCX file.', array( 'status' => 500 ) );
			}
			$response = $claude->complete( "Document:\n\n{$text}\n\n---\n\n{$instruction}", 4096, 'claude-opus-4-7' );
		} elseif ( in_array( $ext, array( 'txt', 'md' ), true ) ) {
			$text     = file_get_contents( $file_path );
			$response = $claude->complete( "Document:\n\n{$text}\n\n---\n\n{$instruction}", 4096, 'claude-opus-4-7' );
		} else {
			return new WP_Error( 'unsupported_type', 'Only PDF, DOCX, TXT, and MD documents can be used to generate a critique.', array( 'status' => 400 ) );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Delete any existing critique version for this document.
		$wpdb->delete( "{$wpdb->prefix}skillsaw_documents", array( 'parent_document_id' => $doc->id ) );

		$wpdb->insert( "{$wpdb->prefix}skillsaw_documents", array(
			'role_id'             => $doc->role_id,
			'name'                => pathinfo( $doc->name, PATHINFO_FILENAME ) . ' — critique.' . pathinfo( $doc->name, PATHINFO_EXTENSION ),
			'type'                => $doc->type,
			'skills'              => $doc->skills,
			'is_critique_version' => 1,
			'parent_document_id'  => $doc->id,
			'critique_text'       => $response,
		) );

		$critique_id = $wpdb->insert_id;

		return rest_ensure_response( array(
			'id'                  => $critique_id,
			'name'                => pathinfo( $doc->name, PATHINFO_FILENAME ) . ' — critique.' . pathinfo( $doc->name, PATHINFO_EXTENSION ),
			'type'                => $doc->type,
			'skills'              => $skills,
			'is_critique_version' => true,
			'parent_document_id'  => $doc->id,
			'critique_text'       => $response,
		) );
	}

	public function suggest_mistakes( $request ) {
		global $wpdb;

		$doc = $wpdb->get_row( $wpdb->prepare(
			"SELECT d.*, r.title as role_title FROM {$wpdb->prefix}skillsaw_documents d
			 JOIN {$wpdb->prefix}skillsaw_roles r ON r.id = d.role_id
			 WHERE d.id = %d AND d.role_id = %d",
			$request['id'],
			$request['role_id']
		) );

		if ( ! $doc ) {
			return new WP_Error( 'not_found', 'Document not found.', array( 'status' => 404 ) );
		}

		$file_path = get_attached_file( $doc->attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error( 'unreadable', 'Could not find document file.', array( 'status' => 500 ) );
		}

		$ext       = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		$skills    = json_decode( $doc->skills, true ) ?: array();
		$skill_str = implode( ', ', $skills );

		$instruction = "You are helping a recruiter prepare a document for a skills assessment exercise. Candidates applying for the role of \"{$doc->role_title}\" will be asked to critique this document. The skills being assessed are: {$skill_str}.\n\nAnalyze this document and suggest 6–8 specific, realistic weaknesses that a recruiter could manually introduce into the document. These weaknesses should:\n- Be non-obvious enough that only a skilled professional would notice them\n- Be directly relevant to the skills being assessed\n- Feel like genuine oversights or misjudgements, not obvious errors\n- Vary in type: strategic blind spots, flawed assumptions, missed considerations, subtle factual errors, questionable decisions\n\nFor each suggestion provide:\n1. Location — where in the document to make the change (section name, chart, paragraph, etc.)\n2. Current content — briefly quote or describe what is there now\n3. Suggested change — exactly what to replace it with or add\n4. Why a strong candidate would catch it — what the correct approach is and why this flaw matters\n\nFormat as a numbered list. Be specific enough that someone without deep domain expertise could make the edit themselves.";

		require_once SKILLSAW_PLUGIN_DIR . 'includes/class-claude.php';
		$claude = new Skillsaw_Claude();

		if ( $ext === 'pdf' ) {
			$user_content = array(
				array(
					'type'   => 'document',
					'source' => array(
						'type'       => 'base64',
						'media_type' => 'application/pdf',
						'data'       => base64_encode( file_get_contents( $file_path ) ),
					),
				),
				array( 'type' => 'text', 'text' => $instruction ),
			);
			$response = $claude->chat(
				array( array( 'role' => 'user', 'content' => $user_content ) ),
				'',
				4096,
				'claude-opus-4-7'
			);
		} elseif ( $ext === 'docx' ) {
			$text = $this->extract_docx_text( $file_path );
			if ( ! $text ) {
				return new WP_Error( 'unreadable', 'Could not extract text from the DOCX file.', array( 'status' => 500 ) );
			}
			$response = $claude->complete( "Document:\n\n{$text}\n\n---\n\n{$instruction}", 4096, 'claude-opus-4-7' );
		} elseif ( in_array( $ext, array( 'txt', 'md' ), true ) ) {
			$text     = file_get_contents( $file_path );
			$response = $claude->complete( "Document:\n\n{$text}\n\n---\n\n{$instruction}", 4096, 'claude-opus-4-7' );
		} else {
			return new WP_Error( 'unsupported_type', 'Only PDF, DOCX, TXT, and MD documents are supported.', array( 'status' => 400 ) );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return rest_ensure_response( array( 'suggestions' => $response ) );
	}

	public function upload_critique_doc( $request ) {
		global $wpdb;

		$doc = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}skillsaw_documents WHERE id = %d AND role_id = %d AND is_critique_version = 0",
			$request['id'],
			$request['role_id']
		) );

		if ( ! $doc ) {
			return new WP_Error( 'not_found', 'Document not found.', array( 'status' => 404 ) );
		}

		if ( empty( $_FILES['file'] ) ) {
			return new WP_Error( 'no_file', 'No file uploaded.', array( 'status' => 400 ) );
		}

		$allowed_exts = array( 'pdf', 'docx', 'doc', 'txt', 'md' );
		$ext_check    = strtolower( pathinfo( $_FILES['file']['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext_check, $allowed_exts, true ) ) {
			return new WP_Error( 'invalid_type', 'Unsupported file type.', array( 'status' => 400 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_handle_upload( 'file', 0 );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$file_path = get_attached_file( $attachment_id );
		$filename  = basename( $file_path );
		$ext       = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		$text = null;
		if ( $ext === 'docx' ) {
			$text = $this->extract_docx_text( $file_path ) ?: null;
		} elseif ( in_array( $ext, array( 'txt', 'md' ), true ) ) {
			$text = file_get_contents( $file_path ) ?: null;
		}

		// Delete any existing critique version (and its attachment) for this parent document.
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}skillsaw_documents WHERE parent_document_id = %d AND is_critique_version = 1",
			$doc->id
		) );
		if ( $existing ) {
			if ( $existing->attachment_id ) {
				wp_delete_attachment( $existing->attachment_id, true );
			}
			$wpdb->delete( "{$wpdb->prefix}skillsaw_documents", array( 'id' => $existing->id ) );
		}

		$name = pathinfo( $doc->name, PATHINFO_FILENAME ) . ' — critique.' . $ext;

		// Inherit skill assignments from parent document.
		$parent_skills = json_decode( $doc->skills, true );
		$parent_skills = is_array( $parent_skills ) ? $parent_skills : array();

		$wpdb->insert( "{$wpdb->prefix}skillsaw_documents", array(
			'role_id'             => $doc->role_id,
			'attachment_id'       => $attachment_id,
			'name'                => $name,
			'type'                => $ext,
			'skills'              => wp_json_encode( $parent_skills ),
			'is_critique_version' => 1,
			'parent_document_id'  => $doc->id,
			'critique_text'       => $text,
		) );

		$critique_id = $wpdb->insert_id;
		$file_url    = wp_get_attachment_url( $attachment_id );

		return rest_ensure_response( array(
			'id'                  => $critique_id,
			'name'                => $name,
			'type'                => $ext,
			'skills'              => json_decode( $doc->skills, true ) ?: array(),
			'is_critique_version' => true,
			'url'                 => $file_url,
			'critique_text'       => $text,
		) );
	}

	// -------------------------------------------------------------------------
	// Candidates
	// -------------------------------------------------------------------------

	public function get_candidates( $request ) {
		global $wpdb;

		$where  = array( 's.status != %s' );
		$values = array( 'in_progress' );

		if ( $request->get_param( 'role_id' ) ) {
			$where[]  = 'role_id = %d';
			$values[] = $request->get_param( 'role_id' );
		}

		if ( $request->get_param( 'q' ) ) {
			$where[]  = 'candidate_name LIKE %s';
			$values[] = '%' . $wpdb->esc_like( $request->get_param( 'q' ) ) . '%';
		}

		$where_sql = implode( ' AND ', $where );

		$sessions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, r.title as role_title, r.team, r.division
				 FROM {$wpdb->prefix}skillsaw_sessions s
				 LEFT JOIN {$wpdb->prefix}skillsaw_roles r ON r.id = s.role_id
				 WHERE {$where_sql}
				 ORDER BY s.started_at DESC",
				...$values
			),
			ARRAY_A
		);

		foreach ( $sessions as &$session ) {
			$session['skill_ratings'] = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT skill_name, rating FROM {$wpdb->prefix}skillsaw_skill_ratings WHERE session_id = %d",
					$session['id']
				),
				ARRAY_A
			);
			$session['file_count'] = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}skillsaw_messages WHERE session_id = %d AND attachment_id IS NOT NULL",
					$session['id']
				)
			);
		}

		return rest_ensure_response( $sessions );
	}

	public function get_candidate_pdf( $request ) {
		global $wpdb;

		$session = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT s.*, r.title as role_title FROM {$wpdb->prefix}skillsaw_sessions s
				 LEFT JOIN {$wpdb->prefix}skillsaw_roles r ON r.id = s.role_id
				 WHERE s.id = %d",
				$request['id']
			),
			ARRAY_A
		);

		if ( ! $session ) {
			return new WP_Error( 'not_found', 'Session not found.', array( 'status' => 404 ) );
		}

		$skill_ratings = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT skill_name, rating FROM {$wpdb->prefix}skillsaw_skill_ratings WHERE session_id = %d",
				$request['id']
			),
			ARRAY_A
		);

		$transcript = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT role, content FROM {$wpdb->prefix}skillsaw_messages WHERE session_id = %d ORDER BY created_at ASC",
				$request['id']
			),
			ARRAY_A
		);

		require_once SKILLSAW_PLUGIN_DIR . 'includes/class-pdf.php';
		$pdf      = Skillsaw_PDF::build( $session, $skill_ratings ?: array(), $transcript ?: array() );
		$filename = sanitize_file_name( ( $session['candidate_name'] ?: 'assessment' ) . '_skillsaw.pdf' );

		return rest_ensure_response( array(
			'filename' => $filename,
			'data'     => base64_encode( $pdf ),
		) );
	}

	public function archive_candidate( $request ) {
		global $wpdb;
		$updated = $wpdb->update(
			"{$wpdb->prefix}skillsaw_sessions",
			array( 'archived_at' => current_time( 'mysql' ) ),
			array( 'id' => (int) $request['id'] )
		);
		if ( $updated === false ) {
			return new WP_Error( 'db_error', 'Could not archive candidate.', array( 'status' => 500 ) );
		}
		return rest_ensure_response( array( 'archived' => true ) );
	}

	public function unarchive_candidate( $request ) {
		global $wpdb;
		$updated = $wpdb->update(
			"{$wpdb->prefix}skillsaw_sessions",
			array( 'archived_at' => null ),
			array( 'id' => (int) $request['id'] )
		);
		if ( $updated === false ) {
			return new WP_Error( 'db_error', 'Could not restore candidate.', array( 'status' => 500 ) );
		}
		return rest_ensure_response( array( 'archived' => false ) );
	}

	public function get_transcript( $request ) {
		global $wpdb;

		$session = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT s.*, r.title as role_title FROM {$wpdb->prefix}skillsaw_sessions s
				 LEFT JOIN {$wpdb->prefix}skillsaw_roles r ON r.id = s.role_id
				 WHERE s.id = %d",
				$request['id']
			),
			ARRAY_A
		);

		if ( ! $session ) {
			return new WP_Error( 'not_found', 'Session not found.', array( 'status' => 404 ) );
		}

		$messages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}skillsaw_messages WHERE session_id = %d ORDER BY created_at ASC",
				$request['id']
			),
			ARRAY_A
		);

		foreach ( $messages as &$msg ) {
			if ( $msg['candidate_skills'] ) {
				$msg['candidate_skills'] = json_decode( $msg['candidate_skills'], true ) ?: array();
			} else {
				$msg['candidate_skills'] = array();
			}

			if ( $msg['attachment_id'] ) {
				$file_path = get_attached_file( $msg['attachment_id'] );
				$msg['file'] = array(
					'name' => basename( $file_path ),
					'url'  => wp_get_attachment_url( $msg['attachment_id'] ),
					'size' => $file_path && file_exists( $file_path )
						? size_format( filesize( $file_path ) )
						: '',
				);
			}
		}

		$session['messages']      = $messages;
		$session['skill_ratings'] = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}skillsaw_skill_ratings WHERE session_id = %d",
				$request['id']
			),
			ARRAY_A
		);

		return rest_ensure_response( $session );
	}

	// -------------------------------------------------------------------------
	// Settings
	// -------------------------------------------------------------------------

	public function get_settings( $request ) {
		return rest_ensure_response( array(
			'anthropic_key'           => Skillsaw_Settings::get_anthropic_key() ? '••••••••' : '',
			'greenhouse_key'          => Skillsaw_Settings::get_greenhouse_key() ? '••••••••' : '',
			'greenhouse_board_token'  => Skillsaw_Settings::get_greenhouse_board_token(),
		) );
	}

	public function update_settings( $request ) {
		$fields = array( 'anthropic_key', 'greenhouse_key', 'greenhouse_board_token' );
		foreach ( $fields as $field ) {
			if ( null !== $request->get_param( $field ) ) {
				Skillsaw_Settings::set( $field, sanitize_text_field( $request->get_param( $field ) ) );
			}
		}
		return $this->get_settings( $request );
	}

	// -------------------------------------------------------------------------
	// Public session routes
	// -------------------------------------------------------------------------

	public function start_session( $request ) {
		global $wpdb;

		$role_id = intval( $request->get_param( 'role_id' ) );
		$mode    = $request->get_param( 'mode' ) ?: 'upload';

		if ( ! in_array( $mode, array( 'upload', 'critique' ), true ) ) {
			$mode = 'upload';
		}

		$role = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}skillsaw_roles WHERE id = %d AND status = 'active'",
				$role_id
			),
			ARRAY_A
		);

		if ( ! $role ) {
			return new WP_Error( 'role_not_found', 'Role not found or not active.', array( 'status' => 404 ) );
		}

		$sessions = new Skillsaw_Sessions();
		$ip    = $this->get_client_ip();
		$token = $sessions->create_session( $role_id, $mode, $ip );

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		// Store the chosen critique doc against this session (if provided).
		$critique_doc_id = 0;
		if ( $mode === 'critique' ) {
			$critique_doc_id = intval( $request->get_param( 'critique_doc_id' ) );
			if ( $critique_doc_id ) {
				$wpdb->update(
					"{$wpdb->prefix}skillsaw_sessions",
					array( 'critique_doc_id' => $critique_doc_id ),
					array( 'session_token' => $token )
				);
			}
		}

		$response = array(
			'session_token' => $token,
			'mode'          => $mode,
		);

		if ( $mode === 'critique' ) {
			$critique = $critique_doc_id
				? $this->get_critique_by_id( $critique_doc_id, $role_id )
				: $this->get_critique_for_role( $role_id );
			if ( ! $critique ) {
				return new WP_Error( 'no_critique', 'No critique document available for this role.', array( 'status' => 404 ) );
			}
			$response['critique_text']     = $critique['text'];
			$response['critique_doc_name'] = $critique['doc_name'];
			$response['critique_doc_url']  = $critique['url'];
			$response['critique_doc_ext']  = $critique['ext'];
			$response['candidate_note']    = $role['candidate_note'] ?? '';
		}

		return rest_ensure_response( $response );
	}

	public function send_message( $request ) {
		require_once SKILLSAW_PLUGIN_DIR . 'includes/class-sessions.php';
		$sessions = new Skillsaw_Sessions();

		$session = $sessions->get_session_by_token( $request['token'] );

		if ( ! $session ) {
			return new WP_Error( 'not_found', 'Session not found.', array( 'status' => 404 ) );
		}

		if ( $session['status'] === 'expired' ) {
			return new WP_Error( 'session_expired', 'This session has expired.', array( 'status' => 410 ) );
		}

		if ( $session['status'] !== 'in_progress' ) {
			return new WP_Error( 'session_closed', 'Session is no longer active.', array( 'status' => 400 ) );
		}

		// Lazy expiry: mark as expired if session is more than 4 hours old.
		if ( time() - strtotime( $session['started_at'] ) > 4 * HOUR_IN_SECONDS ) {
			$sessions->expire_session( $session['id'] );
			return new WP_Error( 'session_expired', 'This session has expired.', array( 'status' => 410 ) );
		}

		$content = sanitize_textarea_field( $request->get_param( 'message' ) ?? '' );
		if ( empty( $content ) ) {
			return new WP_Error( 'empty_message', 'Message cannot be empty.', array( 'status' => 400 ) );
		}
		if ( mb_strlen( $content ) > 4000 ) {
			return new WP_Error( 'message_too_long', 'Message exceeds the 4000 character limit.', array( 'status' => 400 ) );
		}
		if ( $sessions->get_message_count( $session['id'] ) >= 50 ) {
			return new WP_Error( 'session_limit', 'This session has reached the maximum number of messages.', array( 'status' => 400 ) );
		}

		$sessions->save_message( $session['id'], 'user', $content );

		$skills        = $this->get_role_skills( $session['role_id'] );
		$critique_text = null;
		if ( $session['mode'] === 'critique' ) {
			if ( ! empty( $session['critique_doc_id'] ) ) {
				$c = $this->get_critique_by_id( (int) $session['critique_doc_id'], $session['role_id'] );
				$critique_text = $c ? $c['text'] : null;
			} else {
				// Legacy fallback: use most recent critique doc.
				$critique_text = $this->get_critique_text_for_role( $session['role_id'] );
			}
		}
		$system        = $this->build_system_prompt( $session, $skills, $session['mode'], $critique_text );
		$messages      = $sessions->get_messages_for_claude( $session['id'] );

		// Replace filename placeholders with actual file content for Claude.
		foreach ( $messages as &$msg ) {
			if ( ! empty( $msg['_attachment_id'] ) ) {
				$msg['content'] = $this->enrich_with_file_content( $msg['_attachment_id'] );
				unset( $msg['_attachment_id'] );
			}
		}
		unset( $msg );

		$claude = new Skillsaw_Claude();
		$reply  = $claude->chat( $messages, $system, 1024 );

		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		$sessions->save_message( $session['id'], 'bot', $reply );

		return rest_ensure_response( array( 'message' => $reply ) );
	}

	public function upload_file( $request ) {
		require_once SKILLSAW_PLUGIN_DIR . 'includes/class-sessions.php';
		$sessions = new Skillsaw_Sessions();

		$session = $sessions->get_session_by_token( $request['token'] );

		if ( ! $session ) {
			return new WP_Error( 'not_found', 'Session not found.', array( 'status' => 404 ) );
		}

		if ( $session['status'] === 'expired' ) {
			return new WP_Error( 'session_expired', 'This session has expired.', array( 'status' => 410 ) );
		}

		if ( $session['status'] !== 'in_progress' ) {
			return new WP_Error( 'session_closed', 'Session is no longer active.', array( 'status' => 400 ) );
		}

		if ( time() - strtotime( $session['started_at'] ) > 4 * HOUR_IN_SECONDS ) {
			$sessions->expire_session( $session['id'] );
			return new WP_Error( 'session_expired', 'This session has expired.', array( 'status' => 410 ) );
		}

		if ( empty( $_FILES['file'] ) ) {
			return new WP_Error( 'no_file', 'No file uploaded.', array( 'status' => 400 ) );
		}

		if ( $_FILES['file']['size'] > 25 * 1024 * 1024 ) {
			return new WP_Error( 'file_too_large', 'File exceeds the 25 MB limit.', array( 'status' => 400 ) );
		}

		$allowed_exts = array( 'pdf', 'docx', 'txt', 'md' );
		$ext          = strtolower( pathinfo( $_FILES['file']['name'], PATHINFO_EXTENSION ) );
		if ( $ext === 'doc' ) {
			return new WP_Error( 'invalid_file_type', 'Please save your document as DOCX or PDF and re-upload.', array( 'status' => 400 ) );
		}
		if ( ! in_array( $ext, $allowed_exts, true ) ) {
			return new WP_Error( 'invalid_file_type', 'Only PDF, DOCX, TXT, and MD files are accepted.', array( 'status' => 400 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_handle_upload( 'file', 0 );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$file_path = get_attached_file( $attachment_id );
		$filename  = basename( $file_path );
		$ext       = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		$upload_message_id = $sessions->save_message( $session['id'], 'user', "Uploaded file: {$filename}", $attachment_id );

		return rest_ensure_response( array(
			'filename'   => $filename,
			'message_id' => $upload_message_id,
		) );
	}

	public function save_message_skills( $request ) {
		global $wpdb;

		$sessions = new Skillsaw_Sessions();
		$session  = $sessions->get_session_by_token( $request['token'] );

		if ( ! $session ) {
			return new WP_Error( 'not_found', 'Session not found.', array( 'status' => 404 ) );
		}

		$message_id = intval( $request['message_id'] );

		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}skillsaw_messages WHERE id = %d AND session_id = %d",
			$message_id,
			$session['id']
		) );

		if ( ! $exists ) {
			return new WP_Error( 'not_found', 'Message not found.', array( 'status' => 404 ) );
		}

		$skills = array_map( 'sanitize_text_field', (array) ( $request->get_param( 'skills' ) ?? array() ) );
		$sessions->save_upload_skills( $message_id, $skills );

		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function finalize_session( $request ) {
		require_once SKILLSAW_PLUGIN_DIR . 'includes/class-sessions.php';
		$sessions = new Skillsaw_Sessions();

		$session = $sessions->get_session_by_token( $request['token'] );

		if ( ! $session ) {
			return new WP_Error( 'not_found', 'Session not found.', array( 'status' => 404 ) );
		}

		$name  = sanitize_text_field( $request->get_param( 'name' ) ?? '' );
		$email = sanitize_email( $request->get_param( 'email' ) ?? '' );

		if ( $session['status'] === 'complete' ) {
			// Session already complete (endSession fired before form submit).
			// Update name/email and re-trigger evaluation so Greenhouse push
			// uses the actual name rather than the empty string from endSession.
			if ( $name || $email ) {
				$sessions->update_identity( $session['id'], $name, $email );
				wp_schedule_single_event( time(), 'skillsaw_evaluate_session', array( $session['id'] ) );
			}
			return rest_ensure_response( array( 'ok' => true ) );
		}

		$sessions->complete_session( $session['id'], $name, $email );

		wp_schedule_single_event( time(), 'skillsaw_evaluate_session', array( $session['id'] ) );

		return rest_ensure_response( array( 'ok' => true ) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	public function admin_permission() {
		return current_user_can( 'manage_options' );
	}

	private function get_client_ip() {
		// On VIP (and most proxied hosts) the real IP is in X-Forwarded-For.
		// Take only the first entry to avoid header spoofing.
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
			$ip = trim( $forwarded[0] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
		return $_SERVER['REMOTE_ADDR'] ?? '';
	}

	private function get_role_skills( $role_id ) {
		global $wpdb;
		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT name FROM {$wpdb->prefix}skillsaw_skills WHERE role_id = %d ORDER BY sort_order ASC",
				$role_id
			)
		);
	}

	private function get_role_documents( $role_id ) {
		global $wpdb;
		$docs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}skillsaw_documents WHERE role_id = %d AND is_critique_version = 0 ORDER BY created_at ASC",
				$role_id
			),
			ARRAY_A
		);

		foreach ( $docs as &$doc ) {
			$doc['skills'] = json_decode( $doc['skills'], true ) ?: array();
			$doc['url']    = $doc['attachment_id'] ? wp_get_attachment_url( $doc['attachment_id'] ) : null;

			$critique = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}skillsaw_documents WHERE parent_document_id = %d AND is_critique_version = 1",
					$doc['id']
				),
				ARRAY_A
			);
			if ( $critique ) {
				$critique['skills']        = json_decode( $critique['skills'], true ) ?: array();
				$critique['critique_text'] = $critique['critique_text'] ?? null;
				$critique['url']           = $critique['attachment_id'] ? wp_get_attachment_url( $critique['attachment_id'] ) : null;
			}
			$doc['critique'] = $critique;
		}

		return $docs;
	}

	private function get_role_applicant_count( $role_id ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}skillsaw_sessions WHERE role_id = %d AND status != 'in_progress'",
				$role_id
			)
		);
	}

	private function sync_skills( $role_id, $skills ) {
		global $wpdb;
		$wpdb->delete( "{$wpdb->prefix}skillsaw_skills", array( 'role_id' => $role_id ) );
		foreach ( array_values( $skills ) as $i => $name ) {
			$wpdb->insert( "{$wpdb->prefix}skillsaw_skills", array(
				'role_id'    => $role_id,
				'name'       => sanitize_text_field( $name ),
				'sort_order' => $i,
			) );
		}
	}

	private function enrich_with_file_content( $attachment_id ) {
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return 'Uploaded a file (contents unavailable).';
		}
		$ext      = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		$filename = basename( $file_path );

		if ( $ext === 'pdf' ) {
			return array(
				array(
					'type'   => 'document',
					'source' => array(
						'type'       => 'base64',
						'media_type' => 'application/pdf',
						'data'       => base64_encode( file_get_contents( $file_path ) ),
					),
				),
				array(
					'type' => 'text',
					'text' => "I've uploaded my work sample ({$filename}). Please review it alongside the skills I tagged.",
				),
			);
		}

		if ( $ext === 'docx' ) {
			$text = $this->extract_docx_text( $file_path );
			return $text
				? "I've uploaded my work sample ({$filename}):\n\n{$text}"
				: "I've uploaded a work sample ({$filename}).";
		}

		$text = in_array( $ext, array( 'txt', 'md' ), true ) ? file_get_contents( $file_path ) : '';
		if ( $text ) {
			return "I've uploaded my work sample ({$filename}):\n\n{$text}";
		}
		return "I've uploaded a work sample ({$filename}).";
	}

	private function read_file_as_text( $path ) {
		if ( ! $path || ! file_exists( $path ) ) {
			return '';
		}
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, array( 'txt', 'md', 'csv' ), true ) ) {
			return file_get_contents( $path );
		}
		// For PDF/DOCX, return base64 — Claude receives it as a document block.
		return base64_encode( file_get_contents( $path ) );
	}

	private function get_critique_by_id( $doc_id, $role_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT c.critique_text, c.attachment_id, p.name as parent_name
				 FROM {$wpdb->prefix}skillsaw_documents c
				 LEFT JOIN {$wpdb->prefix}skillsaw_documents p ON p.id = c.parent_document_id
				 WHERE c.id = %d AND c.role_id = %d AND c.is_critique_version = 1",
				$doc_id,
				$role_id
			)
		);
		if ( ! $row ) {
			return null;
		}
		if ( ! $row->attachment_id && ! $row->critique_text ) {
			return null;
		}
		$url = $row->attachment_id ? wp_get_attachment_url( $row->attachment_id ) : null;
		$ext = null;
		if ( $row->attachment_id ) {
			$file = get_attached_file( $row->attachment_id );
			$ext  = $file ? strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) : null;
		}
		return array(
			'text'     => $row->critique_text,
			'doc_name' => $row->parent_name ?: 'Document for review',
			'url'      => $url,
			'ext'      => $ext,
		);
	}

	private function get_critique_for_role( $role_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT c.critique_text, c.attachment_id, p.name as parent_name
				 FROM {$wpdb->prefix}skillsaw_documents c
				 LEFT JOIN {$wpdb->prefix}skillsaw_documents p ON p.id = c.parent_document_id
				 WHERE c.role_id = %d AND c.is_critique_version = 1
				 ORDER BY c.created_at DESC LIMIT 1",
				$role_id
			)
		);
		if ( ! $row ) {
			return null;
		}
		if ( ! $row->attachment_id && ! $row->critique_text ) {
			return null;
		}
		$url = $row->attachment_id ? wp_get_attachment_url( $row->attachment_id ) : null;
		$ext = null;
		if ( $row->attachment_id ) {
			$file = get_attached_file( $row->attachment_id );
			$ext  = $file ? strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) : null;
		}
		return array(
			'text'     => $row->critique_text,
			'doc_name' => $row->parent_name ?: 'Document for review',
			'url'      => $url,
			'ext'      => $ext,
		);
	}

	private function get_critique_text_for_role( $role_id ) {
		$critique = $this->get_critique_for_role( $role_id );
		return $critique ? $critique['text'] : null;
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

	private function build_system_prompt( $session, $skills, $mode, $critique_text = null ) {
		$role_title   = $session['role_title'] ?? '';
		$instructions = $session['instructions'] ?? '';
		$skill_list   = implode( ', ', $skills );

		$prompt  = "You are facilitating a skills assessment for a candidate applying for the role of \"{$role_title}\".\n\n";
		$prompt .= "The skills being assessed are: {$skill_list}.\n\n";

		if ( ! empty( $instructions ) ) {
			$prompt .= "Recruiter instructions:\n{$instructions}\n\n";
		}

		if ( $mode === 'upload' ) {
			$prompt .= "Format: Work sample review.\n\n";
			$prompt .= "This interaction should not resemble a job interview in any way. ";
			$prompt .= "Your only goal is to receive the candidate's document and gather the minimum context needed to evaluate it — ";
			$prompt .= "specifically its intent and scope.\n\n";
			$prompt .= "If the document's intent and scope are clear from the outset, no follow-up questions are needed. A simple, warm acknowledgement (e.g. \"Thank you!\") is sufficient.\n\n";
			$prompt .= "If the intent or scope is unclear, ask the candidate to provide context: what the document is, where, when, and why they produced it, and how it should be evaluated. ";
			$prompt .= "Do not ask substantive questions about the document's content or probe the candidate's reasoning. ";
			$prompt .= "Do not ask anything that would feel like an interview question.\n\n";
			$prompt .= "When the session is complete, wrap up warmly and let the candidate know.\n\n";
		} elseif ( $mode === 'critique' ) {
			$prompt .= "Format: Document critique.\n\n";
			if ( $critique_text ) {
				$prompt .= "The document the candidate is critiquing:\n\n---\n{$critique_text}\n---\n\n";
			}
			$prompt .= "This interaction should be candidate-led and should not feel like a job interview. ";
			$prompt .= "Give the candidate time to read the document and formulate their own questions and opinions before engaging.\n\n";
			$prompt .= "You may ask clarifying questions to help the candidate expand on what they have said, but never ask leading questions or offer suggestions. ";
			$prompt .= "A good clarifying question lets the candidate expand without giving them the answer — for example, if the candidate says \"I think the timing for this campaign was off\", ";
			$prompt .= "an appropriate follow-up is \"Specifically how was the timing off?\" ";
			$prompt .= "A bad clarifying question injects your content into their response — for example, \"Good point — do you think it would have been better to run this campaign during the US holiday season?\" would be inappropriate.\n\n";
			$prompt .= "Be absolutely neutral in tone and content. Never offer your own opinion on the document. Never volunteer information about it.\n\n";
			$prompt .= "If the candidate asks a direct question about the document — for example, \"Why did they choose LinkedIn ads over Google or Meta?\" — do not answer it. ";
			$prompt .= "Instead, turn it back to them: ask why they think that choice might have been made, or what possible reasons there might be. ";
			$prompt .= "Let their responses reveal their expertise.\n\n";
			$prompt .= "Do not guide the candidate's critique except to gently encourage coverage of areas they have not yet addressed if the session is drawing to a close. ";
			$prompt .= "When the session is complete, wrap up warmly and let the candidate know.\n\n";
		}

		$prompt .= "General guidelines:\n";
		$prompt .= "- Keep responses concise and conversational (2–4 sentences unless the candidate asks for more)\n";
		$prompt .= "- Be warm and professional\n";
		$prompt .= "- Do not reveal the scoring criteria or mention that you are rating them";

		return $prompt;
	}
}
