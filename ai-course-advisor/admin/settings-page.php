<?php
/**
 * Admin settings page.
 *
 * @package AICourseAdvisor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register admin menu.
 *
 * @return void
 */
function aica_register_admin_menu() {
	add_menu_page(
		__( 'AI Course Advisor', 'ai-course-advisor' ),
		__( 'AI Course Advisor', 'ai-course-advisor' ),
		'manage_options',
		'ai-course-advisor',
		'aica_render_settings_page',
		'dashicons-format-chat',
		56
	);
}
add_action( 'admin_menu', 'aica_register_admin_menu' );

/**
 * Register settings.
 *
 * @return void
 */
function aica_register_settings() {
	register_setting(
		'aica_settings_group',
		'aica_settings',
		array(
			'sanitize_callback' => 'aica_sanitize_settings',
		)
	);
}
add_action( 'admin_init', 'aica_register_settings' );

/**
 * Sanitize settings payload.
 *
 * @param array $input Raw input.
 *
 * @return array
 */
function aica_sanitize_settings( $input ) {
	return array(
		'api_provider'   => in_array( $input['api_provider'], array( 'openai', 'gemini' ), true ) ? $input['api_provider'] : 'openai',
		'api_key'        => sanitize_text_field( $input['api_key'] ),
		'chatbot_title'  => sanitize_text_field( $input['chatbot_title'] ),
		'primary_color'  => sanitize_hex_color( $input['primary_color'] ),
		'bot_avatar'     => esc_url_raw( $input['bot_avatar'] ),
		'enabled'        => empty( $input['enabled'] ) ? 0 : 1,
		'manual_context' => sanitize_textarea_field( $input['manual_context'] ),
	);
}

/**
 * Re-index action.
 *
 * @return void
 */
function aica_handle_reindex_action() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( empty( $_GET['aica_reindex'] ) ) {
		return;
	}

	check_admin_referer( 'aica_reindex_nonce' );
	aica_crawl_and_index_site_content();

	wp_safe_redirect( admin_url( 'admin.php?page=ai-course-advisor&reindexed=1' ) );
	exit;
}
add_action( 'admin_init', 'aica_handle_reindex_action' );

/**
 * Render settings UI.
 *
 * @return void
 */
function aica_render_settings_page() {
	$settings = aica_get_settings();
	global $wpdb;
	$logs_table = $wpdb->prefix . 'ai_course_chat_logs';
	$top_questions = $wpdb->get_results( "SELECT question, COUNT(*) as cnt FROM {$logs_table} GROUP BY question ORDER BY cnt DESC LIMIT 5", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'AI Course Advisor Settings', 'ai-course-advisor' ); ?></h1>
		<?php if ( ! empty( $_GET['reindexed'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Content re-indexed successfully.', 'ai-course-advisor' ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'aica_settings_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="aica_api_provider"><?php esc_html_e( 'API Provider', 'ai-course-advisor' ); ?></label></th>
					<td>
						<select name="aica_settings[api_provider]" id="aica_api_provider">
							<option value="openai" <?php selected( isset( $settings['api_provider'] ) ? $settings['api_provider'] : '', 'openai' ); ?>>OpenAI</option>
							<option value="gemini" <?php selected( isset( $settings['api_provider'] ) ? $settings['api_provider'] : '', 'gemini' ); ?>>Google Gemini</option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aica_api_key"><?php esc_html_e( 'API Key', 'ai-course-advisor' ); ?></label></th>
					<td><input type="password" name="aica_settings[api_key]" id="aica_api_key" class="regular-text" value="<?php echo esc_attr( isset( $settings['api_key'] ) ? $settings['api_key'] : '' ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="aica_chatbot_title"><?php esc_html_e( 'Chatbot Title', 'ai-course-advisor' ); ?></label></th>
					<td><input type="text" name="aica_settings[chatbot_title]" id="aica_chatbot_title" class="regular-text" value="<?php echo esc_attr( isset( $settings['chatbot_title'] ) ? $settings['chatbot_title'] : '' ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="aica_primary_color"><?php esc_html_e( 'Primary Color', 'ai-course-advisor' ); ?></label></th>
					<td><input type="color" name="aica_settings[primary_color]" id="aica_primary_color" value="<?php echo esc_attr( isset( $settings['primary_color'] ) ? $settings['primary_color'] : '#4f46e5' ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="aica_bot_avatar"><?php esc_html_e( 'Bot Avatar URL', 'ai-course-advisor' ); ?></label></th>
					<td><input type="url" name="aica_settings[bot_avatar]" id="aica_bot_avatar" class="regular-text" value="<?php echo esc_url( isset( $settings['bot_avatar'] ) ? $settings['bot_avatar'] : '' ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="aica_manual_context"><?php esc_html_e( 'Manual Course Priority Context', 'ai-course-advisor' ); ?></label></th>
					<td><textarea name="aica_settings[manual_context]" id="aica_manual_context" rows="5" class="large-text"><?php echo esc_textarea( isset( $settings['manual_context'] ) ? $settings['manual_context'] : '' ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Chatbot', 'ai-course-advisor' ); ?></th>
					<td><label><input type="checkbox" name="aica_settings[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>> <?php esc_html_e( 'Show chatbot widget on frontend', 'ai-course-advisor' ); ?></label></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<p>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=ai-course-advisor&aica_reindex=1' ), 'aica_reindex_nonce' ) ); ?>" class="button button-secondary">
				<?php esc_html_e( 'Rebuild Content Index', 'ai-course-advisor' ); ?>
			</a>
		</p>

		<h2><?php esc_html_e( 'Top Questions (Analytics)', 'ai-course-advisor' ); ?></h2>
		<?php if ( empty( $top_questions ) ) : ?>
			<p><?php esc_html_e( 'No chatbot questions recorded yet.', 'ai-course-advisor' ); ?></p>
		<?php else : ?>
			<ul>
				<?php foreach ( $top_questions as $row ) : ?>
					<li><?php echo esc_html( $row['question'] ); ?> (<?php echo intval( $row['cnt'] ); ?>)</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
	<?php
}
