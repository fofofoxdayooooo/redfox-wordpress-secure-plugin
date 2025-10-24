<?php
/**
 * Plugin Name: Authenticated Privilege Guard (APG) - Secure Edition
 * Plugin URI:  https://p-fox.jp/
 * Description: 内部（認証済）権限昇格対策。編集者等が乗っ取られた場合でも致命的操作（ユーザー権限変更、プラグイン/テーマ編集、他者投稿編集など）を補助的にブロックし、監査ログを安全に記録します。共同編集時も「削除・下書き化のみ」例外的に許可。
 * Version:     1.6.0
 * Author:      Red Fox (team Red Fox)
 * License:     GPLv2 or later
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* =========================================================
 * ユーティリティ関数
 * ========================================================= */
function apg_get_option( $key = null, $default = null ) {
	$opts = get_option(
		'apg_options',
		array(
			'enable_guard'        => 1,
			'log_level'           => 'normal',
			'force_override'      => 0,
			'rest_exceptions'     => '',
			'allow_editor_collab' => 0,
			'disable_logging'     => 0,
			'log_dir'             => '',
		)
	);
	if ( null === $key ) return $opts;
	return isset( $opts[ $key ] ) ? $opts[ $key ] : $default;
}

/* =========================================================
 * 安全ログ関数
 * ========================================================= */
function apg_log( $msg, $force = false ) {
	// =========================================================
	// ログ・ガード設定確認
	// =========================================================
	$opts = apg_get_option();
	$enable_guard    = ! empty( $opts['enable_guard'] );
	$disable_logging = ! empty( $opts['disable_logging'] );

	// ログ無効の場合は完全停止
	if ( $disable_logging && ! $force ) {
		return;
	}

	// ガード自体が無効の場合も停止
	if ( ! $enable_guard && ! $force ) {
		return;
	}

	// =========================================================
	// ここから先は実際に書き込みを行う場合のみ
	// =========================================================
	$base_dir   = trailingslashit( WP_CONTENT_DIR );
	$custom_dir = trim( $opts['log_dir'] ?? '' );
	$target_dir = $base_dir . 'apg-logs/'; // デフォルト

	$invalid = false;
	if ( $custom_dir !== '' ) {
		if (
			preg_match( '/[^a-zA-Z0-9_\-\/]/', $custom_dir ) ||
			strpos( $custom_dir, '..' ) !== false ||
			str_starts_with( $custom_dir, '/' ) ||
			preg_match( '/\s/', $custom_dir )
		) {
			$invalid = true;
		} else {
			$resolved = realpath( $base_dir . $custom_dir );
			if ( $resolved === false || strpos( $resolved, realpath( $base_dir ) ) !== 0 ) {
				$invalid = true;
			} else {
				$target_dir = trailingslashit( $resolved );
			}
		}
	}

	if ( $invalid ) {
		$target_dir = $base_dir . 'apg-logs-fallback/';
	}

	if ( ! file_exists( $target_dir ) ) {
		wp_mkdir_p( $target_dir );
	}

	global $wp_filesystem;
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	WP_Filesystem();

	if ( ! $wp_filesystem->is_dir( $target_dir ) || ! $wp_filesystem->is_writable( $target_dir ) ) {
		return;
	}

	$file = $target_dir . 'apg-log.log';
	$time = gmdate( 'Y-m-d H:i:s' );
	$line = sprintf( "[%s] %s\n", $time, $msg );

	$wp_filesystem->put_contents( $file, $line, FS_CHMOD_FILE );
}

/* =========================================================
 * ユーザー識別と権限チェック
 * ========================================================= */
function apg_current_user_id_or_zero() {
	return is_user_logged_in() ? get_current_user_id() : 0;
}

function apg_user_is_trusted( $user_id = null ) {
	if ( null === $user_id ) $user_id = apg_current_user_id_or_zero();
	$user = get_userdata( $user_id );
	if ( ! $user ) return false;
	return in_array( 'administrator', (array) $user->roles, true );
}

