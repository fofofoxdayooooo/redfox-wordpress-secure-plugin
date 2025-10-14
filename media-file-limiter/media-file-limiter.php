<?php
/**
 * Plugin Name: Media File Limiter
 * Description: メディアファイルアップロードの最大サイズを制限し、危険な拡張子のファイルをブロックします。
 * Plugin URI: https://p-fox.jp/
 * Version: 1.0
 * Author: Red Fox(team Red Fox)
 * Author URI: https://p-fox.jp/
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: media-file-limiter
 * Requires at least: 6.8
 * Requires PHP: 7.4
 */

// 直接ファイルにアクセスされた場合の保護
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// プラグインオプションのプレフィックス
define( 'MFL_OPTION_PREFIX', 'mfl_');


// ----------------------------------------------------
// 1. 管理画面の設定とメニューの登録
// ----------------------------------------------------

add_action('admin_menu', 'mfl_add_admin_menu');
add_action('admin_init', 'mfl_settings_init');

/**
 * カスタム設定メニューを「設定」に追加
 */
function mfl_add_admin_menu() {
    add_options_page(
        esc_html__('メディアアップロード制限設定', 'media-file-limiter'),
        esc_html__('メディア制限', 'media-file-limiter'),
        'manage_options',
        'mfl-settings-page', // 設定ページのスラッグ
        'mfl_settings_page_callback'
    );
}

/**
 * 設定ページのコンテンツを表示
 */
function mfl_settings_page_callback() {
    // ユーザーに権限があるか確認
    if ( ! current_user_can('manage_options') ) {
        return;
    }
    
    // WordPressとPHPで許可されている最大アップロードサイズを取得（バイト単位）
    $max_size_bytes = wp_max_upload_size();
    $max_size_display = size_format( $max_size_bytes );

    // PHP/WPの制限値の情報を表示
    $php_limit_info = sprintf(
        /* translators: %s: Maximum upload size allowed by PHP/WordPress, e.g., 64MB */
        esc_html__('現在のPHP/WordPressの最大アップロード制限は %s です。これ以下の値を設定してください。', 'media-file-limiter'),
        '<strong>' . esc_html($max_size_display) . '</strong>'
    );

    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        
        <div class="notice notice-info">
            <p><?php echo wp_kses_post($php_limit_info); ?></p>
        </div>

        <form action="options.php" method="post">
            <?php
            // 設定グループ (mfl_options_group) の設定
            settings_fields('mfl_options_group');
            // セクションとフィールドの表示
            do_settings_sections('mfl-settings-page');
            // 保存ボタン
            submit_button(esc_html__('設定を保存', 'media-file-limiter'));
            ?>
        </form>
    </div>
    <?php
}

/**
 * 設定とフィールドを登録
 */
function mfl_settings_init() {
    $option_size = MFL_OPTION_PREFIX . 'max_upload_size';
    $option_exts = MFL_OPTION_PREFIX . 'forbidden_extensions';
    $option_mimes = MFL_OPTION_PREFIX . 'allowed_mime_types'; // 新しいMIMEタイプオプション

    // 1. 最大アップロードサイズ (MB) のオプションを登録
    register_setting('mfl_options_group', $option_size, array(
        'default'             => 8, // デフォルト8MB
        'type'                => 'integer',
        'sanitize_callback'   => 'absint', // 負の値を許容しない整数としてサニタイズ
        'show_in_rest'        => false,
    ));

    // 2. 禁止拡張子のリストを登録
    register_setting('mfl_options_group', $option_exts, array(
        'default'             => 'exe, php, phtml, html, shtml, js',
        'type'                => 'string',
        'sanitize_callback'   => 'mfl_sanitize_forbidden_extensions',
        'show_in_rest'        => false,
    ));

    // 3. 許可するMIMEタイプのリストを登録 (新規)
    register_setting('mfl_options_group', $option_mimes, array(
        'default'             => '', // デフォルトは空
        'type'                => 'string',
        'sanitize_callback'   => 'mfl_sanitize_allowed_mimes',
        'show_in_rest'        => false,
    ));


    // 設定セクションを登録
    add_settings_section(
        'mfl_main_section',
        esc_html__('メディアアップロード制限機能設定', 'media-file-limiter'),
        'mfl_section_callback',
        'mfl-settings-page'
    );

    // 設定フィールド (最大サイズ) を登録
    add_settings_field(
        $option_size,
        esc_html__('最大アップロードサイズ (MB)', 'media-file-limiter'),
        'mfl_max_size_callback',
        'mfl-settings-page',
        'mfl_main_section',
        array('label_for' => $option_size)
    );
    
    // 設定フィールド (禁止拡張子) を登録
    add_settings_field(
        $option_exts,
        esc_html__('禁止するファイル拡張子 (カンマ区切り)', 'media-file-limiter'),
        'mfl_forbidden_exts_callback',
        'mfl-settings-page',
        'mfl_main_section',
        array('label_for' => $option_exts)
    );

    // 設定フィールド (追加で許可するMIMEタイプ) を登録 (新規)
    add_settings_field(
        $option_mimes,
        esc_html__('追加で許可するMIMEタイプ (カンマ区切り)', 'media-file-limiter'),
        'mfl_allowed_mimes_callback',
        'mfl-settings-page',
        'mfl_main_section',
        array('label_for' => $option_mimes)
    );
}

