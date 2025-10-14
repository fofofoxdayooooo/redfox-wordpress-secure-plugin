<?php
/**
 * Plugin Name: Upload File Renamer (アップロードファイル名強制リネーム)
 * Description: アップロード時にファイル名を強制的にリネームします。リネームの有無と形式（date()関数書式）を管理画面で設定できます。
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

// 直接ファイルにアクセスされた場合の保護
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ----------------------------------------------------
// 1. 管理画面の設定とメニューの登録
// ----------------------------------------------------

add_action('admin_menu', 'mfn_add_admin_menu');
add_action('admin_init', 'mfn_settings_init');

/**
 * カスタム設定メニューを「設定」に追加
 */
function mfn_add_admin_menu() {
    add_options_page(
        esc_html__('ファイルリネーム設定', 'move-file-name'),
        esc_html__('ファイルリネーム', 'move-file-name'),
        'manage_options',
        'mfn-settings-page', // 設定ページのスラッグ
        'mfn_settings_page_callback'
    );
}

/**
 * 設定ページのコンテンツを表示
 */
function mfn_settings_page_callback() {
    // ユーザーに権限があるか確認
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form action="options.php" method="post">
            <?php
            // 設定グループ (mfn_options_group) の設定
            settings_fields('mfn_options_group');
            // セクションとフィールドの表示
            do_settings_sections('mfn-settings-page');
            // 保存ボタン
            submit_button(esc_html__('設定を保存', 'move-file-name'));
            ?>
        </form>
    </div>
    <?php
}

/**
 * 設定とフィールドを登録
 */
function mfn_settings_init() {
    $option_enabled        = 'mfn_enable_rename';
    $option_format         = 'mfn_rename_format';
    $option_forbidden      = 'mfn_forbidden_extensions';

    // 1. リネーム有効/無効のオプションを登録
    register_setting('mfn_options_group', $option_enabled, array(
        'default'             => 1, // デフォルト値はオン (1)
        'type'                => 'boolean',
        'sanitize_callback'   => 'intval',
        'show_in_rest'        => false,
    ));

    // 2. ファイル名フォーマットのオプションを登録
    register_setting('mfn_options_group', $option_format, array(
        'default'             => 'Ymd_His_', // デフォルト値: 例: 20251011_182800_
        'type'                => 'string',
        'sanitize_callback'   => 'sanitize_text_field', // テキストフィールドのサニタイズ
        'show_in_rest'        => false,
    ));

    // 3. 禁止拡張子のオプションを登録
    register_setting('mfn_options_group', $option_forbidden, array(
        // 一般的に危険な拡張子をデフォルトで設定
        'default'             => 'exe, php, phtml, html, shtml, js', 
        'type'                => 'string',
        'sanitize_callback'   => 'mfn_sanitize_forbidden_extensions', // カスタムサニタイズ
        'show_in_rest'        => false,
    ));


    // 設定セクションを登録
    add_settings_section(
        'mfn_main_section',
        esc_html__('アップロードファイル名強制リネーム設定', 'move-file-name'),
        'mfn_section_callback',
        'mfn-settings-page'
    );

    // 設定フィールド (チェックボックス) を登録
    add_settings_field(
        $option_enabled,
        esc_html__('リネームを強制する', 'move-file-name'),
        'mfn_toggle_callback',
        'mfn-settings-page',
        'mfn_main_section',
        array('label_for' => $option_enabled)
    );

    // 設定フィールド (フォーマット入力) を登録
    add_settings_field(
        $option_format,
        esc_html__('リネーム形式', 'move-file-name'),
        'mfn_format_callback',
        'mfn-settings-page',
        'mfn_main_section',
        array('label_for' => $option_format)
    );
    
    // 設定フィールド (禁止拡張子入力) を登録
    add_settings_field(
        $option_forbidden,
        esc_html__('禁止拡張子 (アップロードブロック)', 'move-file-name'),
        'mfn_forbidden_callback',
        'mfn-settings-page',
        'mfn_main_section',
        array('label_for' => $option_forbidden)
    );
}

/**
 * 禁止拡張子リストをサニタイズするカスタムコールバック
 * カンマ区切りリストを受け取り、安全な小文字の拡張子リストに整形する。
 * @param string $input 入力値
 * @return string サニタイズされた値
 */
