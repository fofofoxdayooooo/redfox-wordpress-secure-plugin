<?php
/**
 * Plugin Name: Admin Ajax Blocker
 * Description: 非ログインユーザーによる「wp-admin/admin-ajax.php」へのアクセスをグローバルにブロックします。エラーコードやメッセージ、HTTPステータスコードを設定画面で自由にカスタマイズできます。
 * Plugin URI: https://p-fox.jp/
 * Version: 1.0
 * Author: Red Fox(team Red Fox)
 * Author URI: https://p-fox.jp/
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: admin-ajax-blocker
 * Requires at least: 6.8
 * Requires PHP: 7.4
 */

// 直接ファイルにアクセスされた場合の保護
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// --------------------------------------------------------
// 1. 管理画面に設定項目を追加 (設定 > ディスカッション)
// --------------------------------------------------------

/**
 * 設定メニューの登録とフィールドの追加
 */
function aab_register_settings() {
    // 1. ブロック機能の有効/無効
    register_setting(
        'discussion',
        'aab_enable_block',
        array(
            'type'            => 'boolean',
            'sanitize_callback' => 'intval',
            'default'         => 0,
        )
    );

    // 2. HTTP ステータスコード
    register_setting(
        'discussion',
        'aab_status_code',
        array(
            'type'            => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'         => '403',
        )
    );

    // 3. JSON エラーコード
    register_setting(
        'discussion',
        'aab_error_code',
        array(
            'type'            => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'         => 'login_required',
        )
    );

    // 4. JSON エラーメッセージ
    register_setting(
        'discussion',
        'aab_error_message',
        array(
            'type'            => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'         => 'Access Forbidden. Login is required.',
        )
    );

    // 5. 【新規】ホワイトリストアクション
    register_setting(
        'discussion',
        'aab_whitelisted_actions',
        array(
            'type'            => 'string',
            // カンマ区切りのリストとしてサニタイズ
            'sanitize_callback' => 'aab_sanitize_whitelisted_actions',
            'default'         => '',
        )
    );


    // 設定セクションを追加
    add_settings_section(
        'aab_blocker_section',
        esc_html__('Admin AJAX ブロック設定', 'admin-ajax-blocker'),
        'aab_blocker_section_callback',
        'discussion'
    );

    // 1. 有効/無効チェックボックス
    add_settings_field(
        'aab_enable_block_field',
        esc_html__('ブロック機能の有効化', 'admin-ajax-blocker'),
        'aab_enable_block_field_callback',
        'discussion',
        'aab_blocker_section'
    );

    // 5. 【新規】ホワイトリストアクションフィールド
    add_settings_field(
        'aab_whitelisted_actions_field',
        esc_html__('ホワイトリストアクション', 'admin-ajax-blocker'),
        'aab_whitelisted_actions_field_callback',
        'discussion',
        'aab_blocker_section'
    );

    // 2. HTTP ステータスコード
    add_settings_field(
        'aab_status_code_field',
        esc_html__('HTTPステータスコード', 'admin-ajax-blocker'),
        'aab_status_code_field_callback',
        'discussion',
        'aab_blocker_section'
    );

    // 3. JSON エラーコード
    add_settings_field(
        'aab_error_code_field',
        esc_html__('JSONエラーコード', 'admin-ajax-blocker'),
        'aab_error_code_field_callback',
        'discussion',
        'aab_blocker_section'
    );
    
    // 4. JSON エラーメッセージ
    add_settings_field(
        'aab_error_message_field',
        esc_html__('JSONエラーメッセージ', 'admin-ajax-blocker'),
        'aab_error_message_field_callback',
        'discussion',
        'aab_blocker_section'
    );
}
add_action('admin_init', 'aab_register_settings');

/**
 * ホワイトリストアクションのサニタイズコールバック
 * カンマ区切りリストを受け取り、安全な文字列に整形します。
 * @param string $input 入力値
 * @return string サニタイズされた値
 */
function aab_sanitize_whitelisted_actions($input) {
    // カンマ、スペース、改行などを区切り文字とし、アクション名として有効な文字のみを残す
    $actions = explode(',', $input);
    $sanitized_actions = array_map(function($action) {
        // アクション名はキーとしてサニタイズするのが適切
        return sanitize_key(trim($action)); 
    }, $actions);

    // 空の要素を取り除き、カンマとスペース区切りで戻す
    return implode(', ', array_filter($sanitized_actions));
}


/**
 * セクションの説明を表示
 */
function aab_blocker_section_callback() {
    echo '<p>' . esc_html__('非ログインユーザーが admin-ajax.php にアクセスした際の動作を制御します。ホワイトリストに登録されていないアクションはブロックされます。', 'admin-ajax-blocker') . '</p>';
}

/**
 * 有効/無効チェックボックスのHTML
 */
function aab_enable_block_field_callback() {
    $option = get_option('aab_enable_block');
    $checked = checked(1, $option, false);

    echo '<label for="aab_enable_block">';
    echo '<input type="checkbox" id="aab_enable_block" name="aab_enable_block" value="1" ' . esc_attr( $checked ) . '/>';
    echo esc_html__('非ログインユーザーによるadmin-ajax.phpへのアクセスをブロックする', 'admin-ajax-blocker');
    echo '</label>';
    echo '<p class="description">' . esc_html__('チェックを入れると、未ログイン状態でのadmin-ajax.phpへのリクエストは、ホワイトリストに記載されたアクションを除き、全てカスタムエラーで終了します。', 'admin-ajax-blocker') . '</p>';
}