/**
 * 禁止拡張子オプションのサニタイズ処理
 * @param string $input 入力された文字列
 * @return string サニタイズされた文字列
 */
function mfl_sanitize_forbidden_extensions($input) {
    // 基本的なテキストフィールドのサニタイズ
    $input = sanitize_text_field($input);
    // スペース削除、小文字化、空要素削除、重複削除
    $exts = explode(',', $input);
    $exts = array_map('trim', $exts);
    $exts = array_filter($exts); 
    $exts = array_map('strtolower', $exts);
    $exts = array_unique($exts);
    
    return implode(', ', $exts);
}

/**
 * 許可するMIMEタイプオプションのサニタイズ処理 (新規)
 * @param string $input 入力された文字列
 * @return string サニタイズされた文字列
 */
function mfl_sanitize_allowed_mimes($input) {
    // 基本的なテキストフィールドのサニタイズ
    $input = sanitize_text_field($input);
    // スペース削除、小文字化、空要素削除、重複削除
    $mimes = explode(',', $input);
    $mimes = array_map('trim', $mimes);
    $mimes = array_filter($mimes); 
    $mimes = array_map('strtolower', $mimes);
    $mimes = array_unique($mimes);
    
    return implode(', ', $mimes);
}

/**
 * 設定セクションの説明を表示
 */
function mfl_section_callback() {
    echo '<p>' . esc_html__('この設定は、WordPressのデフォルト機能より厳格にアップロードを制限します。アップロード処理の非常に早い段階で介入します。', 'media-file-limiter') . '</p>';
}

/**
 * 最大アップロードサイズ入力フィールドのHTML
 */
function mfl_max_size_callback() {
    $option_name = MFL_OPTION_PREFIX . 'max_upload_size';
    $limit_mb = get_option($option_name, 8);
    ?>
    <input type="number" 
           name="<?php echo esc_attr($option_name); ?>" 
           id="<?php echo esc_attr($option_name); ?>" 
           value="<?php echo absint($limit_mb); ?>" 
           min="1" 
           step="1"
           aria-describedby="mfl-size-description">
    <label for="<?php echo esc_attr($option_name); ?>">
        <?php esc_html_e('MB (メガバイト)', 'media-file-limiter'); ?>
    </label>
    <p class="description" id="mfl-size-description"><?php esc_html_e('ここで設定されたサイズ以上のファイルはアップロード時にブロックされます。', 'media-file-limiter'); ?></p>
    <?php
}

/**
 * 禁止拡張子リスト入力フィールドのHTML
 */
