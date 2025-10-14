<?php
/**
 * Plugin Name: Feed Access Controller
 * Description: サイト内のすべてのフィード (/feed) へのアクセスを制御し、ホワイトリスト化されたルートのみ許可します。トップページfeedを個別に許可できます。
 * Plugin URI: https://p-fox.jp/blog/archive/367/
 * Version: 1.1.1
 * Author: Red Fox (team Red Fox)
 * Author URI: https://p-fox.jp/
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: feed-access-controller
 * Requires at least: 6.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * =========================================================
 * PART 1: Feed Access Restriction Core
 * =========================================================
 */

function fac_block_feed_access() {

	if ( ! is_feed() ) {
		return;
	}

	// 設定キャッシュ（パフォーマンス向上）
	static $fac_settings = null;
	if ( null === $fac_settings ) {
		$fac_settings = array(
			'block_all'     => get_option( 'fac_block_feed_access', '1' ) === '1',
			'allow_home'    => get_option( 'fac_allow_home_feed', '0' ) === '1',
			'whitelist_raw' => (string) get_option( 'fac_allowed_feed_routes', '' ),
			'error_code'    => absint( get_option( 'fac_feed_error_code', '403' ) ),
			'error_message' => (string) get_option( 'fac_feed_error_message', __( 'Feed access is denied.', 'feed-access-controller' ) ),
		);
	}

	if ( ! $fac_settings['block_all'] ) {
		return;
	}

	// --- URLパスの安全な抽出 ---
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	$parsed_url  = wp_parse_url( $request_uri );
	$request_path = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '';

	// feedパターン除去 (/feed/, /rss2/ など)
	$request_path = preg_replace( '#/(feed|rss2|atom|rdf|comments/feed|comment-rss)(/)?$#i', '', $request_path );
	$request_path = trim( untrailingslashit( $request_path ), '/' );

	// --- トップページfeedの許可設定 ---
	if ( $request_path === '' && $fac_settings['allow_home'] ) {
		return;
	}

	// --- ホワイトリストマッチング ---
	$allowed_routes = array_filter(
		array_map( 'trim', explode( "\n", strtolower( $fac_settings['whitelist_raw'] ) ) )
	);

	$current = strtolower( $request_path );
	foreach ( $allowed_routes as $route ) {
		$route = trim( untrailingslashit( $route ), '/' );
		if ( $route === '' ) {
			continue;
		}
		if ( $current === $route || strpos( $current, $route . '/' ) === 0 ) {
			return; // 許可済みルート
		}
	}

	// --- ブロック処理 ---
	status_header( $fac_settings['error_code'] );
	wp_die(
		esc_html( $fac_settings['error_message'] ),
		esc_html__( 'Feed Access Restricted', 'feed-access-controller' ),
		array( 'response' => absint( $fac_settings['error_code'] ) )
	);
}
add_action( 'template_redirect', 'fac_block_feed_access', 0 );

/**
 * =========================================================
 * PART 2: Settings Page (Settings → Reading)
 * =========================================================
 */

function fac_settings_init() {
	register_setting(
		'reading',
		'fac_block_feed_access',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '1',
		)
	);
	register_setting(
		'reading',
		'fac_allow_home_feed',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '0',
		)
	);
	register_setting(
		'reading',
		'fac_allowed_feed_routes',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'wp_kses_post',
			'default'           => '',
		)
	);
	register_setting(
		'reading',
		'fac_feed_error_code',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '403',
		)
	);
	register_setting(
		'reading',
		'fac_feed_error_message',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => __( 'Feed access is denied.', 'feed-access-controller' ),
		)
	);

	add_settings_section(
		'fac_feed_section',
		__( 'Feed Access Control', 'feed-access-controller' ),
		'fac_feed_section_callback',
		'reading'
	);

	add_settings_field(
		'fac_block_feed_access',
		__( 'Block Non-Whitelisted Feeds', 'feed-access-controller' ),
		'fac_block_feed_access_field',
		'reading',
		'fac_feed_section'
	);
	add_settings_field(
		'fac_allow_home_feed',
		__( 'Allow Home Feed', 'feed-access-controller' ),
		'fac_allow_home_feed_field',
		'reading',
		'fac_feed_section'
	);
	add_settings_field(
		'fac_allowed_feed_routes',
		__( 'Allowed Feed Routes (Whitelist)', 'feed-access-controller' ),
		'fac_allowed_feed_routes_field',
		'reading',
		'fac_feed_section'
	);
	add_settings_field(
		'fac_feed_error_code',
		__( 'Error Code', 'feed-access-controller' ),
		'fac_feed_error_code_field',
		'reading',
		'fac_feed_section'
	);
	add_settings_field(
		'fac_feed_error_message',
		__( 'Error Message', 'feed-access-controller' ),
		'fac_feed_error_message_field',
		'reading',
		'fac_feed_section'
	);
}
add_action( 'admin_init', 'fac_settings_init' );

function fac_feed_section_callback() {
	echo '<p>' . esc_html__( 'Control which feed URLs (RSS/Atom) are accessible. Use this to prevent scraping or unwanted content aggregation.', 'feed-access-controller' ) . '</p>';
}

function fac_block_feed_access_field() {
	$value = get_option( 'fac_block_feed_access', '1' );
	echo '<input type="checkbox" id="fac_block_feed_access" name="fac_block_feed_access" value="1" ' . checked( $value, '1', false ) . ' />';
	echo '<label for="fac_block_feed_access"> ' . esc_html__( 'Block all feeds unless whitelisted.', 'feed-access-controller' ) . '</label>';
}

function fac_allow_home_feed_field() {
	$value = get_option( 'fac_allow_home_feed', '0' );
	echo '<input type="checkbox" id="fac_allow_home_feed" name="fac_allow_home_feed" value="1" ' . checked( $value, '1', false ) . ' />';
	echo '<label for="fac_allow_home_feed"> ' . esc_html__( 'Allow site root feed (/feed).', 'feed-access-controller' ) . '</label>';
}

function fac_allowed_feed_routes_field() {
	$routes = get_option( 'fac_allowed_feed_routes', '' );
	echo '<textarea id="fac_allowed_feed_routes" name="fac_allowed_feed_routes" class="large-text code" rows="5">' . esc_textarea( $routes ) . '</textarea>';
	echo '<p class="description">' . wp_kses_post( __( 'Enter one route per line, without leading slashes. For example: <code>blog</code> or <code>category/news</code> will allow <code>/blog/feed</code> or <code>/category/news/feed</code>.', 'feed-access-controller' ) ) . '</p>';
}

function fac_feed_error_code_field() {
	$code = get_option( 'fac_feed_error_code', '403' );
	echo '<input type="text" id="fac_feed_error_code" name="fac_feed_error_code" class="small-text" value="' . esc_attr( $code ) . '" />';
}

function fac_feed_error_message_field() {
	$message = get_option( 'fac_feed_error_message', __( 'Feed access is denied.', 'feed-access-controller' ) );
	echo '<input type="text" id="fac_feed_error_message" name="fac_feed_error_message" class="regular-text" value="' . esc_attr( $message ) . '" />';
}

/**
 * =========================================================
 * PART 3: Uninstall Cleanup
 * =========================================================
 */

register_uninstall_hook( __FILE__, 'fac_cleanup_options' );
function fac_cleanup_options() {
	$opts = array(
		'fac_block_feed_access',
		'fac_allow_home_feed',
		'fac_allowed_feed_routes',
		'fac_feed_error_code',
		'fac_feed_error_message',
	);
	foreach ( $opts as $opt ) {
		delete_option( $opt );
	}
}