<?php
/**
 * Plugin Name: Remove Image Exif Data
 * Description: Force deletion of Exif data from uploaded images.
 * Plugin URI: https://p-fox.jp/
 * Version: 1.0
 * Author: Red Fox(team Red Fox)
 * Author URI: https://p-fox.jp/
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: remove-image-exif-date
 * Requires at least: 6.8
 * Requires PHP: 7.4
 */

// 直接ファイルにアクセスされた場合の保護
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// プラグインオプションのプレフィックス
define( 'MER_OPTION_PREFIX', 'mer_');

// ====================================================
// GDライブラリの必須チェックと実行停止の仕組み
// ====================================================

// GDライブラリが利用可能かチェック
if ( ! extension_loaded('gd') ) {
    
    /**
     * GD非サポート時に管理者向けのエラー通知を表示する
     */
    function mer_gd_missing_admin_notice() {
        // 管理者権限を持つユーザーにのみエラーを表示
        if ( current_user_can( 'manage_options' ) ) {
            // Text Domainをロードし、翻訳を有効にする
            $error_message = esc_html__('GDライブラリが有効になっていないため、このプラグインは動作しません。ホスティングプロバイダーに連絡し、PHPのGD拡張機能を有効にしてください。', 'remove-image-exif-date');
            ?>
            <div class="notice notice-error is-dismissible">
                <p><strong>Remove Image Exif Data:</strong> <?php echo wp_kses_post($error_message); ?></p>
            </div>
            <?php
        }
    }
    // 管理画面にエラー通知をフック
    add_action('admin_notices', 'mer_gd_missing_admin_notice');

    // GDがないため、ここでプラグインの実行を停止し、以降の処理をトラップする
    // これにより、GD関数への依存による致命的なエラーを防ぐ
    return; 
}

// ----------------------------------------------------
// 1. 管理画面の設定とメニューの登録
// ----------------------------------------------------

add_action('admin_menu', 'mer_add_admin_menu');
add_action('admin_init', 'mer_settings_init');

/**
 * カスタム設定メニューを「設定」に追加
 */
function mer_add_admin_menu() {
    add_options_page(
        esc_html__('画像メタデータ削除設定', 'remove-image-exif-date'),
        esc_html__('画像メタデータ削除', 'remove-image-exif-date'),
        'manage_options',
        'mer-settings-page', // 設定ページのスラッグ
        'mer_settings_page_callback'
    );
}

/**
 * 設定ページのコンテンツを表示
 */
function mer_settings_page_callback() {
    // ユーザーに権限があるか確認
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form action="options.php" method="post">
            <?php
            // 設定グループ (mer_options_group) の設定
            settings_fields('mer_options_group');
            // セクションとフィールドの表示
            do_settings_sections('mer-settings-page');
            // 保存ボタン
            submit_button(esc_html__('設定を保存', 'remove-image-exif-date'));
            ?>
        </form>
    </div>
    <?php
}

/**
 * 設定とフィールドを登録
 */
function mer_settings_init() {
    $option_enabled = MER_OPTION_PREFIX . 'enabled';

    // 1. メタデータ削除有効/無効のオプションを登録
    register_setting('mer_options_group', $option_enabled, array(
        'default'             => 0, // デフォルト値はオフ (0)
        'type'                => 'boolean',
        'sanitize_callback'   => 'intval',
        'show_in_rest'        => false,
    ));

    // 設定セクションを登録
    add_settings_section(
        'mer_main_section',
        esc_html__('画像メタ情報強制削除設定', 'remove-image-exif-date'),
        'mer_section_callback',
        'mer-settings-page'
    );

    // 設定フィールド (チェックボックス) を登録
    add_settings_field(
        $option_enabled,
        esc_html__('メタデータ削除を強制する', 'remove-image-exif-date'),
        'mer_toggle_callback',
        'mer-settings-page',
        'mer_main_section',
        array('label_for' => $option_enabled)
    );
}

/**
 * 設定セクションの説明を表示
 */
function mer_section_callback() {
    echo '<p>' . esc_html__('画像をアップロードする際に、カメラの機種名、撮影日時、GPS情報などのメタ情報を強制的に削除します。', 'remove-image-exif-date') . '</p>';
    if (!extension_loaded('gd')) {
        echo '<div style="padding: 10px; border: 1px solid #dc3232; background: #ffebeb; color: #dc3232;">' . esc_html__('警告: この機能にはPHPのGD拡張機能が必要です。現在GDが有効になっていないため、メタデータ削除機能は動作しません。', 'remove-image-exif-date') . '</div>';
    } else {
        echo '<p class="description">' . esc_html__('対応形式: JPEG, PNG, GIF, WebP (PHPのGDライブラリ設定に依存します)。', 'remove-image-exif-date') . '</p>';
    }
}

