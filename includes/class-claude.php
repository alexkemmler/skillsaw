<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skillsaw_Claude {

	const API_URL = 'https://api.anthropic.com/v1/messages';
	const VERSION = '2023-06-01';

	public function chat( array $messages, string $system, int $max_tokens = 1024, string $model = 'claude-sonnet-4-6' ) {
		$key = Skillsaw_Settings::get_anthropic_key();

		if ( empty( $key ) ) {
			return new WP_Error( 'no_api_key', 'Anthropic API key is not configured.' );
		}

		$response = wp_remote_post( self::API_URL, array(
			'timeout' => 90,
			'headers' => array(
				'x-api-key'         => $key,
				'anthropic-version' => self::VERSION,
				'content-type'      => 'application/json',
			),
			'body' => wp_json_encode( array(
				'model'      => $model,
				'max_tokens' => $max_tokens,
				'system'     => $system,
				'messages'   => $messages,
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$msg = $body['error']['message'] ?? 'Unknown Anthropic API error.';
			return new WP_Error( 'anthropic_error', $msg, array( 'status' => $code ) );
		}

		return $body['content'][0]['text'] ?? '';
	}

	public function complete( string $prompt, int $max_tokens = 1024, string $model = 'claude-sonnet-4-6' ) {
		return $this->chat(
			array( array( 'role' => 'user', 'content' => $prompt ) ),
			'',
			$max_tokens,
			$model
		);
	}

	public function chat_with_document( array $messages, string $system, string $file_path, string $media_type, int $max_tokens = 1024 ) {
		$ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'file_missing', 'Uploaded file not found.' );
		}

		$data = base64_encode( file_get_contents( $file_path ) );

		$doc_block = array(
			'type'   => 'document',
			'source' => array(
				'type'       => 'base64',
				'media_type' => $media_type,
				'data'       => $data,
			),
		);

		// Inject the document into the first user message.
		if ( ! empty( $messages ) && $messages[0]['role'] === 'user' ) {
			$first_content = $messages[0]['content'];
			if ( is_string( $first_content ) ) {
				$first_content = array( array( 'type' => 'text', 'text' => $first_content ) );
			}
			array_unshift( $first_content, $doc_block );
			$messages[0]['content'] = $first_content;
		}

		return $this->chat( $messages, $system, $max_tokens );
	}
}
