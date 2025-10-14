<?php
/**
 * Plugin Name: Page Comment Controller
 * Description: すべての固定ページ（Page）に対するコメント投稿をグローバル設定で一括制御し、スパムリスクを低減します。
 * Version: 1.0
 * Author: Red Fox(team Red Fox) 
 * Author URI: https://p-fox.jp/
 * License: GPLv2 or later
 * Text Domain: page-comment-controller
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
function custom_page_comments_register_settings() {
    // オプション名: disable_page_comments_globally
    register_setting(
        'discussion', // ディスカッション設定グループに追加
        'disable_page_comments_globally',
        array(
            'type' => 'boolean',
            'sanitize_callback' => 'intval',
            'default' => 0,
        )
    );

    // セクションを追加
    add_settings_section(
        'custom_page_comments_section',
        '固定ページコメント設定',
        'custom_page_comments_section_callback',
        'discussion'
    );

    // 設定フィールド（チェックボックス）を追加
    add_settings_field(
        'disable_page_comments_field',
        '固定ページのコメント投稿',
        'custom_page_comments_field_callback',
        'discussion',
        'custom_page_comments_section'
    );
}
add_action('admin_init', 'custom_page_comments_register_settings');

/**
 * セクションの説明を表示
 */
function custom_page_comments_section_callback() {
    // 修正: 出力テキストをesc_html__()でエスケープ
    echo '<p>' . esc_html__('固定ページ（post type: page）へのコメント投稿機能の可否をグローバルに制御します。コメント投稿フォームが表示されていなくても、この設定を無効化（チェックを入れる）することでスパム投稿のリスクを低減できます。', 'page-comment-controller') . '</p>';
}

/**
 * 設定フィールド（チェックボックス）のHTMLを出力
 */
function custom_page_comments_field_callback() {
    $option = get_option('disable_page_comments_globally');
    $checked = checked(1, $option, false);

    echo '<label for="disable_page_comments_globally">';
    // 修正箇所: $checked (HTML属性値) をesc_attr()でエスケープ
    echo '<input type="checkbox" id="disable_page_comments_globally" name="disable_page_comments_globally" value="1" ' . esc_attr( $checked ) . '/>';
    // 修正: 出力テキストをesc_html__()でエスケープ
    echo esc_html__('すべての固定ページでのコメント投稿を', 'page-comment-controller') . '<b>' . esc_html__('無効', 'page-comment-controller') . '</b>' . esc_html__('にする（推奨。チェックを入れるとコメント投稿ができなくなります）', 'page-comment-controller');
    echo '</label>';
}


// --------------------------------------------------------
// 2. comments_open フィルターでコメント投稿の可否を制御
// --------------------------------------------------------

/**
 * 固定ページへのコメント投稿可否をグローバル設定で制御
 */
function custom_control_page_comments($open, $post_id) {
    // 投稿IDがない場合や、管理画面での実行時はスキップ
    if (!$post_id || is_admin()) {
        return $open;
    }

    $post_type = get_post_type($post_id);
    $is_disabled = get_option('disable_page_comments_globally');

    // 固定ページであり、かつグローバル設定で無効化がチェックされている場合
    if ($post_type === 'page' && $is_disabled) {
        // コメント投稿を強制的に無効（false）にする
        return false;
    }

    // それ以外の場合は元の状態を維持
    return $open;
}
add_filter('comments_open', 'custom_control_page_comments', 99, 2);


// --------------------------------------------------------
// 3. アンインストール時の処理 (クリーンアップ)
// --------------------------------------------------------

/**
 * プラグイン削除時にデータベースのオプションをクリーンアップする
 */
function page_comment_controller_uninstall_cleanup() {
    delete_option( 'disable_page_comments_globally' );
}
register_uninstall_hook( __FILE__, 'page_comment_controller_uninstall_cleanup' );