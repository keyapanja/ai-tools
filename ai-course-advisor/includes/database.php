<?php
/**
 * Database and settings utilities.
 *
 * @package AICourseAdvisor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create plugin database tables.
 *
 * @return void
 */
function aica_create_tables() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset_collate = $wpdb->get_charset_collate();
	$vector_table    = $wpdb->prefix . 'ai_course_vectors';
	$logs_table      = $wpdb->prefix . 'ai_course_chat_logs';

	$sql_vectors = "CREATE TABLE {$vector_table} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		post_id BIGINT(20) UNSIGNED NOT NULL,
		title TEXT NOT NULL,
		content LONGTEXT NOT NULL,
		excerpt TEXT NULL,
		meta LONGTEXT NULL,
		embedding LONGTEXT NOT NULL,
		url TEXT NOT NULL,
		checkout_link TEXT NULL,
		manual_priority INT(11) DEFAULT 0,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY post_id (post_id)
	) {$charset_collate};";

	$sql_logs = "CREATE TABLE {$logs_table} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		question TEXT NOT NULL,
		answer LONGTEXT NOT NULL,
		matched_posts TEXT NULL,
		created_at DATETIME NOT NULL,
		PRIMARY KEY  (id)
	) {$charset_collate};";

	dbDelta( $sql_vectors );
	dbDelta( $sql_logs );
}

/**
 * Ensure plugin settings exist.
 *
 * @return void
 */
function aica_ensure_default_settings() {
	$defaults = array(
		'api_provider'   => 'openai',
		'api_key'        => '',
		'chatbot_title'  => 'AI Course Advisor',
		'primary_color'  => '#4f46e5',
		'bot_avatar'     => '',
		'enabled'        => 1,
		'manual_context' => '',
	);

	$current = get_option( 'aica_settings', array() );
	$merged  = wp_parse_args( is_array( $current ) ? $current : array(), $defaults );

	update_option( 'aica_settings', $merged );
}

/**
 * Get settings.
 *
 * @return array<string,mixed>
 */
function aica_get_settings() {
	$settings = get_option( 'aica_settings', array() );

	return is_array( $settings ) ? $settings : array();
}

/**
 * Log chatbot interaction for analytics.
 *
 * @param string $question User question.
 * @param string $answer   Bot answer.
 * @param array  $matches  Matched post IDs.
 *
 * @return void
 */
function aica_log_chat_interaction( $question, $answer, $matches = array() ) {
	global $wpdb;

	$logs_table = $wpdb->prefix . 'ai_course_chat_logs';

	$wpdb->insert(
		$logs_table,
		array(
			'question'     => sanitize_text_field( $question ),
			'answer'       => wp_kses_post( $answer ),
			'matched_posts'=> wp_json_encode( array_map( 'intval', (array) $matches ) ),
			'created_at'   => current_time( 'mysql' ),
		),
		array( '%s', '%s', '%s', '%s' )
	);
}