function mfn_sanitize_forbidden_extensions($input) {
    // 入力を小文字に変換
    $input = strtolower($input);
    // カンマ、スペース、改行などを区切り文字として分割
    $extensions = array_map('trim', explode(',', $input));
    
    $sanitized_extensions = array_map(function($ext) {
        // 拡張子として適切な文字（アルファベット、数字）のみを許可し、それ以外を除去
        return preg_replace('/[^a-z0-9]/', '', $ext);
    }, $extensions);

    // 空の要素を取り除き、カンマとスペース区切りで戻す
    return implode(', ', array_filter($sanitized_extensions));
}

/**
 * 設定セクションの説明を表示
 */
function mfn_section_callback() {
    echo '<p>' . esc_html__('ファイルをアップロードする際に、ファイル名をユニークなIDに強制的にリネームするかどうかと、その命名規則を設定します。デフォルトはオンです。リネームが有効な場合、メディアの「名前」（タイトル）も自動で匿名化されます。', 'move-file-name') . '</p>';
}

/**
 * 有効/無効チェックボックスのHTML
 */
function mfn_toggle_callback() {
    $option_name = 'mfn_enable_rename';
    $is_enabled = get_option($option_name, 1);
    ?>
    <input type="checkbox" name="<?php echo esc_attr($option_name); ?>" id="<?php echo esc_attr($option_name); ?>" value="1" <?php checked(1, $is_enabled); ?>>
    <label for="<?php echo esc_attr($option_name); ?>">
        <?php esc_html_e('オンにすると、ファイル名はアップロード時に指定された形式に置き換えられ、メディアの「名前」も匿名化されます。', 'move-file-name'); ?>
    </label>
    <?php
}

/**
 * テキストフィールド (リネーム形式) の表示
 */
function mfn_format_callback() {
    $option_name = 'mfn_rename_format';
    $format = get_option($option_name, 'Ymd_His_');
    ?>
    <input type="text" name="<?php echo esc_attr($option_name); ?>" id="<?php echo esc_attr($option_name); ?>" value="<?php echo esc_attr($format); ?>" class="regular-text">
    <p class="description">
        <?php esc_html_e('リネーム後のファイル名の接頭辞に使用する形式を', 'move-file-name'); ?>**PHPのdate()関数**<?php esc_html_e('の書式で指定します。例:', 'move-file-name'); ?> <code>Y-m-d-U-</code>
        <br>
        <?php esc_html_e('ファイル名はこの接頭辞の後にユニークIDが追加されます。（例:', 'move-file-name'); ?> <code><?php echo esc_html(gmdate($format)); ?>...</code>）
    </p>
    <?php
}

/**
 * テキストフィールド (禁止拡張子) の表示
 */
function mfn_forbidden_callback() {
    $option_name = 'mfn_forbidden_extensions';
    $forbidden_list = get_option($option_name, 'exe, php, phtml, html, shtml, js');
    ?>
    <input type="text" name="<?php echo esc_attr($option_name); ?>" id="<?php echo esc_attr($option_name); ?>" value="<?php echo esc_attr($forbidden_list); ?>" class="regular-text large-text">
    <p class="description">
        <?php esc_html_e('アップロードを明示的に禁止する拡張子をカンマ区切りで入力してください。', 'move-file-name'); ?>
        <br>
        <?php esc_html_e('デフォルトのリスト: ', 'move-file-name'); ?> <code>exe, php, phtml, html, shtml, js</code>
    </p>
    <?php
}


// ----------------------------------------------------
// 2. コアのファイルリネームロジック
// ----------------------------------------------------

add_filter('wp_handle_upload_prefilter', 'mfn_force_rename_uploaded_file');

/**
 * アップロードされるファイルのファイル名を強制的にリネームする
 *
 * 設定がオンの場合のみ実行されます。
 * @param array $file アップロードされるファイルに関するデータ。
 * @return array 変更されたファイルデータ、またはエラー情報を含むデータ。
 */