function mfl_forbidden_exts_callback() {
    $option_name = MFL_OPTION_PREFIX . 'forbidden_extensions';
    // データベースから取得した値をesc_textareaでエスケープ
    $forbidden_exts = get_option($option_name, 'exe, php, phtml, html, shtml, js');
    ?>
    <textarea name="<?php echo esc_attr($option_name); ?>" 
              id="<?php echo esc_attr($option_name); ?>" 
              rows="3" 
              cols="50" 
              class="large-text code"
              aria-describedby="mfl-exts-description"><?php echo esc_textarea($forbidden_exts); ?></textarea>
    <p class="description" id="mfl-exts-description"><?php esc_html_e('カンマ区切りで、アップロードを禁止したいファイル拡張子を小文字で入力してください。', 'media-file-limiter'); ?></p>
    <?php
}

/**
 * 追加で許可するMIMEタイプ入力フィールドのHTML (新規)
 */
function mfl_allowed_mimes_callback() {
    $option_name = MFL_OPTION_PREFIX . 'allowed_mime_types';
    // データベースから取得した値をesc_textareaでエスケープ
    $allowed_mimes = get_option($option_name, '');
    ?>
    <textarea name="<?php echo esc_attr($option_name); ?>" 
              id="<?php echo esc_attr($option_name); ?>" 
              rows="3" 
              cols="50" 
              class="large-text code"
              aria-describedby="mfl-mimes-description"><?php echo esc_textarea($allowed_mimes); ?></textarea>
    <p class="description" id="mfl-mimes-description">
        <?php esc_html_e('特定のプラグインや環境で必要となる、追加で許可したいMIMEタイプを正確にカンマ区切りで入力してください (例: application/epub+zip, font/woff2)。', 'media-file-limiter'); ?>
    </p>
    <?php
}


// ----------------------------------------------------
// 2. コアのアップロード制限ロジック
// ----------------------------------------------------

/**
 * WordPressの最大アップロードサイズをプラグインの設定値に制限する
 * @param int $max_size PHP/WordPressで許可されている最大アップロードサイズ（バイト）。
 * @return int 制限された最大アップロードサイズ（バイト）。
 */
add_filter('upload_size_limit', 'mfl_set_upload_size_limit');

function mfl_set_upload_size_limit($max_size) {
    // ファイルをアップロードする権限を持つユーザーにのみ適用
    if ( ! current_user_can('upload_files') ) {
        return $max_size;
    }
    
    $option_name = MFL_OPTION_PREFIX . 'max_upload_size';
    // 設定された最大サイズ (MB) を安全な整数として取得
    $limit_mb = (int) get_option($option_name, 8);
    
    // 設定値が0以下の場合はスキップ
    if ($limit_mb <= 0) {
        return $max_size;
    }

    $custom_limit_bytes = $limit_mb * 1024 * 1024; // MBをバイトに変換

    // PHP/WPの制限値とカスタム制限値のうち、小さい方を返す
    return min($max_size, $custom_limit_bytes);
}

/**
 * wp_handle_upload_prefilterフックに登録し、早期にアップロードチェックを実行する
 * 優先度 1: ほとんどの処理よりも早く介入するため
 */
add_filter('wp_handle_upload_prefilter', 'mfl_check_upload_pre_filter', 1);

/**
 * アップロードされたファイルに関するカスタムチェックを実行する
 * @param array $file アップロードされるファイルに関するデータ。
 * @return array 変更されたファイルデータ。エラーがあれば 'error' キーが含まれる。
 */
function mfl_check_upload_pre_filter($file) {
    // 既にエラーがある場合はスキップ
    if (isset($file['error']) && $file['error'] !== false) {
        return $file;
    }
    
    // 0. MIMEタイプ厳格チェック (finfoを使用)
    $file = mfl_strict_mime_type_check($file);
    if (isset($file['error'])) {
        return $file;
    }

    // 1. 危険な拡張子のチェック
    $file = mfl_block_forbidden_extensions($file);
    if (isset($file['error'])) {
        return $file;
    }

    // 2. ファイルサイズのチェック (拡張子チェック後に実行)
    $file = mfl_limit_file_size($file);
    if (isset($file['error'])) {
        return $file;
    }
    
    return $file;
}

/**
 * ファイルのMIMEタイプを厳格に検証し、許可されたタイプのみを許可する (セキュリティ強化)。
 *
 * @param array $file アップロードされるファイルに関するデータ。
 * @return array 変更されたファイルデータ。エラーがあれば 'error' キーが含まれる。
 */
