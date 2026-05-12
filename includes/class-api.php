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
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => array( $this, 'delete_document' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );

		register_rest_route( self::NAMESPACE, '/roles/(?P<role_id>\d+)/documents/(?P<id>\d+)/generate-critique', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'generate_critique' ),
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

		register_rest_route( self::NAMESPACE, '/candidates/(?P<id>\d+)/transcript', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_transcript' ),
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

		if ( ! empty( $data ) ) {
			$wpdb->update( "{$wpdb->prefix}skillsaw_roles", $data, array( 'id' => $request['id'] ) );
		}

		if ( null !== $request->get_param( 'skills' ) ) {
			$this->sync_skills( $request['id'], $request->get_param( 'skills' ) );
		}

		return $this->get_role( $request );
	}

	public function delete_role( $request ) {
		global $wpdb;

		$wpdb->delete( "{$wpdb->prefix}skillsaw_roles", array( 'id' => $request['id'] ) );
		$wpdb->delete( "{$wpdb->prefix}skillsaw_skills", array( 'role_id' => $request['id'] ) );
		$wpdb->delete( "{$wpdb->prefix}skillsaw_documents", array( 'role_id' => $request['id'] ) );

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

		$file_path   = get_attached_file( $doc->attachment_id );
		$file_content = $this->read_file_as_text( $file_path );

		if ( ! $file_content ) {
			return new WP_Error( 'unreadable', 'Could not read document contents.', array( 'status' => 500 ) );
		}

		$skills    = json_decode( $doc->skills, true ) ?: array();
		$skill_str = implode( ', ', $skills );

		$prompt = "You are helping design a skills evaluation for a hiring process. Below is a document that will be given to candidates applying for the role of \"{$doc->role_title}\". The skills being evaluated are: {$skill_str}.\n\nPlease create a revised version of this document that contains several realistic but non-obvious weaknesses — strategic blind spots, flawed assumptions, missed considerations, or subtle errors — that a strong candidate for this role should be able to identify and critique. The weaknesses should feel like genuine oversights, not obvious mistakes. Return only the revised document text, nothing else.\n\n---\n\n{$file_content}";

		$claude   = new Skillsaw_Claude();
		$response = $claude->complete( $prompt, 4096, 'claude-opus-4-7' );

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
			if ( $msg['attachment_id'] ) {
				$msg['file'] = array(
					'name' => basename( get_attached_file( $msg['attachment_id'] ) ),
					'url'  => wp_get_attachment_url( $msg['attachment_id'] ),
					'size' => size_format( filesize( get_attached_file( $msg['attachment_id'] ) ) ),
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
	// Public session routes (stubs — implemented in Phase 3)
	// -------------------------------------------------------------------------

	public function start_session( $request ) {
		return new WP_Error( 'not_implemented', 'Coming in Phase 3.', array( 'status' => 501 ) );
	}

	public function send_message( $request ) {
		return new WP_Error( 'not_implemented', 'Coming in Phase 3.', array( 'status' => 501 ) );
	}

	public function upload_file( $request ) {
		return new WP_Error( 'not_implemented', 'Coming in Phase 3.', array( 'status' => 501 ) );
	}

	public function finalize_session( $request ) {
		return new WP_Error( 'not_implemented', 'Coming in Phase 3.', array( 'status' => 501 ) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	public function admin_permission() {
		return current_user_can( 'manage_options' );
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
			$critique      = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}skillsaw_documents WHERE parent_document_id = %d AND is_critique_version = 1",
					$doc['id']
				),
				ARRAY_A
			);
			if ( $critique ) {
				$critique['skills'] = json_decode( $critique['skills'], true ) ?: array();
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
}
