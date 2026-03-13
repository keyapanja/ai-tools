<?php
/**
 * Plugin Name: AI Course Advisor
 * Description: Intelligent, self-hosted chatbot that indexes site content and recommends the best course/product based on user goals.
 * Version: 1.0.0
 * Author: AI Course Advisor Team
 * Text Domain: ai-course-advisor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AICA_VERSION', '1.0.0' );
define( 'AICA_PLUGIN_FILE', __FILE__ );
define( 'AICA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AICA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once AICA_PLUGIN_DIR . 'includes/database.php';
require_once AICA_PLUGIN_DIR . 'includes/vector-index.php';
require_once AICA_PLUGIN_DIR . 'includes/crawler.php';
require_once AICA_PLUGIN_DIR . 'includes/ai-engine.php';
require_once AICA_PLUGIN_DIR . 'includes/recommendation-engine.php';
require_once AICA_PLUGIN_DIR . 'admin/settings-page.php';
require_once AICA_PLUGIN_DIR . 'api/chat-endpoint.php';

/**
 * Activation callback.
 *
 * @return void
 */
function aica_activate_plugin() {
	aica_create_tables();
	aica_ensure_default_settings();
	aica_crawl_and_index_site_content();
}
register_activation_hook( __FILE__, 'aica_activate_plugin' );

/**
 * Register scripts and styles.
 *
 * @return void
 */
function aica_register_assets() {
	wp_register_style(
		'aica-chat-style',
		AICA_PLUGIN_URL . 'assets/chat-style.css',
		array(),
		AICA_VERSION
	);

	wp_register_script(
		'aica-chat-ui',
		AICA_PLUGIN_URL . 'assets/chat-ui.js',
		array(),
		AICA_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'aica_register_assets' );

/**
 * Enqueue frontend assets lazily.
 *
 * @return void
 */
function aica_enqueue_front_assets() {
	$options = aica_get_settings();

	if ( empty( $options['enabled'] ) ) {
		return;
	}

	wp_enqueue_style( 'aica-chat-style' );
	wp_enqueue_script( 'aica-chat-ui' );

	wp_localize_script(
		'aica-chat-ui',
		'AICourseAdvisor',
		array(
			'restUrl'      => esc_url_raw( rest_url( 'ai-course-advisor/v1/chat' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'title'        => isset( $options['chatbot_title'] ) ? sanitize_text_field( $options['chatbot_title'] ) : __( 'AI Course Advisor', 'ai-course-advisor' ),
			'primaryColor' => isset( $options['primary_color'] ) ? sanitize_hex_color( $options['primary_color'] ) : '#4f46e5',
			'botAvatar'    => isset( $options['bot_avatar'] ) ? esc_url_raw( $options['bot_avatar'] ) : '',
			'greeting'     => __( 'Hi 👋 Tell me what you\'re looking for today.', 'ai-course-advisor' ),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'aica_enqueue_front_assets' );

/**
 * Render floating widget container.
 *
 * @return void
 */
function aica_render_widget_shell() {
	$options = aica_get_settings();

	if ( empty( $options['enabled'] ) ) {
		return;
	}

	echo '<div id="aica-chatbot-root" aria-live="polite"></div>';
}
add_action( 'wp_footer', 'aica_render_widget_shell' );

/**
 * Sync index when content changes.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 *
 * @return void
 */
function aica_sync_on_save( $post_id, $post ) {
	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}

	if ( ! in_array( $post->post_status, array( 'publish', 'private' ), true ) ) {
		return;
	}

	aica_index_single_post( $post_id );
}
add_action( 'save_post', 'aica_sync_on_save', 10, 2 );
add_action( 'publish_post', 'aica_index_single_post', 10, 1 );