function mfl_strict_mime_type_check($file) {
    // finfo拡張機能がない場合はスキップ（PHP 5.3以降では通常有効）
    if ( ! extension_loaded('fileinfo') || ! isset($file['tmp_name']) || ! is_uploaded_file($file['tmp_name']) ) {
        return $file;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    
    if ( ! $finfo ) {
        // finfo_openが失敗した場合もスキップ
        return $file;
    }

    $tmp_name = $file['tmp_name'];
    $mime = finfo_file($finfo, $tmp_name);
    finfo_close($finfo);

    // 許可されたカスタムMIMEタイプを取得し、配列に変換
    $allowed_mimes_str = get_option(MFL_OPTION_PREFIX . 'allowed_mime_types', '');
    $custom_allowed_mimes = array_map('trim', explode(',', strtolower($allowed_mimes_str)));
    $custom_allowed_mimes = array_filter($custom_allowed_mimes);


    // 許可条件: 
    // 1. 標準カテゴリ（image, video, audio, text）に属するか、
    // 2. カスタム設定リストに含まれる
    if (preg_match('/^(image|video|audio|text)\//', $mime) || in_array($mime, $custom_allowed_mimes, true)) {
        return $file;
    }

    // 上記のいずれにも該当しない場合はブロック
    $error_message = sprintf(
        /* translators: %s: Detected MIME type */
        esc_html__('検出されたMIMEタイプ「%s」はセキュリティ上の理由により許可されていません。', 'media-file-limiter'),
        esc_html($mime)
    );
    $file['error'] = $error_message;
    return $file;
}

/**
 * 危険なファイル拡張子をブロックする (より厳格なチェックを適用)
 *
 * NOTE: この関数内で拡張子は**小文字に正規化**されるため、大文字・小文字を使った拡張子偽装（例: 'JpG'）は確実にブロックされます。
 *
 * @param array $file アップロードされるファイルに関するデータ。
 * @return array 変更されたファイルデータ。エラーがあれば 'error' キーが含まれる。
 */
function mfl_block_forbidden_extensions($file) {
    $option_name = MFL_OPTION_PREFIX . 'forbidden_extensions';
    // データベースからサニタイズされた禁止リスト文字列を取得
    $forbidden_list_str = get_option($option_name, 'exe, php, phtml, html, shtml, js');

    // 禁止リストを処理: 小文字化し、トリミングし、空の要素を削除
    $forbidden_list_array = array_map('trim', explode(',', $forbidden_list_str));
    $forbidden_list_array = array_map('strtolower', $forbidden_list_array);
    $forbidden_list_array = array_filter($forbidden_list_array);

    // ファイル拡張子の取得と小文字化 (ここで大文字・小文字の偽装を防ぐ)
    $file_info = wp_check_filetype( $file['name'] );
    $ext = isset( $file_info['ext'] ) ? strtolower( $file_info['ext'] ) : '';

    // wp_check_filetype()が拡張子を認識できない場合のフォールバック
    if ( empty( $ext ) ) {
        $ext = pathinfo( $file['name'], PATHINFO_EXTENSION );
        $ext = strtolower( $ext ); // ここでも確実に小文字化
    }

    if ( in_array($ext, $forbidden_list_array, true) ) {
        // エラーメッセージを作成
        $error_message = sprintf(
            /* translators: %s: The forbidden file extension, e.g., 'exe' */
            esc_html__('セキュリティ上の理由により、ファイル拡張子「.%s」のアップロードは許可されていません。', 'media-file-limiter'),
            esc_html($ext)
        );

        $file['error'] = $error_message;
        return $file;
    }
    
    return $file;
}

/**
 * ファイルサイズを制限する
 *
 * @param array $file アップロードされるファイルに関するデータ。
 * @return array 変更されたファイルデータ。エラーがあれば 'error' キーが含まれる。
 */
function mfl_limit_file_size($file) {
    $option_name = MFL_OPTION_PREFIX . 'max_upload_size';
    // 設定された最大サイズ (MB) を安全な整数として取得
    $limit_mb = (int) get_option($option_name, 8);
    
    // 設定が無効な値(0以下)の場合はチェックをスキップ
    if ($limit_mb <= 0) {
        return $file;
    }

    // $file['size']が存在しない場合はfilesize()で取得を試みる
    $file_size_bytes = (int) ($file['size'] ?? 0);
    
    if ($file_size_bytes === 0 && isset($file['file']) && file_exists($file['file'])) {
        $file_size_bytes = filesize($file['file']);
    }

    $limit_bytes = $limit_mb * 1024 * 1024; // MBをバイトに変換

    if ($file_size_bytes > $limit_bytes) {
        // エラーメッセージを作成
        $error_message = sprintf(
            /* translators: 1: uploaded file size (e.g., 10.5MB), 2: maximum allowed size (e.g., 8MB) */
            esc_html__('ファイルサイズが制限を超えています。アップロードされたファイルサイズ: %1$s、許可されている最大サイズ: %2$s。', 'media-file-limiter'),
            esc_html(size_format($file_size_bytes)),
            esc_html(size_format($limit_bytes))
        );

        $file['error'] = $error_message;
        return $file;
    }

    return $file;
}

// wp_handle_uploadフックに登録し、ファイルがサーバーに保存された直後に再チェックを実行する
// 優先度 1: ほとんどの処理よりも早く介入するため
add_filter('wp_handle_upload', 'mfl_recheck_after_upload', 1, 2);

/**
 * ファイル保存直後にセキュリティチェックを再実行し、問題があれば削除する (最終防衛線)。
 *
 * エラーが検出された場合、ファイルを強制的に削除してからエラーを返す。
 *
 * @param array $file アップロード後にWordPressが返すファイルデータ (file, url, type, error)。
 * @param string $context アップロードコンテキスト。
 * @return array 変更されたファイルデータ。エラーがあれば 'error' キーが含まれる。
 */
function mfl_recheck_after_upload($file, $context) {
    // 既にエラーがある場合はスキップ
    if (isset($file['error']) && $file['error'] !== false) {
        return $file;
    }
    
    // 再チェックのために、既存のチェック関数が期待する配列形式に近い構造を作成
    $recheck_file = array(
        'name'     => basename($file['file']), 
        'file'     => $file['file'], 
        'error'    => 0,
        'size'     => 0, 
    );

    $error_detected = false;
    $error_message = '';

    // 1. 危険な拡張子の再チェック 
    $recheck_file = mfl_block_forbidden_extensions($recheck_file);
    if (isset($recheck_file['error']) && $recheck_file['error'] !== 0) {
        $error_detected = true;
        $error_message = $recheck_file['error'];
    }

    // 2. ファイルサイズの再チェック
    if (!$error_detected) {
        $recheck_file = mfl_limit_file_size($recheck_file);
        if (isset($recheck_file['error']) && $recheck_file['error'] !== 0) {
            $error_detected = true;
            $error_message = $recheck_file['error'];
        }
    }

    // エラーが検出された場合、ファイルを削除し、エラーメッセージを設定
    if ($error_detected) {
        // ファイルを強制削除 (wp_delete_file()は規約適合関数)
        if ( function_exists( 'wp_delete_file' ) && file_exists( $file['file'] ) ) {
            wp_delete_file( $file['file'] );
        } else {
            // wp_delete_fileが利用できない場合のフォールバック
            @unlink($file['file']); 
        }
        
        // エラーを返す
        $file['error'] = $error_message;
        return $file;
    }

    return $file;
}


// --------------------------------------------------------
// 3. アンインストール時の処理 (クリーンアップ)
// --------------------------------------------------------

/**
 * プラグイン削除時にデータベースのオプションをクリーンアップする
 */
function mfl_uninstall_cleanup() {
    delete_option( MFL_OPTION_PREFIX . 'max_upload_size' );
    delete_option( MFL_OPTION_PREFIX . 'forbidden_extensions' );
    delete_option( MFL_OPTION_PREFIX . 'allowed_mime_types' ); // 新しいオプションを削除
}
// プラグイン削除時に実行する関数を登録
register_uninstall_hook( __FILE__, 'mfl_uninstall_cleanup' );