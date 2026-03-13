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

	$payload = aica_get_recommendation_for_query( $message );

	return new WP_REST_Response(
		array(
			'answer'    => wp_kses_post( $payload['answer'] ),
			'book_link' => esc_url_raw( $payload['book_link'] ),
		),
		200
	);
}