/**
 * 有効/無効チェックボックスのHTML
 */
function mer_toggle_callback() {
    $option_name = MER_OPTION_PREFIX . 'enabled';
    $is_enabled = get_option($option_name, 0);
    ?>
    <input type="checkbox" name="<?php echo esc_attr($option_name); ?>" id="<?php echo esc_attr($option_name); ?>" value="1" <?php checked(1, $is_enabled); ?>>
    <label for="<?php echo esc_attr($option_name); ?>">
        <?php esc_html_e('オンにすると、アップロードされた画像からメタ情報が削除されます。', 'remove-image-exif-date'); ?>
    </label>
    <?php
}


// ----------------------------------------------------
// 2. コアのメタデータ削除ロジック
// ----------------------------------------------------

// ファイルがアップロードされ、一時ディレクトリに移動された後に実行されるフック
add_filter('wp_handle_upload', 'mer_strip_uploaded_image_exif');

/**
 * アップロードされた画像のメタ情報（Exifなど）を強制的に削除する
 *
 * GDライブラリを使用して画像を読み込み直し、再保存することでメタデータを破棄する。
 * @param array $file アップロードされるファイルに関するデータ。
 * @return array 変更されたファイルデータ。
 */
function mer_strip_uploaded_image_exif($file) {
    $option_enabled = MER_OPTION_PREFIX . 'enabled';

    // 1. 設定を取得
    $is_enabled = (int) get_option($option_enabled, 0);

    // 2. 設定がオフの場合、またはアップロードにエラーがある場合はスキップ
    if (!$is_enabled || (isset($file['error']) && $file['error'] !== false)) {
        return $file;
    }

    $type = isset($file['type']) ? $file['type'] : '';
    $file_path = isset($file['file']) ? $file['file'] : '';
    
    // GD拡張機能が利用可能か確認
    if (!extension_loaded('gd')) {
        return $file;
    }

    $image = false;
    $success = false;

    try {
        switch ($type) {
            case 'image/jpeg':
            case 'image/jpg':
            case 'image/pjpeg':
                if (function_exists('imagecreatefromjpeg')) {
                    $image = imagecreatefromjpeg($file_path);
                    if ($image !== false) {
                        // JPEG: 品質100で再保存し、画質の劣化を最小限に抑える
                        $success = imagejpeg($image, $file_path, 100); 
                    }
                }
                break;

            case 'image/png':
                if (function_exists('imagecreatefrompng')) {
                    $image = imagecreatefrompng($file_path);
                    if ($image !== false) {
                        // PNG: 圧縮レベル9 (最も高い圧縮率、品質劣化は少ない) で再保存
                        $success = imagepng($image, $file_path, 9); 
                    }
                }
                break;

            case 'image/gif':
                if (function_exists('imagecreatefromgif')) {
                    $image = imagecreatefromgif($file_path);
                    if ($image !== false) {
                        // GIF: 品質オプションなしで再保存
                        $success = imagegif($image, $file_path);
                    }
                }
                break;
            
            case 'image/webp':
                if (function_exists('imagecreatefromwebp')) {
                    $image = imagecreatefromwebp($file_path);
                    if ($image !== false) {
                        // WebP: 品質100で再保存
                        $success = imagewebp($image, $file_path, 100); 
                    }
                }
                break;
                
            default:
                // 上記以外の画像形式は処理をスキップ
                return $file;
        }

        // 画像リソースが作成された場合はメモリを解放
        if ($image !== false) {
            imagedestroy($image);
        }

    } catch (\Exception $e) {
        // アップロード自体は続行
    }
    // 再保存が成功しても失敗しても、ファイル自体は存在するため、$file を返す
    return $file;
}


// --------------------------------------------------------
// 3. アンインストール時の処理 (クリーンアップ)
// --------------------------------------------------------

/**
 * プラグイン削除時にデータベースのオプションをクリーンアップする
 */
function mer_uninstall_cleanup() {
    delete_option( MER_OPTION_PREFIX . 'enabled' );
}
// プラグイン削除時に実行する関数を登録
register_uninstall_hook( __FILE__, 'mer_uninstall_cleanup' );
