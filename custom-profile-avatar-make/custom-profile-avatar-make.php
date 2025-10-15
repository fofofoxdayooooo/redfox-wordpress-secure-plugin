<?php
/**
 * Plugin Name: Custom Profile Avatar Make
 * Description: ユーザーが自分のプロフィール画像をアップロード・削除できるようにします。未設定時は通常のGravatarを使用します。
 * Version: 1.3
 * Author: Red Fox (team Red Fox)
 * License: GPLv2 or later
 * Text Domain: custom-profile-avatar-make
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ユーザープロフィール編集フォームに enctype="multipart/form-data" を追加
 * これがないとファイルアップロードが正常に動作しません。
 */
function cpam_update_user_form_enctype() {
	echo ' enctype="multipart/form-data"';
}
add_action( 'user_edit_form_tag', 'cpam_update_user_form_enctype' );

/**
 * プロフィール編集画面にアップロードフィールドを追加
 */
function cpam_user_profile_avatar_field( $user ) {
	$avatar_url = esc_url( get_user_meta( $user->ID, 'cpam_custom_avatar', true ) );
	// nonceフィールドはセキュリティのために必須です
	wp_nonce_field( 'cpam_avatar_update', 'cpam_avatar_nonce' );
	?>
	<h3><?php esc_html_e( 'Custom Profile Avatar', 'custom-profile-avatar-make' ); ?></h3>
	<table class="form-table">
		<tr>
			<th><label for="cpam_custom_avatar"><?php esc_html_e( 'Upload your avatar', 'custom-profile-avatar-make' ); ?></label></th>
			<td>
				<?php if ( $avatar_url ) : ?>
					<img src="<?php echo esc_url( $avatar_url ); ?>" alt="<?php esc_attr_e( 'Current Avatar', 'custom-profile-avatar-make' ); ?>" width="96" height="96" class="avatar avatar-96 photo" /><br>
				<?php endif; ?>
				<input type="file" name="cpam_custom_avatar" id="cpam_custom_avatar" accept="image/jpeg,image/png"><br>
				<label>
					<input type="checkbox" name="cpam_delete_avatar" value="1">
					<?php esc_html_e( 'Delete current avatar', 'custom-profile-avatar-make' ); ?>
				</label><br>
				<span class="description"><?php esc_html_e( 'Upload a JPG or PNG image (max 512KB).', 'custom-profile-avatar-make' ); ?></span>
			</td>
		</tr>
	</table>
	<?php
}
add_action( 'show_user_profile', 'cpam_user_profile_avatar_field' );
add_action( 'edit_user_profile', 'cpam_user_profile_avatar_field' );

/**
 * プロフィール保存処理
 */
function cpam_save_user_profile_avatar( $user_id ) {
	if ( ! current_user_can( 'upload_files' ) ) return;

	if ( empty( $_POST['cpam_avatar_nonce'] ) ||
	     ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cpam_avatar_nonce'] ) ), 'cpam_avatar_update' ) ) {
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	// 削除処理
	if ( ! empty( $_POST['cpam_delete_avatar'] ) ) {
		$existing = get_user_meta( $user_id, 'cpam_custom_avatar', true );
		if ( $existing ) {
			$upload_dir = wp_get_upload_dir();
			$file_path  = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $existing );
			if ( file_exists( $file_path ) ) {
				wp_delete_file( $file_path );
			}
			delete_user_meta( $user_id, 'cpam_custom_avatar' );
		}
		return;
	}

    $raw_file = $_FILES['cpam_custom_avatar'];

	// ファイルがない場合は終了
	if ( empty( $raw_file ) ) {
		return;
	}

    $file = array(
        'name'     => isset( $raw_file['name'] ) ? sanitize_file_name( wp_unslash( $raw_file['name'] ) ) : '',
        'type'     => isset( $raw_file['type'] ) ? sanitize_mime_type( wp_unslash( $raw_file['type'] ) ) : '',
        'tmp_name' => isset( $raw_file['tmp_name'] ) ? wp_unslash( $raw_file['tmp_name'] ) : '',
        'error'    => isset( $raw_file['error'] ) ? absint( $raw_file['error'] ) : 0,
        'size'     => isset( $raw_file['size'] ) ? absint( $raw_file['size'] ) : 0,
    );

	// サイズ制限
	if ( $file['size'] > 512000 ) {
		return;
	}

	// MIMEタイプ制限
	$allowed = array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png' );
	$file_type = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $allowed );
	if ( empty( $file_type['type'] ) ) {
		return;
	}

	$upload_overrides = array( 'test_form' => false, 'mimes' => $allowed );
	$movefile = wp_handle_upload( $file, $upload_overrides );

	if ( isset( $movefile['error'] ) ) {
		return;
	}

	if ( ! empty( $movefile['file'] ) && file_exists( $movefile['file'] ) ) {
		$image = wp_get_image_editor( $movefile['file'] );
		if ( ! is_wp_error( $image ) ) {
			$image->resize( 96, 96, true );
			$image->save( $movefile['file'] );
		}
		update_user_meta( $user_id, 'cpam_custom_avatar', esc_url_raw( $movefile['url'] ) );
	}
}
// 既存の2つに加えて追加
add_action( 'personal_options_update', 'cpam_save_user_profile_avatar' );
add_action( 'edit_user_profile_update', 'cpam_save_user_profile_avatar' );
add_action( 'profile_update', 'cpam_save_user_profile_avatar' ); // 追加

/**
 * get_avatarを上書きして独自画像を使用
 * (この関数は元のコードから変更ありませんが、含めておきます)
 */
/**
 * get_avatar_url() にも対応するためのフック
 */
function cpam_filter_get_avatar_data( $args, $id_or_email ) {
    $user = false;

    if ( is_numeric( $id_or_email ) ) {
        $user = get_user_by( 'id', (int) $id_or_email );
    } elseif ( is_object( $id_or_email ) && ! empty( $id_or_email->user_id ) ) {
        $user = get_user_by( 'id', (int) $id_or_email->user_id );
    } elseif ( is_string( $id_or_email ) ) {
        $user = get_user_by( 'email', sanitize_email( $id_or_email ) );
    }

    if ( $user ) {
        $custom_avatar = get_user_meta( $user->ID, 'cpam_custom_avatar', true );
        if ( $custom_avatar ) {
            $args['url'] = esc_url( $custom_avatar );
        }
    }

    return $args;
}
add_filter( 'get_avatar_data', 'cpam_filter_get_avatar_data', 10, 2 );

/**
 * アンインストール処理
 */
function cpam_uninstall() {
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		return;
	}
	
	// ファイル処理に必要なファイルをインクルード
	if ( ! function_exists( 'wp_delete_file' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	
	$users = get_users( array( 'fields' => array( 'ID' ) ) );
	$upload_dir = wp_get_upload_dir();
	
	foreach ( $users as $user ) {
		$existing = get_user_meta( $user->ID, 'cpam_custom_avatar', true );
		if ( $existing ) {
			// URLからファイルパスに変換
			$file_path = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $existing );
			
			if ( file_exists( $file_path ) ) {
				// ファイルを安全に削除
				wp_delete_file( wp_normalize_path( $file_path ) );
			}
			delete_user_meta( $user->ID, 'cpam_custom_avatar' );
		}
	}
}
register_uninstall_hook( __FILE__, 'cpam_uninstall' );