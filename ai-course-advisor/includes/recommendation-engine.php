<?php
/**
 * Recommendation engine.
 *
 * @package AICourseAdvisor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Process user query and return answer payload.
 *
 * @param string $message User message.
 * @param array  $history Prior chat history.
 *
 * @return array<string,mixed>
 */
function aica_get_recommendation_for_query( $message, $history = array() ) {
	$user_message = sanitize_text_field( $message );
	$query_vec    = aica_generate_embedding( $user_message );
	$rows         = aica_get_all_vectors();
	$scored       = array();

	foreach ( $rows as $row ) {
		$similarity   = aica_cosine_similarity( $query_vec, (array) $row['embedding'] );
		$manual_boost = intval( $row['manual_priority'] ) / 100;
		$row['score'] = $similarity + $manual_boost;
		$row['raw_similarity'] = $similarity;
		$scored[] = $row;
	}

	usort(
		$scored,
		function ( $a, $b ) {
			if ( $a['score'] === $b['score'] ) {
				return 0;
			}
			return ( $a['score'] < $b['score'] ) ? 1 : -1;
		}
	);

	$top_matches = array_slice( $scored, 0, 3 );
	$best_match  = isset( $top_matches[0] ) ? $top_matches[0] : array();
	$has_confident_match = ! empty( $best_match ) && floatval( $best_match['raw_similarity'] ) >= 0.23;

	if ( ! $has_confident_match ) {
		$answer = __( 'Thanks for sharing. To guide you better, could you tell me one more thing: are you looking for stress relief, confidence growth, relationship healing, or career focus?', 'ai-course-advisor' );
		aica_log_chat_interaction( $user_message, $answer, array() );
		return array(
			'answer'        => $answer,
			'matches'       => array(),
			'book_link'     => '',
			'show_book_now' => false,
			'user_query'    => $user_message,
		);
	}

	$prompt = aica_build_recommendation_prompt( $user_message, $history, $top_matches );
	$answer = aica_generate_chat_completion( $prompt, $history );

	if ( empty( $answer ) || aica_is_placeholder_ai_message( $answer ) ) {
		$answer = aica_build_fallback_course_response( $best_match );
	}

	$book_link = aica_get_valid_book_link( $best_match );
	$show_book = ! empty( $book_link );

	$matched_ids = wp_list_pluck( $top_matches, 'post_id' );
	aica_log_chat_interaction( $user_message, $answer, $matched_ids );

	return array(
		'answer'        => $answer,
		'matches'       => $top_matches,
		'book_link'     => $book_link,
		'show_book_now' => $show_book,
		'user_query'    => $user_message,
	);
}

/**
 * Build LLM prompt.
 *
 * @param string $user_query User query.
 * @param array  $history    Prior messages.
 * @param array  $matches    Top course matches.
 *
 * @return string
 */
function aica_build_recommendation_prompt( $user_query, $history, $matches ) {
	$settings = aica_get_settings();
	$context  = '';

	foreach ( $matches as $item ) {
		$meta = isset( $item['meta'] ) && is_array( $item['meta'] ) ? $item['meta'] : array();
		$context .= "Course Title: {$item['title']}\n";
		$context .= 'Description: ' . wp_trim_words( $item['content'], 90 ) . "\n";
		$context .= 'Benefits: ' . ( ! empty( $meta['benefits'] ) ? sanitize_text_field( $meta['benefits'] ) : 'Not specified' ) . "\n";
		$context .= 'Duration: ' . ( ! empty( $meta['duration'] ) ? sanitize_text_field( $meta['duration'] ) : 'Not specified' ) . "\n";
		$context .= 'Price: ' . ( ! empty( $meta['price'] ) ? sanitize_text_field( $meta['price'] ) : 'Not specified' ) . "\n";
		$context .= "Course URL: {$item['url']}\n";
		$context .= 'Relevance Score: ' . round( floatval( $item['raw_similarity'] ), 3 ) . "\n\n";
	}

	$history_text = '';
	foreach ( array_slice( (array) $history, -6 ) as $turn ) {
		if ( empty( $turn['role'] ) || empty( $turn['content'] ) ) {
			continue;
		}
		$role = ( 'user' === $turn['role'] ) ? 'User' : 'Advisor';
		$history_text .= $role . ': ' . sanitize_text_field( $turn['content'] ) . "\n";
	}

	if ( ! empty( $settings['manual_context'] ) ) {
		$context .= 'Business Notes: ' . sanitize_textarea_field( $settings['manual_context'] ) . "\n\n";
	}

	return "You are a warm, conversational AI course advisor. Speak like a real coach, not a robot.\n\nRules:\n1) Recommend only from provided courses.\n2) If information is missing, say that honestly.\n3) Give: why this course fits, key outcomes, duration, price.\n4) Ask 1 short follow-up question when user intent can be refined.\n5) Do NOT invent links.\n\nRecent Conversation:\n{$history_text}\nUser Goal: {$user_query}\n\nCourse Context:\n{$context}";
}