function mfn_force_rename_uploaded_file($file) {
    $option_enabled = 'mfn_enable_rename';
    $option_format = 'mfn_rename_format';

    // 1. 設定を取得。デフォルトはオン(1)
    $is_enabled = (int) get_option($option_enabled, 1);

    // 2. 設定がオフの場合は処理をスキップ
    if (!$is_enabled) {
        return $file;
    }

    // 3. ファイル名と拡張子を取得
    $info = pathinfo($file['name']);
    // 拡張子を小文字で取得 (チェック用)
    $ext = isset($info['extension']) ? strtolower($info['extension']) : ''; 

    // --- プラグイン/テーマアップロード時のリネーム禁止 ---
    // 拡張子が'zip'の場合は、プラグインやテーマのインストールとみなし、リネームをスキップ
    if ( 'zip' === $ext ) {
        return $file;
    }
    // --- プラグイン/テーマアップロード時のリネーム禁止 (ここまで) ---

    // --- 禁止拡張子のチェック ---
    $forbidden_list_string = get_option('mfn_forbidden_extensions', 'exe, php, phtml, html, shtml, js');
    $forbidden_extensions = array_map('trim', explode(',', strtolower($forbidden_list_string)));
    
    // 拡張子チェック
    if ($ext && in_array($ext, $forbidden_extensions, true)) {
        // アップロードをブロックし、エラーメッセージを返す
        /* translators: %s: Forbidden file extension (e.g., 'exe') */
        $file['error'] = sprintf( esc_html__('セキュリティ上の理由により、ファイル拡張子「.%s」のアップロードは禁止されています。', 'move-file-name'), $ext );
        // エラー情報を持った $file を返すことで、WordPressのアップロード処理が中断されます
        return $file;
    }
    // --- 禁止拡張子のチェック (ここまで) ---

    // 4. カスタムリネーム形式を取得。デフォルト値は 'Ymd_His_'
    $format_string = get_option($option_format, 'Ymd_His_');

    // 5. 新しいユニークなファイル名を生成
    // 拡張子が存在する場合のみドットを付ける (リネーム用)
    $ext_with_dot = $ext ? '.' . $ext : '';
    
    // タイムゾーン設定の影響を受けない gmdate() を使用して、常に UTC に基づいた命名を行う
    $date_prefix = gmdate($format_string);
    // gmdate() + uniqid() を組み合わせて衝突の可能性を極限まで低くする
    $random = bin2hex(random_bytes(3)); // 6桁程度の安全なランダム文字列
    $new_filename_base = $date_prefix . uniqid('') . '_' . $random;
    $new_filename = $new_filename_base . $ext_with_dot;

    // 6. ファイル名を置き換え
    $file['name'] = $new_filename;

    return $file;
}

// --------------------------------------------------------
// 3. メディアのタイトル (post_title) をリネームするロジック
// --------------------------------------------------------

add_filter('wp_insert_post_data', 'mfn_sanitize_attachment_title', 10, 2);

/**
 * アップロードされたメディアのタイトル (post_title) をサニタイズする
 *
 * ファイル名と同じく、アップロード時に設定される post_title からも
 * 個人情報や元のファイル名に関する情報を排除します。
 * post_name (スラッグ) もサニタイズします。
 *
 * @param array $data 投稿データ配列。
 * @param array $postarr 投稿データ。
 * @return array 変更された投稿データ配列。
 */
function mfn_sanitize_attachment_title($data, $postarr) {
    // 添付ファイル (attachment) のみが対象
    if ('attachment' !== $data['post_type']) {
        return $data;
    }

    // ファイル名リネーム設定が有効な場合のみ実行
    $is_enabled = (int) get_option('mfn_enable_rename', 1);
    if (!$is_enabled) {
        return $data;
    }

    // post_title を新しい匿名化されたタイトルに置き換える
    // このタイトルは、メディアライブラリの「名前」として表示されます。
    // 例: "メディアファイル 2025-10-11 22:35"
    /* translators: %s: Current date and time (e.g., 2025-10-11 22:35:00) */
    $new_title_format = esc_html__('メディアファイル %s', 'move-file-name');
    
    // 現在のローカライズされた日時を使用
    $date_string = date_i18n( 'Y-m-d H:i:s', current_time( 'timestamp', 0 ) );

    // 新しいタイトルを構築
    $new_title = sprintf( $new_title_format, $date_string );

    // post_title を上書き（メディアライブラリの「名前」）
    $data['post_title'] = $new_title;
    
    // post_name (スラッグ) も上書きして、元のファイル名がスラッグとして残るのを防ぐ
    $data['post_name'] = sanitize_title( $new_title );

    return $data;
}


// --------------------------------------------------------
// 4. アンインストール時の処理 (クリーンアップ)
// --------------------------------------------------------

/**
 * プラグイン削除時にデータベースのオプションをクリーンアップする
 */
function mfn_uninstall_cleanup() {
    delete_option( 'mfn_enable_rename' );
    delete_option( 'mfn_rename_format' );
    delete_option( 'mfn_forbidden_extensions' ); // 禁止拡張子オプションの削除
}
// プラグイン削除時に実行する関数を登録
register_uninstall_hook( __FILE__, 'mfn_uninstall_cleanup' );