/* =========================================================
 * 危険cap除去
 * ========================================================= */
add_filter(
	'user_has_cap',
	function( $allcaps, $caps, $args, $user ) {
		if ( ! apg_get_option( 'enable_guard' ) ) return $allcaps;
		$danger_caps = array(
			'install_plugins','update_plugins','delete_plugins',
			'activate_plugins','edit_plugins','edit_themes',
			'install_themes','update_themes','edit_files',
			'edit_dashboard','create_users','delete_users',
			'promote_users','edit_users','edit_theme_options',
			'manage_options','switch_themes',
		);
		$user_id = $user instanceof WP_User ? $user->ID : (int) $user['ID'];
		if ( apg_user_is_trusted( $user_id ) ) return $allcaps;

		foreach ( $danger_caps as $dc ) {
			if ( ! empty( $allcaps[ $dc ] ) && true === $allcaps[ $dc ] ) {
				$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
				apg_log( sprintf( 'Blocked capability: user=%d cap=%s ip=%s', $user_id, $dc, $ip ) );
				$allcaps[ $dc ] = false;
			}
		}
		return $allcaps;
	},
	PHP_INT_MAX,
	4
);

/* =========================================================
 * 投稿編集制御
 * ========================================================= */
add_filter(
	'map_meta_cap',
	function( $caps, $cap, $user_id, $args ) {
		if ( ! apg_get_option( 'enable_guard' ) ) return $caps;
		if ( apg_user_is_trusted( $user_id ) ) return $caps;

		if ( in_array( $cap, array( 'edit_post','delete_post','edit_others_posts','delete_others_posts' ), true )
			&& isset( $args[0] ) && $post = get_post( $args[0] ) ) {

			$post_author = get_userdata( $post->post_author );
			if ( $post_author && in_array( 'administrator', (array) $post_author->roles, true ) ) {
				$caps[] = 'do_not_allow';
				apg_log( sprintf( 'Blocked admin post manipulation: user=%d post=%d', $user_id, $post->ID ) );
				return $caps;
			}

			if ( (int) $post->post_author === (int) $user_id ) {
				return $caps;
			}

			$allow_editor_collab = (bool) apg_get_option( 'allow_editor_collab', false );
			if ( $allow_editor_collab ) {
				apg_log( sprintf( 'Allowed editor collaboration: user=%d post=%d', $user_id, $post->ID ) );
				return $caps;
			}

			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			$is_editor_screen = ( is_admin() && strpos( $request_uri, 'post.php' ) !== false );

			if ( $is_editor_screen ) {
				$intent     = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
				$new_status = isset( $_REQUEST['post_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['post_status'] ) ) : '';

				if ( isset( $_REQUEST['_wpnonce'] ) && ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'update-post_' . $post->ID ) ) {
					apg_log( sprintf( 'Nonce verification failed: user=%d post=%d', $user_id, $post->ID ) );
					$caps[] = 'do_not_allow';
					return $caps;
				}

				$allowed_ops = array( 'trash', 'draft' );
				if ( in_array( $intent, $allowed_ops, true ) || in_array( $new_status, $allowed_ops, true ) ) {
					apg_log( sprintf( 'Allowed limited modification: user=%d post=%d', $user_id, $post->ID ) );
					return $caps;
				}
			}

			$caps[] = 'do_not_allow';
			apg_log( sprintf( 'Blocked others post edit: user=%d post=%d', $user_id, $post->ID ) );
		}
		return $caps;
	},
	PHP_INT_MAX,
	4
);

/* =========================================================
 * ロール変更阻止
 * ========================================================= */
add_action(
	'set_user_role',
	function( $user_id, $role, $old_roles ) {
		if ( ! apg_get_option( 'enable_guard' ) ) return;
		$actor_id = apg_current_user_id_or_zero();
		if ( ! $actor_id || apg_user_is_trusted( $actor_id ) ) return;
		apg_log( sprintf( 'Blocked set_user_role: actor=%d target=%d newrole=%s', $actor_id, $user_id, $role ) );
		wp_die( esc_html( 'Access denied. Role changes are restricted.', 'authenticated-privilege-guard' ) );
	},
	1,
	3
);

/* =========================================================
 * RESTルート保護
 * ========================================================= */
add_filter(
	'rest_pre_dispatch',
	function( $result, $server, $request ) {
		if ( ! apg_get_option( 'enable_guard' ) ) return $result;
		$route   = $request->get_route();
		$method  = $request->get_method();
		$user_id = apg_current_user_id_or_zero();
		if ( apg_user_is_trusted( $user_id ) ) return $result;

		$exception_raw = apg_get_option( 'rest_exceptions', '' );
		$exceptions = array_filter( array_map( 'trim', explode( "\n", $exception_raw ) ) );
		foreach ( $exceptions as $allow ) {
			if ( @preg_match( $allow, $route ) ) {
				apg_log( sprintf( 'Allowed REST route: %s', $allow ) );
				return $result;
			}
		}

		$danger_patterns = array( '#^/wp/v2/users#','#^/wp/v2/plugins#','#^/wp/v2/themes#','#^/wp/v2/settings#','#^/wp/v2/options#' );
		foreach ( $danger_patterns as $pat ) {
			if ( preg_match( $pat, $route ) ) {
				apg_log( sprintf( 'Blocked REST route: user=%d route=%s method=%s', $user_id, $route, $method ) );
				return new WP_Error( 'apg_rest_block', esc_html( 'Access denied to privileged REST route.', 'authenticated-privilege-guard' ), array( 'status' => 403 ) );
			}
		}
		return $result;
	},
	PHP_INT_MAX,
	3
);

/* =========================================================
 * 設定画面
 * ========================================================= */
add_action( 'admin_menu', function() {
	add_options_page( 'Authenticated Privilege Guard', 'Authenticated Privilege Guard', 'manage_options', 'apg-settings', 'apg_render_settings_page' );
});

add_action( 'admin_init', function() {
	register_setting( 'apg_settings_group', 'apg_options', array(
		'type'              => 'array',
		'sanitize_callback' => 'apg_sanitize_options',
		'default' => array(
			'enable_guard'        => 1,
			'log_level'           => 'normal',
			'force_override'      => 0,
			'rest_exceptions'     => '',
			'allow_editor_collab' => 0,
			'disable_logging'     => 0,
			'log_dir'             => '',
		),
	));

	add_settings_section( 'apg_section', '', '__return_false', 'apg-settings' );
	add_settings_field( 'enable_guard', '防御機能の有効化', 'apg_field_enable_guard', 'apg-settings', 'apg_section' );
	add_settings_field( 'log_level', 'ログ粒度', 'apg_field_log_level', 'apg-settings', 'apg_section' );
	add_settings_field( 'disable_logging', 'ログを保存しない（非推奨）', 'apg_field_disable_logging', 'apg-settings', 'apg_section' );
	add_settings_field( 'log_dir', 'ログ保存ディレクトリ（WP_CONTENT_DIR配下）', 'apg_field_log_dir', 'apg-settings', 'apg_section' );
	add_settings_field( 'force_override', '投稿最優先割込み', 'apg_field_force_override', 'apg-settings', 'apg_section' );
	add_settings_field( 'allow_editor_collab', '編集者による共同編集の許可', 'apg_field_allow_editor_collab', 'apg-settings', 'apg_section' );
	add_settings_field( 'rest_exceptions', 'REST例外ルート（1行1パターン）', 'apg_field_rest_exceptions', 'apg-settings', 'apg_section' );
});

function apg_field_enable_guard() {
	$o = apg_get_option();
	printf( '<label><input type="checkbox" name="apg_options[enable_guard]" value="1" %s> 有効</label>', checked( 1, $o['enable_guard'], false ) );
}
function apg_field_log_level() {
	$o = apg_get_option();
	$level = $o['log_level'];
	echo '<select name="apg_options[log_level]">';
	echo '<option value="minimal"' . selected( $level, 'minimal', false ) . '>最小</option>';
	echo '<option value="normal"' . selected( $level, 'normal', false ) . '>通常</option>';
	echo '<option value="verbose"' . selected( $level, 'verbose', false ) . '>詳細</option>';
	echo '</select>';
}
function apg_field_disable_logging() {
	$o = apg_get_option();
	printf( '<label><input type="checkbox" name="apg_options[disable_logging]" value="1" %s> ログを保存しない（非推奨）</label><p class="description">監査ログが残らなくなります。推奨設定ではありません。</p>', checked( 1, $o['disable_logging'], false ) );
}
function apg_field_log_dir() {
	$o = apg_get_option();
	$val = esc_attr( $o['log_dir'] ?? '' );
	echo '<input type="text" name="apg_options[log_dir]" value="' . esc_attr($val) . '" size="40" placeholder="apg-logs">';
	echo '<p class="description">WP_CONTENT_DIR配下のみ指定可能。空白・記号・「../」などは自動的にフォールバックします。</p>';
}
function apg_field_force_override() {
	$o = apg_get_option();
	printf( '<label><input type="checkbox" name="apg_options[force_override]" value="1" %s> 有効（他プラグイン設定を上書き）</label>', checked( 1, $o['force_override'], false ) );
}
function apg_field_allow_editor_collab() {
	$o = apg_get_option();
	printf( '<label><input type="checkbox" name="apg_options[allow_editor_collab]" value="1" %s> 有効（他者投稿の編集を完全に許可 - 管理者投稿は除く）</label>', checked( 1, $o['allow_editor_collab'], false ) );
}
function apg_field_rest_exceptions() {
	$o = apg_get_option();
	echo '<textarea name="apg_options[rest_exceptions]" rows="6" cols="60" placeholder="#^/wp-json/contact-form-7/.*$">' . esc_textarea( $o['rest_exceptions'] ) . '</textarea>';
	echo '<p class="description">正規表現でルートパターンを指定可能。</p>';
}
function apg_sanitize_options( $input ) {
	$log_dir = sanitize_text_field( $input['log_dir'] ?? '' );

	// 無効文字・空白・相対パス・危険記号のチェック
	if (
		preg_match( '/[^a-zA-Z0-9_\-\/]/', $log_dir ) ||
		preg_match( '/\s/', $log_dir ) ||
		strpos( $log_dir, '..' ) !== false ||
		str_starts_with( $log_dir, '/' )
	) {
		// 無効な場合はフォールバック
		$log_dir = '';
	}

	return array(
		'enable_guard'        => empty( $input['enable_guard'] ) ? 0 : 1,
		'log_level'           => in_array( $input['log_level'] ?? 'normal', array( 'minimal', 'normal', 'verbose' ), true ) ? $input['log_level'] : 'normal',
		'force_override'      => empty( $input['force_override'] ) ? 0 : 1,
		'rest_exceptions'     => sanitize_textarea_field( $input['rest_exceptions'] ?? '' ),
		'allow_editor_collab' => empty( $input['allow_editor_collab'] ) ? 0 : 1,
		'disable_logging'     => empty( $input['disable_logging'] ) ? 0 : 1,
		'log_dir'             => $log_dir,
	);
}

/* =========================================================
 * 設定ページ描画
 * ========================================================= */
function apg_render_settings_page() {
	echo '<div class="wrap"><h1>Authenticated Privilege Guard 設定</h1>';
	echo '<form method="post" action="options.php">';
	settings_fields( 'apg_settings_group' );
	do_settings_sections( 'apg-settings' );
	submit_button();
	echo '</form></div>';
}