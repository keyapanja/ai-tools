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
 *
 * @return array<string,mixed>
 */
function aica_get_recommendation_for_query( $message ) {
	$user_message = sanitize_text_field( $message );
	$query_vec    = aica_generate_embedding( $user_message );
	$rows         = aica_get_all_vectors();
	$scored       = array();

	foreach ( $rows as $row ) {
		$score = aica_cosine_similarity( $query_vec, (array) $row['embedding'] ) + ( intval( $row['manual_priority'] ) / 100 );
		$row['score'] = $score;
		$scored[]     = $row;
	}

	usort(
		$scored,
		function ( $a, $b ) {
			return $a['score'] < $b['score'] ? 1 : -1;
		}
	);

	$top_matches = array_slice( $scored, 0, 3 );
	$prompt      = aica_build_recommendation_prompt( $user_message, $top_matches );
	$answer      = aica_generate_chat_completion( $prompt );

	$book_link = '';
	if ( ! empty( $top_matches[0]['checkout_link'] ) ) {
		$book_link = esc_url_raw( $top_matches[0]['checkout_link'] );
	}

	if ( $book_link ) {
		$answer .= "\n\n<a class=\"aica-book-now\" href=\"" . esc_url( $book_link ) . "\" target=\"_blank\" rel=\"noopener\">" . esc_html__( 'Book Now', 'ai-course-advisor' ) . '</a>';
	}

	$matched_ids = wp_list_pluck( $top_matches, 'post_id' );
	aica_log_chat_interaction( $user_message, $answer, $matched_ids );

	return array(
		'answer'     => $answer,
		'matches'    => $top_matches,
		'book_link'  => $book_link,
		'user_query' => $user_message,
	);
}

/**
 * Build LLM prompt.
 *
 * @param string $user_query User query.
 * @param array  $matches    Top course matches.
 *
 * @return string
 */
function aica_build_recommendation_prompt( $user_query, $matches ) {
	$settings = aica_get_settings();
	$context  = '';

	foreach ( $matches as $item ) {
		$meta = isset( $item['meta'] ) && is_array( $item['meta'] ) ? $item['meta'] : array();
		$context .= "Course Title: {$item['title']}\n";
		$context .= 'Description: ' . wp_trim_words( $item['content'], 80 ) . "\n";
		$context .= 'Benefits: ' . ( isset( $meta['benefits'] ) ? $meta['benefits'] : '' ) . "\n";
		$context .= 'Duration: ' . ( isset( $meta['duration'] ) ? $meta['duration'] : '' ) . "\n";
		$context .= 'Price: ' . ( isset( $meta['price'] ) ? $meta['price'] : '' ) . "\n";
		$context .= "Checkout Link: {$item['checkout_link']}\n";
		$context .= "Page URL: {$item['url']}\n\n";
	}

	if ( ! empty( $settings['manual_context'] ) ) {
		$context .= 'Business Notes: ' . sanitize_textarea_field( $settings['manual_context'] ) . "\n\n";
	}

	return "User Goal: {$user_query}\n\nUse the course context below to recommend the best option. Keep response friendly and concise. Include title, why it matches, duration, price and clear call-to-action.\n\n{$context}";
}
