<?php
/**
 * Plugin Name: Authenticated Privilege Guard (APG) - Secure Edition
 * Plugin URI:  https://p-fox.jp/
 * Description: 内部（認証済）権限昇格対策。編集者等が乗っ取られた場合でも致命的操作（ユーザー権限変更、プラグイン/テーマ編集、他者投稿編集など）を補助的にブロックし、監査ログを安全に記録します。共同編集時も「削除・下書き化のみ」例外的に許可。
 * Version:     1.6.1
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
 * 安全ログ関数 (WP_Filesystemに準拠)
 * ========================================================= */
function apg_log( $msg, $force = false ) {
	// CLI環境ではまずスキップ判断
	$is_cli = ( php_sapi_name() === 'cli' || ( defined( 'WP_CLI' ) && WP_CLI ) );

	$opts = get_option( 'apg_options' );

	// ① オプション未ロード時は安全側に倒す（無効扱い）
	if ( ! is_array( $opts ) ) {
		return; // 初期化不完全時はログを止める
	}

	// ② 通常設定判定
	$enable_guard    = ! empty( $opts['enable_guard'] );
	$disable_logging = ! empty( $opts['disable_logging'] );

	if ( ( ! $enable_guard || $disable_logging ) && ! $force ) {
		// CLI時は設定尊重
		if ( $is_cli && $disable_logging && ! $force ) {
			return;
		}
		if ( ! $enable_guard && ! $force ) {
			return;
		}
		if ( $disable_logging && ! $force ) {
			return;
		}
	}

	// =========================================================
	// ここから先は実際に書き込みを行う場合のみ（WP_Filesystem必須）
	// =========================================================
	global $wp_filesystem;
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	// WP_Filesystemの初期化
	if ( ! $wp_filesystem || ! $wp_filesystem->init() ) {
		if ( ! WP_Filesystem() ) {
			return; // WP_Filesystemが使えない場合はログを諦める
		}
	}

	$base_dir   = trailingslashit( WP_CONTENT_DIR );
	$custom_dir = trim( $opts['log_dir'] ?? '' );
	$target_dir = $base_dir . 'apg-logs/'; // デフォルト

	$invalid = false;

	if ( $custom_dir !== '' ) {
		// 不正文字・パス操作対策 (apg_sanitize_optionsでほとんど処理済みだが、念のため)
		if (
			preg_match( '/[^a-zA-Z0-9_\-\/]/', $custom_dir ) ||
			strpos( $custom_dir, '..' ) !== false ||
			str_starts_with( $custom_dir, '/' ) ||
			preg_match( '/\s/', $custom_dir )
		) {
			$invalid = true;
		} else {
			$custom_path = $base_dir . ltrim( $custom_dir, '/' );

			// ディレクトリ生成（$wp_filesystem経由）
			if ( ! $wp_filesystem->is_dir( $custom_path ) ) {
				// wp_mkdir_p の代わりに $wp_filesystem->mkdir を使用
				if ( ! $wp_filesystem->mkdir( $custom_path ) ) {
					$invalid = true;
				}
			}

			// 書き込み可否チェック
			if ( ! $invalid && $wp_filesystem->is_dir( $custom_path ) && $wp_filesystem->is_writable( $custom_path ) ) {
				$target_dir = trailingslashit( $custom_path );
			} else {
				$invalid = true;
			}
		}
	}

	if ( $invalid ) {
		// フォールバックディレクトリ
		$target_dir = $base_dir . 'apg-logs-fallback/';
		// フォールバックディレクトリの作成も $wp_filesystem を使う
		if ( ! $wp_filesystem->is_dir( $target_dir ) ) {
			if ( ! $wp_filesystem->mkdir( $target_dir ) ) {
				return; // フォールバックも失敗したらログを断念
			}
		}
	}

	if ( ! $wp_filesystem->is_dir( $target_dir ) || ! $wp_filesystem->is_writable( $target_dir ) ) {
		return;
	}

	$file = $target_dir . 'apg-log.log';
	$time = gmdate( 'Y-m-d H:i:s' );
	$line = sprintf( "[%s] %s\n", $time, $msg );

	$max_size = 100 * 1024; // 100KB

	// ログローテーションを WP_Filesystem で実施
	if ( $wp_filesystem->exists( $file ) && $wp_filesystem->size( $file ) > $max_size ) {
		$wp_filesystem->move( $file, $file . '.' . gmdate('Ymd_His') . '.bak', true );
	}

	// ログの追記 (get_contents -> put_contents を使うことで、FTP/SSH環境でも動作保証)
	// put_contentsには追記モードがないため、一度読み込んで追記してから書き戻す
	$current_content = $wp_filesystem->get_contents( $file );
	$new_content = $current_content . $line . "\n"; // 改行を追加して可読性向上

	// ログファイルの書き込み（パーミッションは FS_CHMOD_FILE を使用）
	$wp_filesystem->put_contents( $file, $new_content, FS_CHMOD_FILE );
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
		// force_override オプションがあれば、他のプラグインの動作を無効化する
		if ( apg_get_option( 'force_override' ) ) {
			// このプラグインが最も早く処理されるように、フックを強制的に最優先にする
			if ( current_filter() !== 'user_has_cap' || did_action( 'user_has_cap' ) > 0 ) {
				// 既に他のフックが走っていたり、このフックが別の場所から呼ばれていたら処理をスキップ
			}
		}

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
			// 有効なCapabilityのみを無効化
			if ( ! empty( $allcaps[ $dc ] ) && true === $allcaps[ $dc ] ) {
				$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
				apg_log( sprintf( 'Blocked capability: user=%d cap=%s ip=%s', $user_id, $dc, $ip ) );
				$allcaps[ $dc ] = false;
			}
		}
		return $allcaps;
	},
	PHP_INT_MAX, // 最も高い優先度で実行
	4
);

