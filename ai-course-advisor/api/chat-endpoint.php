<?php
/**
 * Chat REST endpoint.
 *
 * @package AICourseAdvisor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register route.
 *
 * @return void
 */
function aica_register_chat_route() {
	register_rest_route(
		'ai-course-advisor/v1',
		'/chat',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'aica_handle_chat_request',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'aica_register_chat_route' );

/**
 * Chat request handler.
 *
 * @param WP_REST_Request $request Request.
 *
 * @return WP_REST_Response
 */
function aica_handle_chat_request( WP_REST_Request $request ) {
	$nonce = $request->get_header( 'X-WP-Nonce' );
	if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) && ! is_user_logged_in() ) {
		return new WP_REST_Response(
			array(
				'error' => __( 'Invalid request nonce.', 'ai-course-advisor' ),
			),
			403
		);
	}

	$message = sanitize_text_field( $request->get_param( 'message' ) );
	if ( empty( $message ) ) {
		return new WP_REST_Response(
			array(
				'error' => __( 'Message is required.', 'ai-course-advisor' ),
			),
			400
		);
	}

	$history = aica_sanitize_chat_history( $request->get_param( 'history' ) );
	$payload = aica_get_recommendation_for_query( $message, $history );

	return new WP_REST_Response(
		array(
			'answer'        => wp_kses_post( $payload['answer'] ),
			'book_link'     => esc_url_raw( $payload['book_link'] ),
			'show_book_now' => ! empty( $payload['show_book_now'] ),
		),
		200
	);
}

/**
 * Sanitize chat history payload.
 *
 * @param mixed $raw_history Incoming history.
 *
 * @return array<int,array<string,string>>
 */
function aica_sanitize_chat_history( $raw_history ) {
	if ( ! is_array( $raw_history ) ) {
		return array();
	}

	$history = array();
	foreach ( array_slice( $raw_history, -8 ) as $turn ) {
		if ( ! is_array( $turn ) || empty( $turn['content'] ) ) {
			continue;
		}

		$role = ! empty( $turn['role'] ) && in_array( $turn['role'], array( 'user', 'assistant', 'bot' ), true ) ? $turn['role'] : 'user';
		$history[] = array(
			'role'    => $role,
			'content' => sanitize_text_field( $turn['content'] ),
		);
	}

	return $history;
}
