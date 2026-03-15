<?php
/**
 * Content crawler/indexer.
 *
 * @package AICourseAdvisor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Crawl site and index eligible content.
 *
 * @return void
 */
function aica_crawl_and_index_site_content() {
	$post_types = get_post_types( array( 'public' => true ), 'names' );

	$args = array(
		'post_type'      => $post_types,
		'post_status'    => 'publish',
		'posts_per_page' => 200,
		'orderby'        => 'date',
		'order'          => 'DESC',
	);

	$query = new WP_Query( $args );

	if ( ! $query->have_posts() ) {
		return;
	}

	foreach ( $query->posts as $post ) {
		aica_index_single_post( $post->ID );
	}
}

/**
 * Index one post into vector table.
 *
 * @param int $post_id Post ID.
 *
 * @return void
 */
function aica_index_single_post( $post_id ) {
	$post = get_post( $post_id );

	if ( ! $post || 'publish' !== $post->post_status ) {
		return;
	}

	$post_type = get_post_type( $post_id );
	if ( ! post_type_supports( $post_type, 'editor' ) ) {
		return;
	}

	$url = get_permalink( $post_id );
	if ( aica_is_non_advisor_page_url( $url ) ) {
		return;
	}

	$title   = get_the_title( $post_id );
	$content = wp_strip_all_tags( apply_filters( 'the_content', $post->post_content ) );
	$excerpt = has_excerpt( $post_id ) ? get_the_excerpt( $post_id ) : wp_trim_words( $content, 40 );

	$meta = array(
		'post_type' => $post_type,
		'price'     => get_post_meta( $post_id, '_price', true ),
		'duration'  => get_post_meta( $post_id, 'duration', true ),
		'benefits'  => get_post_meta( $post_id, 'benefits', true ),
	);

	$checkout_link = aica_guess_checkout_link( $post_id, $url );

	$source_text = sprintf( "%s\n\n%s\n\n%s", $title, $excerpt, $content );
	$embedding   = aica_generate_embedding( $source_text );

	if ( empty( $embedding ) ) {
		return;
	}

	aica_upsert_vector_row(
		array(
			'post_id'       => $post_id,
			'title'         => $title,
			'content'       => $content,
			'excerpt'       => $excerpt,
			'meta'          => $meta,
			'embedding'     => $embedding,
			'url'           => $url,
			'checkout_link' => $checkout_link,
		)
	);
}

/**
 * Determine checkout link based on WooCommerce or page CTA.
 *
 * @param int    $post_id Post ID.
 * @param string $url     Page URL.
 *
 * @return string
 */
function aica_guess_checkout_link( $post_id, $url ) {
	if ( class_exists( 'WooCommerce' ) ) {
		$product = wc_get_product( $post_id );
		if ( $product ) {
			return esc_url_raw( add_query_arg( 'add-to-cart', $post_id, wc_get_checkout_url() ) );
		}
	}

	$cta_link = get_post_meta( $post_id, 'cta_link', true );
	if ( ! empty( $cta_link ) ) {
		$cta_link = esc_url_raw( $cta_link );
		if ( ! aica_is_non_advisor_page_url( $cta_link ) ) {
			return $cta_link;
		}
	}

	return esc_url_raw( $url );
}

/**
 * Filter out utility/account pages from advisor index and CTA links.
 *
 * @param string $url URL.
 *
 * @return bool
 */
function aica_is_non_advisor_page_url( $url ) {
	$path = wp_parse_url( $url, PHP_URL_PATH );
	$path = is_string( $path ) ? strtolower( trim( $path, '/' ) ) : '';

	$blocked_parts = array( 'my-account', 'checkout', 'order-received', 'thank-you', 'cart', 'privacy-policy', 'wp-admin' );
	foreach ( $blocked_parts as $blocked ) {
		if ( false !== strpos( $path, $blocked ) ) {
			return true;
		}
	}

	return false;
}
