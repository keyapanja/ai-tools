<?php
/**
 * AI provider integration.
 *
 * @package AICourseAdvisor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate text embedding using configured API provider.
 * Falls back to deterministic local embedding if API is missing.
 *
 * @param string $text Input text.
 *
 * @return array<int,float>
 */
function aica_generate_embedding( $text ) {
	$settings = aica_get_settings();
	$provider = isset( $settings['api_provider'] ) ? $settings['api_provider'] : 'openai';
	$api_key  = isset( $settings['api_key'] ) ? trim( $settings['api_key'] ) : '';

	if ( empty( $api_key ) ) {
		return aica_local_embedding( $text );
	}

	if ( 'gemini' === $provider ) {
		$embedding = aica_generate_gemini_embedding( $text, $api_key );
	} else {
		$embedding = aica_generate_openai_embedding( $text, $api_key );
	}

	if ( empty( $embedding ) || ! is_array( $embedding ) ) {
		return aica_local_embedding( $text );
	}

	return $embedding;
}

/**
 * Local deterministic embedding.
 *
 * @param string $text Input text.
 *
 * @return array<int,float>
 */
function aica_local_embedding( $text ) {
	$vector = array_fill( 0, 32, 0.0 );
	$tokens = preg_split( '/\s+/', strtolower( wp_strip_all_tags( $text ) ) );

	foreach ( (array) $tokens as $token ) {
		if ( '' === $token ) {
			continue;
		}
		$hash = crc32( $token );
		$idx  = abs( $hash ) % 32;
		$vector[ $idx ] += 1.0;
	}

	$norm = 0.0;
	foreach ( $vector as $value ) {
		$norm += $value * $value;
	}
	$norm = sqrt( $norm );

	if ( $norm > 0 ) {
		foreach ( $vector as $i => $value ) {
			$vector[ $i ] = $value / $norm;
		}
	}

	return $vector;
}

/**
 * OpenAI embedding API call.
 *
 * @param string $text    Text.
 * @param string $api_key API key.
 *
 * @return array<int,float>
 */
function aica_generate_openai_embedding( $text, $api_key ) {
	$response = wp_remote_post(
		'https://api.openai.com/v1/embeddings',
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode(
				array(
					'model' => 'text-embedding-3-small',
					'input' => mb_substr( $text, 0, 4000 ),
				)
			),
			'timeout' => 20,
		)
	);

	if ( is_wp_error( $response ) ) {
		return array();
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	return isset( $body['data'][0]['embedding'] ) && is_array( $body['data'][0]['embedding'] ) ? $body['data'][0]['embedding'] : array();
}

/**
 * Gemini embedding API call.
 *
 * @param string $text    Text.
 * @param string $api_key API key.
 *
 * @return array<int,float>
 */
function aica_generate_gemini_embedding( $text, $api_key ) {
	$url      = 'https://generativelanguage.googleapis.com/v1beta/models/text-embedding-004:embedContent?key=' . rawurlencode( $api_key );
	$response = wp_remote_post(
		$url,
		array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode(
				array(
					'content' => array(
						'parts' => array(
							array( 'text' => mb_substr( $text, 0, 4000 ) ),
						),
					),
				)
			),
			'timeout' => 20,
		)
	);

	if ( is_wp_error( $response ) ) {
		return array();
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	return isset( $body['embedding']['values'] ) && is_array( $body['embedding']['values'] ) ? $body['embedding']['values'] : array();
}

/**
 * Generate final recommendation text using configured provider.
 *
 * @param string $prompt  Prompt.
 * @param array  $history Conversation history.
 *
 * @return string
 */
function aica_generate_chat_completion( $prompt, $history = array() ) {
	$settings = aica_get_settings();
	$provider = isset( $settings['api_provider'] ) ? $settings['api_provider'] : 'openai';
	$api_key  = isset( $settings['api_key'] ) ? trim( $settings['api_key'] ) : '';

	if ( empty( $api_key ) ) {
		return '';
	}

	if ( 'gemini' === $provider ) {
		return aica_generate_gemini_response( $prompt, $api_key, $history );
	}

	return aica_generate_openai_response( $prompt, $api_key, $history );
}

/**
 * OpenAI chat completion.
 *
 * @param string $prompt  Prompt.
 * @param string $api_key API key.
 * @param array  $history Chat history.
 *
 * @return string
 */
function aica_generate_openai_response( $prompt, $api_key, $history = array() ) {
	$messages = array(
		array(
			'role'    => 'system',
			'content' => 'You are an empathetic AI course advisor. Answer conversationally, ask short follow-up questions when useful, and recommend only from provided context.',
		),
	);

	foreach ( array_slice( (array) $history, -6 ) as $turn ) {
		if ( empty( $turn['role'] ) || empty( $turn['content'] ) ) {
			continue;
		}

		$role = ( 'assistant' === $turn['role'] || 'bot' === $turn['role'] ) ? 'assistant' : 'user';
		$messages[] = array(
			'role'    => $role,
			'content' => sanitize_text_field( $turn['content'] ),
		);
	}

	$messages[] = array(
		'role'    => 'user',
		'content' => $prompt,
	);

	$response = wp_remote_post(
		'https://api.openai.com/v1/chat/completions',
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode(
				array(
					'model'       => 'gpt-4o-mini',
					'temperature' => 0.4,
					'messages'    => $messages,
				)
			),
			'timeout' => 30,
		)
	);

	if ( is_wp_error( $response ) ) {
		return '';
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	return isset( $body['choices'][0]['message']['content'] ) ? wp_kses_post( $body['choices'][0]['message']['content'] ) : '';
}

/**
 * Gemini response generation.
 *
 * @param string $prompt  Prompt.
 * @param string $api_key API key.
 * @param array  $history Chat history.
 *
 * @return string
 */
function aica_generate_gemini_response( $prompt, $api_key, $history = array() ) {
	$history_text = '';
	foreach ( array_slice( (array) $history, -6 ) as $turn ) {
		if ( empty( $turn['role'] ) || empty( $turn['content'] ) ) {
			continue;
		}
		$role = ( 'assistant' === $turn['role'] || 'bot' === $turn['role'] ) ? 'Advisor' : 'User';
		$history_text .= $role . ': ' . sanitize_text_field( $turn['content'] ) . "\n";
	}

	$url      = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . rawurlencode( $api_key );
	$response = wp_remote_post(
		$url,
		array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode(
				array(
					'contents' => array(
						array(
							'parts' => array(
								array( 'text' => "Conversation so far:\n{$history_text}\n\n{$prompt}" ),
							),
						),
					),
				)
			),
			'timeout' => 30,
		)
	);

	if ( is_wp_error( $response ) ) {
		return '';
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( isset( $body['candidates'][0]['content']['parts'][0]['text'] ) ) {
		return wp_kses_post( $body['candidates'][0]['content']['parts'][0]['text'] );
	}

	return '';
}