/**
 * Determine whether provider answer is placeholder/error text.
 *
 * @param string $answer AI answer.
 *
 * @return bool
 */
function aica_is_placeholder_ai_message( $answer ) {
	$text = strtolower( wp_strip_all_tags( $answer ) );
	$needles = array(
		'no response received from ai provider',
		'unable to reach ai provider',
		'please add an api key',
		'sorry, i could not generate',
	);

	foreach ( $needles as $needle ) {
		if ( false !== strpos( $text, $needle ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Build a deterministic fallback response from top course.
 *
 * @param array $course Best-matched course row.
 *
 * @return string
 */
function aica_build_fallback_course_response( $course ) {
	$meta = isset( $course['meta'] ) && is_array( $course['meta'] ) ? $course['meta'] : array();
	$title = ! empty( $course['title'] ) ? sanitize_text_field( $course['title'] ) : __( 'a relevant course', 'ai-course-advisor' );
	$description = ! empty( $course['excerpt'] ) ? wp_strip_all_tags( $course['excerpt'] ) : wp_trim_words( wp_strip_all_tags( $course['content'] ), 35 );
	$benefits = ! empty( $meta['benefits'] ) ? sanitize_text_field( $meta['benefits'] ) : __( 'Practical guidance and structured support.', 'ai-course-advisor' );
	$duration = ! empty( $meta['duration'] ) ? sanitize_text_field( $meta['duration'] ) : __( 'Duration details available on course page', 'ai-course-advisor' );
	$price = ! empty( $meta['price'] ) ? sanitize_text_field( $meta['price'] ) : __( 'Price available on course page', 'ai-course-advisor' );

	return sprintf(
		/* translators: 1: course title, 2: description, 3: benefits, 4: duration, 5: price */
		__( "Based on what you shared, I recommend: %1$s.\n\nWhy it fits: %2$s\n\nWhat you'll get: %3$s\nDuration: %4$s\nPrice: %5$s\n\nWould you like me to compare this with one more option before you decide?", 'ai-course-advisor' ),
		$title,
		$description,
		$benefits,
		$duration,
		$price
	);
}

/**
 * Return safe booking link for match only if clearly related.
 *
 * @param array $course Candidate course row.
 *
 * @return string
 */
function aica_get_valid_book_link( $course ) {
	if ( empty( $course ) || empty( $course['post_id'] ) ) {
		return '';
	}

	$post_id = intval( $course['post_id'] );
	$page_url = ! empty( $course['url'] ) ? esc_url_raw( $course['url'] ) : '';
	$checkout_url = ! empty( $course['checkout_link'] ) ? esc_url_raw( $course['checkout_link'] ) : '';

	if ( $checkout_url && aica_is_safe_related_url( $checkout_url, $post_id, $page_url ) ) {
		return $checkout_url;
	}

	if ( $page_url && aica_is_safe_related_url( $page_url, $post_id, $page_url ) ) {
		return $page_url;
	}

	return '';
}

/**
 * Validate whether URL is safe and course-related.
 *
 * @param string $url      Candidate URL.
 * @param int    $post_id  Related post ID.
 * @param string $page_url Canonical course page URL.
 *
 * @return bool
 */
function aica_is_safe_related_url( $url, $post_id, $page_url ) {
	$target = wp_parse_url( $url );
	$home   = wp_parse_url( home_url() );

	if ( empty( $target['host'] ) || empty( $home['host'] ) || strtolower( $target['host'] ) !== strtolower( $home['host'] ) ) {
		return false;
	}

	$path = isset( $target['path'] ) ? strtolower( trim( $target['path'], '/' ) ) : '';
	$blocked_parts = array( 'my-account', 'thank-you', 'order-received', 'cart', 'wp-admin' );
	foreach ( $blocked_parts as $blocked ) {
		if ( false !== strpos( $path, $blocked ) ) {
			return false;
		}
	}

	if ( false !== strpos( $path, 'checkout' ) ) {
		$query = array();
		parse_str( isset( $target['query'] ) ? $target['query'] : '', $query );
		if ( empty( $query['add-to-cart'] ) || intval( $query['add-to-cart'] ) !== intval( $post_id ) ) {
			return false;
		}
	}

	if ( ! empty( $page_url ) ) {
		$page_normal = trailingslashit( $page_url );
		$url_normal  = trailingslashit( $url );
		if ( $url_normal === $page_normal ) {
			return true;
		}
	}

	return true;
}
