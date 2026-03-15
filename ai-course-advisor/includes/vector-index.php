<?php
/**
 * Vector index operations.
 *
 * @package AICourseAdvisor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Save or update vector row.
 *
 * @param array $data Row data.
 *
 * @return void
 */
function aica_upsert_vector_row( $data ) {
	global $wpdb;

	$table = $wpdb->prefix . 'ai_course_vectors';
	$row   = array(
		'post_id'         => intval( $data['post_id'] ),
		'title'           => sanitize_text_field( $data['title'] ),
		'content'         => wp_strip_all_tags( $data['content'] ),
		'excerpt'         => wp_strip_all_tags( $data['excerpt'] ),
		'meta'            => wp_json_encode( isset( $data['meta'] ) ? $data['meta'] : array() ),
		'embedding'       => wp_json_encode( $data['embedding'] ),
		'url'             => esc_url_raw( $data['url'] ),
		'checkout_link'   => esc_url_raw( $data['checkout_link'] ),
		'manual_priority' => isset( $data['manual_priority'] ) ? intval( $data['manual_priority'] ) : 0,
		'updated_at'      => current_time( 'mysql' ),
	);

	$existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE post_id = %d", $row['post_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	if ( $existing_id ) {
		$wpdb->update(
			$table,
			$row,
			array( 'id' => intval( $existing_id ) ),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);
		return;
	}

	$wpdb->insert(
		$table,
		$row,
		array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
	);
}

/**
 * Fetch all indexed rows.
 *
 * @return array<int,array<string,mixed>>
 */
function aica_get_all_vectors() {
	global $wpdb;

	$table = $wpdb->prefix . 'ai_course_vectors';
	$rows  = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	if ( ! is_array( $rows ) ) {
		return array();
	}

	foreach ( $rows as &$row ) {
		$row['embedding'] = json_decode( $row['embedding'], true );
		$row['meta']      = json_decode( $row['meta'], true );
	}

	return $rows;
}

/**
 * Simple cosine similarity.
 *
 * @param array $a Vector A.
 * @param array $b Vector B.
 *
 * @return float
 */
function aica_cosine_similarity( $a, $b ) {
	if ( empty( $a ) || empty( $b ) || count( $a ) !== count( $b ) ) {
		return 0.0;
	}

	$dot = 0.0;
	$na  = 0.0;
	$nb  = 0.0;

	foreach ( $a as $i => $value ) {
		$av = floatval( $value );
		$bv = floatval( $b[ $i ] );
		$dot += $av * $bv;
		$na  += $av * $av;
		$nb  += $bv * $bv;
	}

	if ( 0.0 === $na || 0.0 === $nb ) {
		return 0.0;
	}

	return $dot / ( sqrt( $na ) * sqrt( $nb ) );
}