/**
 * HTTP ステータスコードのテキスト入力
 */
function aab_status_code_field_callback() {
    $option = get_option('aab_status_code', '403');
    echo '<input type="text" id="aab_status_code" name="aab_status_code" value="' . esc_attr($option) . '" class="regular-text" placeholder="例: 403" />';
    echo '<p class="description">' . esc_html__('サーバーが返すHTTPステータスコード（例: 403, 401, 503など）を自由に入力してください。', 'admin-ajax-blocker') . '</p>';
}

/**
 * JSON エラーコードのテキスト入力
 */
function aab_error_code_field_callback() {
    $option = get_option('aab_error_code', 'login_required');
    echo '<input type="text" id="aab_error_code" name="aab_error_code" value="' . esc_attr($option) . '" class="regular-text" />';
    echo '<p class="description">' . esc_html__('JSONレスポンスに含まれるエラーの識別コード（例: login_required）。', 'admin-ajax-blocker') . '</p>';
}

/**
 * JSON エラーメッセージのテキスト入力
 */
function aab_error_message_field_callback() {
    $option = get_option('aab_error_message', 'Access Forbidden. Login is required.');
    echo '<input type="text" id="aab_error_message" name="aab_error_message" value="' . esc_attr($option) . '" class="regular-text" />';
    echo '<p class="description">' . esc_html__('JSONレスポンスに含まれるエラーメッセージ。', 'admin-ajax-blocker') . '</p>';
}

/**
 * 【新規】ホワイトリストアクションのテキスト入力
 */
function aab_whitelisted_actions_field_callback() {
    $option = get_option('aab_whitelisted_actions', '');
    echo '<input type="text" id="aab_whitelisted_actions" name="aab_whitelisted_actions" value="' . esc_attr($option) . '" class="large-text" placeholder="例: woocommerce_add_to_cart, my_public_form_submit" />';
    echo '<p class="description">' . esc_html__('非ログインユーザーからのアクセスを許可するAJAXアクション名（$_POST[\'action\']の値）をカンマ区切りで入力してください。', 'admin-ajax-blocker') . '</p>';
}


// --------------------------------------------------------
// 2. コアのAJAXブロックロジック (更新済み)
// --------------------------------------------------------

/**
 * 非ログインユーザーによるadmin-ajax.phpへのアクセスをブロック
 */
function aab_block_admin_ajax() {
    // 1. AJAXリクエストでなければ終了
    if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
        return;
    }

    // 2. ブロック機能が無効なら終了
    if ( ! get_option( 'aab_enable_block', 0 ) ) {
        return;
    }

    // 3. ユーザーがログインしていれば終了 (ブロックしない)
    if ( is_user_logged_in() ) {
        return;
    }
    
    // 4. 【ホワイトリストチェック】: 許可されたアクションであればブロックせずに終了
    // Nonce検証が必要なのは状態を変更するアクションハンドラ側であり、
    // このブロック関数はアクション名を取得して判断するだけで状態を変更しないため、Nonce検証を省略します。
    // phpcs:disable WordPress.Security.NonceVerification.Missing
    $requested_action = isset( $_POST['action'] ) ? sanitize_key( $_POST['action'] ) : '';
    // phpcs:enable

    if ( $requested_action ) {
        $whitelisted_actions_string = get_option( 'aab_whitelisted_actions', '' );
        // カンマ区切り文字列を配列に変換し、スペースなどをトリム
        $whitelisted_actions = array_map( 'trim', explode( ',', $whitelisted_actions_string ) );
        $whitelisted_actions = array_filter( $whitelisted_actions ); // 空要素を除去

        // リクエストされたアクションがホワイトリストに含まれていれば、ブロックせずに処理を継続
        if ( in_array( $requested_action, $whitelisted_actions, true ) ) {
            return; 
        }
    }
    
    // 5. 非ログインユーザー、機能有効、ホワイトリスト外なのでブロック処理を実行
    $status_code = (int) get_option( 'aab_status_code', 403 );
    $error_code = get_option( 'aab_error_code', 'login_required' );
    $error_message = get_option( 'aab_error_message', 'Access Forbidden. Login is required.' );
    
    // wp_send_json_error を使用して、カスタムエラーコードとHTTPステータスコードを返す
    wp_send_json_error( 
        [ 
            'code'      => $error_code, 
            'message'   => $error_message,
            // 互換性のため、ステータスコードは 'data' 内部にも含める (ユーザーの元の構造を維持)
            'data'      => ['status' => $status_code], 
        ], 
        $status_code 
    );
    
    // 念のため exit も残しておく
    exit;
}
// initの非常に早い段階 (優先度1) で実行し、他のAJAXアクションがトリガーされる前にブロックします
add_action( 'init', 'aab_block_admin_ajax', 1 );


// --------------------------------------------------------
// 3. アンインストール時の処理 (クリーンアップ) (更新済み)
// --------------------------------------------------------

/**
 * プラグイン削除時にデータベースのオプションをクリーンアップする
 */
function aab_uninstall_cleanup() {
    delete_option( 'aab_enable_block' );
    delete_option( 'aab_status_code' );
    delete_option( 'aab_error_code' );
    delete_option( 'aab_error_message' );
    delete_option( 'aab_whitelisted_actions' ); // 新しいオプションを追加
}
register_uninstall_hook( __FILE__, 'aab_uninstall_cleanup' );
