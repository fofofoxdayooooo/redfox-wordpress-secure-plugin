<?php
/**
 * Plugin Name: Upload File Renamer (アップロードファイル名強制リネーム)
 * Description: アップロード時にファイル名を強制的にリネームします。Base64付与やスラッグ形式のカスタマイズも可能です。
 * Plugin URI: https://p-fox.jp/
 * Version: 1.0.0
 * Author: Red Fox
 * Author URI: https://p-fox.jp/
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: move-file-name
 * Requires at least: 6.8
 * Requires PHP: 7.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ----------------------------------------------------
// 管理画面設定
// ----------------------------------------------------

add_action('admin_menu', 'mfn_add_admin_menu');
add_action('admin_init', 'mfn_settings_init');
add_filter('wp_handle_upload_prefilter', 'mfn_force_rename_uploaded_file');
add_filter('wp_handle_sideload_prefilter', 'mfn_force_rename_uploaded_file');
add_filter('rest_pre_insert_attachment', 'mfn_rest_force_rename', 10, 2);
add_filter('wp_insert_post_data', 'mfn_sanitize_attachment_title', 10, 2);


function mfn_rest_force_rename($prepared_post, $request) {
	if ( ! get_option('mfn_enable_rename', 1) ) {
		return $prepared_post;
	}
	$random = md5(uniqid('', true));

	$prepared_post->post_title = sprintf(
		esc_html($random, 'move-file-name'),
		date_i18n('Y-m-d H:i:s', current_time('timestamp', 0))
	);

	return $prepared_post;
}


function mfn_add_admin_menu() {
	add_options_page(
		esc_html__('ファイルリネーム設定', 'move-file-name'),
		esc_html__('ファイルリネーム', 'move-file-name'),
		'manage_options',
		'mfn-settings-page',
		'mfn_settings_page_callback'
	);
}

function mfn_settings_page_callback() {
	if ( ! current_user_can('manage_options') ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<form action="options.php" method="post">
			<?php
			settings_fields('mfn_options_group');
			do_settings_sections('mfn-settings-page');
			submit_button(esc_html__('設定を保存', 'move-file-name'));
			?>
		</form>
	</div>
	<?php
}

function mfn_settings_init() {
	register_setting('mfn_options_group', 'mfn_enable_rename', [
		'default' => 1,
		'type' => 'boolean',
		'sanitize_callback' => 'intval',
	]);

	register_setting('mfn_options_group', 'mfn_rename_format', [
		'default' => 'Ymd_His_',
		'type' => 'string',
		'sanitize_callback' => 'sanitize_text_field',
	]);

	register_setting('mfn_options_group', 'mfn_forbidden_extensions', [
		'default' => 'exe, php, phtml, html, shtml, js',
		'type' => 'string',
		'sanitize_callback' => 'mfn_sanitize_forbidden_extensions',
	]);

	register_setting('mfn_options_group', 'mfn_enable_base64', [
		'default' => 0,
		'type' => 'boolean',
		'sanitize_callback' => 'intval',
	]);

	register_setting('mfn_options_group', 'mfn_base64_length', [
		'default' => 16,
		'type' => 'integer',
		'sanitize_callback' => 'absint',
	]);

	register_setting('mfn_options_group', 'mfn_slug_format', [
		'default' => 'media-%Y%m%d-%H%M%S',
		'type' => 'string',
		'sanitize_callback' => 'sanitize_text_field',
	]);

	add_settings_section(
		'mfn_main_section',
		esc_html__('アップロードファイル名強制リネーム設定', 'move-file-name'),
		'mfn_section_callback',
		'mfn-settings-page'
	);

	add_settings_field(
		'mfn_enable_rename',
		esc_html__('リネームを強制する', 'move-file-name'),
		'mfn_toggle_callback',
		'mfn-settings-page',
		'mfn_main_section'
	);

	add_settings_field(
		'mfn_rename_format',
		esc_html__('リネーム形式', 'move-file-name'),
		'mfn_format_callback',
		'mfn-settings-page',
		'mfn_main_section'
	);

	add_settings_field(
		'mfn_forbidden_extensions',
		esc_html__('禁止拡張子', 'move-file-name'),
		'mfn_forbidden_callback',
		'mfn-settings-page',
		'mfn_main_section'
	);

	add_settings_field(
		'mfn_enable_base64',
		esc_html__('Base64付与を有効にする', 'move-file-name'),
		'mfn_base64_toggle_callback',
		'mfn-settings-page',
		'mfn_main_section'
	);

	add_settings_field(
		'mfn_base64_length',
		esc_html__('Base64文字列の長さ', 'move-file-name'),
		'mfn_base64_length_callback',
		'mfn-settings-page',
		'mfn_main_section'
	);
}

function mfn_section_callback() {
	echo '<p>' . esc_html__('アップロード時のファイル名とスラッグ(post_name)の匿名化設定を行います。', 'move-file-name') . '</p>';
}

function mfn_toggle_callback() {
	$is_enabled = get_option('mfn_enable_rename', 1);
	?>
	<input type="checkbox" name="mfn_enable_rename" value="1" <?php checked(1, $is_enabled); ?>>
	<label><?php esc_html_e('オンにすると、アップロード時にファイル名を強制的にリネームします。', 'move-file-name'); ?></label>
	<?php
}

function mfn_format_callback() {
	$format = get_option('mfn_rename_format', 'Ymd_His_');
	?>
	<input type="text" name="mfn_rename_format" value="<?php echo esc_attr($format); ?>" class="regular-text">
	<p class="description"><?php esc_html_e('PHPのdate()関数形式で指定します。', 'move-file-name'); ?></p>
	<?php
}

function mfn_forbidden_callback() {
	$forbidden_list = get_option('mfn_forbidden_extensions', 'exe, php, phtml, html, shtml, js');
	?>
	<input type="text" name="mfn_forbidden_extensions" value="<?php echo esc_attr($forbidden_list); ?>" class="regular-text large-text">
	<p class="description"><?php esc_html_e('アップロード禁止拡張子をカンマ区切りで指定します。', 'move-file-name'); ?></p>
	<?php
}

function mfn_base64_toggle_callback() {
	$enable_base64 = get_option('mfn_enable_base64', 0);
	?>
	<input type="checkbox" name="mfn_enable_base64" value="1" <?php checked(1, $enable_base64); ?>>
	<label><?php esc_html_e('オンにするとファイル名末尾にBase64文字列を付与します。', 'move-file-name'); ?></label>
	<?php
}

function mfn_base64_length_callback() {
	$length = get_option('mfn_base64_length', 16);
	?>
	<select name="mfn_base64_length">
		<option value="16" <?php selected($length, 16); ?>>16</option>
		<option value="32" <?php selected($length, 32); ?>>32</option>
	</select>
	<p class="description"><?php esc_html_e('付与するBase64文字列の長さを指定します。', 'move-file-name'); ?></p>
	<?php
}

// ----------------------------------------------------
// ファイル名リネームロジック
// ----------------------------------------------------

add_filter('wp_handle_upload_prefilter', 'mfn_force_rename_uploaded_file');

function mfn_force_rename_uploaded_file($file) {
	if ( ! get_option('mfn_enable_rename', 1) ) {
		return $file;
	}


	$info = pathinfo($file['name']);
	$ext  = isset($info['extension']) ? strtolower($info['extension']) : '';

	if ( 'zip' === $ext ) {
		return $file;
	}

	$forbidden_list_string = get_option('mfn_forbidden_extensions', 'exe, php, phtml, html, shtml, js');
	$forbidden_extensions  = array_map('trim', explode(',', strtolower($forbidden_list_string)));

	if ( $ext && in_array($ext, $forbidden_extensions, true) ) {
		$file['error'] = sprintf(
			// translators: %s is the forbidden file extension (e.g., 'exe')
			esc_html__('セキュリティ上の理由により、ファイル拡張子「.%s」のアップロードは禁止されています。', 'move-file-name'),
			$ext
		);
		return $file;
	}

	$format_string = get_option('mfn_rename_format', 'Ymd_His_');
	$ext_with_dot  = $ext ? '.' . $ext : '';
	$date_prefix   = gmdate($format_string);

	$random = bin2hex(random_bytes(3));

	$enable_base64  = (int) get_option('mfn_enable_base64', 0);
	$base64_length  = (int) get_option('mfn_base64_length', 16);
	if ( $enable_base64 ) {
		$b64 = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes($base64_length))), 0, $base64_length);
		$b64 = strtolower($b64);
		$random .= '_' . $b64;
	}

	$new_filename_base = $date_prefix . uniqid('') . '_' . $random;
	$file['name']      = $new_filename_base . $ext_with_dot;

	return $file;
}

// ----------------------------------------------------
// メディアタイトルとスラッグ匿名化
// ----------------------------------------------------

add_filter('wp_insert_post_data', 'mfn_sanitize_attachment_title', 10, 2);

function mfn_sanitize_attachment_title($data, $postarr) {
	if ( 'attachment' !== $data['post_type'] ) {
		return $data;
	}
	if ( ! get_option('mfn_enable_rename', 1) ) {
		return $data;
	}

	// translators: %s is the localized current date and time (e.g., "2025-10-17 15:32:00")
	$new_title_format = esc_html__('メディアファイル %s', 'move-file-name');
	$date_string      = date_i18n('Y-m-d H:i:s', current_time('timestamp', 0));
	$new_title        = sprintf($new_title_format, $date_string);

	$data['post_title'] = $new_title;

	$slug_format = get_option('mfn_slug_format', 'media-%Y%m%d-%H%M%S');
	$slug_string = gmdate(str_replace('%', '', $slug_format));
	$data['post_name']  = sanitize_title($slug_string);

	return $data;
}

// ----------------------------------------------------
// アンインストール処理
// ----------------------------------------------------

function mfn_uninstall_cleanup() {
	delete_option('mfn_enable_rename');
	delete_option('mfn_rename_format');
	delete_option('mfn_forbidden_extensions');
	delete_option('mfn_enable_base64');
	delete_option('mfn_base64_length');
	delete_option('mfn_slug_format');
}
register_uninstall_hook(__FILE__, 'mfn_uninstall_cleanup');

// ----------------------------------------------------
// サニタイズ関数
// ----------------------------------------------------

function mfn_sanitize_forbidden_extensions($input) {
	$input = strtolower($input);
	$extensions = array_map('trim', explode(',', $input));
	$sanitized_extensions = array_map(function($ext) {
		return preg_replace('/[^a-z0-9]/', '', $ext);
	}, $extensions);
	return implode(', ', array_filter($sanitized_extensions));
}