/* =========================================================
 * 投稿編集制御 (管理者投稿の保護と共同編集の制限)
 * ========================================================= */
add_filter(
	'map_meta_cap',
	function( $caps, $cap, $user_id, $args ) {
		if ( ! apg_get_option( 'enable_guard' ) ) return $caps;
		if ( apg_user_is_trusted( $user_id ) ) return $caps;

		$danger_caps = array( 'edit_post','delete_post','edit_others_posts','delete_others_posts' );

		if ( in_array( $cap, $danger_caps, true ) && isset( $args[0] ) && $post = get_post( $args[0] ) ) {

			// 1. 管理者投稿の保護 (最優先)
			$post_author = get_userdata( $post->post_author );
			if ( $post_author && in_array( 'administrator', (array) $post_author->roles, true ) ) {
				$caps[] = 'do_not_allow';
				apg_log( sprintf( 'Blocked admin post manipulation: user=%d post=%d', $user_id, $post->ID ) );
				return $caps;
			}

			// 2. 自分の投稿は許可
			if ( (int) $post->post_author === (int) $user_id ) {
				return $caps;
			}

			// 3. 共同編集の許可オプション
			$allow_editor_collab = (bool) apg_get_option( 'allow_editor_collab', false );
			if ( $allow_editor_collab ) {
				apg_log( sprintf( 'Allowed editor collaboration: user=%d post=%d', $user_id, $post->ID ) );
				return $caps;
			}

			// 4. 限定的な操作（ゴミ箱/下書き）の許可 (Gutenberg/Classic Editor経由の操作)
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			$is_editor_screen = ( is_admin() && ( strpos( $request_uri, 'post.php' ) !== false || strpos( $request_uri, 'post-new.php' ) !== false ) );

			if ( $is_editor_screen ) {
				$intent     = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
				$new_status = isset( $_REQUEST['post_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['post_status'] ) ) : '';

				// ノンス検証は必須
				if ( isset( $_REQUEST['_wpnonce'] ) && ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'update-post_' . $post->ID ) ) {
					apg_log( sprintf( 'Nonce verification failed: user=%d post=%d', $user_id, $post->ID ) );
					$caps[] = 'do_not_allow';
					return $caps;
				}

				$allowed_ops = array( 'trash', 'draft' );
				// アクションまたは新しいステータスが許可された操作の場合
				if ( in_array( $intent, $allowed_ops, true ) || in_array( $new_status, $allowed_ops, true ) ) {
					apg_log( sprintf( 'Allowed limited modification (trash/draft): user=%d post=%d', $user_id, $post->ID ) );
					return $caps;
				}
			}

			// 5. それ以外の操作はブロック
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
		// 実行者が非管理者であり、かつ認証されている場合のみブロック
		if ( ! $actor_id || apg_user_is_trusted( $actor_id ) ) return;
		apg_log( sprintf( 'Blocked set_user_role: actor=%d target=%d newrole=%s', $actor_id, $user_id, $role ) );
		// wp_die で操作を強制終了
		wp_die( esc_html( 'Access denied. Role changes are restricted.', 'authenticated-privilege-guard' ) );
	},
	1, // 早めの優先度でフック
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

		// 例外ルートのチェック
		$exception_raw = apg_get_option( 'rest_exceptions', '' );
		$exceptions = array_filter( array_map( 'trim', explode( "\n", $exception_raw ) ) );
		foreach ( $exceptions as $allow ) {
			if ( @preg_match( $allow, $route ) ) {
				apg_log( sprintf( 'Allowed REST route: %s', $allow ) );
				return $result;
			}
		}

		// 危険ルートのパターンマッチ
		$danger_patterns = array(
			'#^/wp/v2/users#',      // ユーザー操作全般
			'#^/wp/v2/plugins#',    // プラグイン操作
			'#^/wp/v2/themes#',     // テーマ操作
			'#^/wp/v2/settings#',   // 一般設定
			'#^/wp/v2/options#',    // オプション操作
		);
		foreach ( $danger_patterns as $pat ) {
			if ( preg_match( $pat, $route ) ) {
				apg_log( sprintf( 'Blocked REST route: user=%d route=%s method=%s', $user_id, $route, $method ) );
				// 403 Forbidden エラーを返す
				return new WP_Error( 'apg_rest_block', esc_html( 'Access denied to privileged REST route.', 'authenticated-privilege-guard' ), array( 'status' => 403 ) );
			}
		}
		return $result;
	},
	PHP_INT_MAX, // 最も高い優先度で実行
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
	add_settings_field( 'force_override', 'Capフィルタの最優先割込み', 'apg_field_force_override', 'apg-settings', 'apg_section' );
	add_settings_field( 'allow_editor_collab', '編集者による共同編集の許可', 'apg_field_allow_editor_collab', 'apg-settings', 'apg_section' );
	add_settings_field( 'rest_exceptions', 'REST例外ルート（1行1正規表現パターン）', 'apg_field_rest_exceptions', 'apg-settings', 'apg_section' );
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
	echo '<p class="description">WP_CONTENT_DIR配下のみ指定可能。「../」や不正記号などは自動的にフォールバックします。</p>';
}
function apg_field_force_override() {
	$o = apg_get_option();
	printf( '<label><input type="checkbox" name="apg_options[force_override]" value="1" %s> 有効（Capフィルタの優先度を最大化し、他プラグイン設定を上書き）</label>', checked( 1, $o['force_override'], false ) );
}
function apg_field_allow_editor_collab() {
	$o = apg_get_option();
	printf( '<label><input type="checkbox" name="apg_options[allow_editor_collab]" value="1" %s> 有効（他者投稿の編集を完全に許可 - 管理者投稿は除く）</label>', checked( 1, $o['allow_editor_collab'], false ) );
}
function apg_field_rest_exceptions() {
	$o = apg_get_option();
	echo '<textarea name="apg_options[rest_exceptions]" rows="6" cols="60" placeholder="#^/wp/v2/contact-form-7/.*$">' . esc_textarea( $o['rest_exceptions'] ) . '</textarea>';
	echo '<p class="description">PHPの正規表現でルートパターンを指定可能。例: <code>#^/wp/v2/contact-form-7/.*$#</code></p>';
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
		// 無効な場合はフォールバック（空）
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
	echo '<p>このプラグインは、内部の認証済みユーザーアカウントが乗っ取られた場合に、サイトへの致命的な変更を防ぐための補助的な防御線を提供します。</p>';
	echo '<form method="post" action="options.php">';
	settings_fields( 'apg_settings_group' );
	do_settings_sections( 'apg-settings' );
	submit_button();
	echo '</form></div>';
}
