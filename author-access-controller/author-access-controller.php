<?php
/**
 * Plugin Name: Author Access Controller
 * Description: ?author= へのアクセスを制御し、リダイレクト・JSONエラー・許可を選択できるセキュリティプラグインです。
 * Version: 1.1
 * Author: Red Fox (team Red Fox)
 * License: GPLv2 or later
 * Text Domain: author-access-controller
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 設定登録
 */
function aac_register_settings() {
	add_option( 'aac_mode', 'redirect_top' );
	add_option( 'aac_error_name', 'forbidden_author_access' );
	add_option( 'aac_error_message', 'Access to author pages is restricted.' );
	add_option( 'aac_error_status', '403' );

	register_setting( 'aac_options_group', 'aac_mode', array( 'sanitize_callback' => 'sanitize_text_field' ) );
	register_setting( 'aac_options_group', 'aac_error_name', array( 'sanitize_callback' => 'sanitize_text_field' ) );
	register_setting( 'aac_options_group', 'aac_error_message', array( 'sanitize_callback' => 'sanitize_text_field' ) );
	register_setting( 'aac_options_group', 'aac_error_status', array( 'sanitize_callback' => 'absint' ) );
}
add_action( 'admin_init', 'aac_register_settings' );

/**
 * 設定画面
 */
function aac_register_options_page() {
	add_options_page(
		'Author Access Controller',
		'Author Access',
		'manage_options',
		'aac',
		'aac_options_page'
	);
}
add_action( 'admin_menu', 'aac_register_options_page' );

/**
 * 管理画面HTML
 */
function aac_options_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Author Access Controller Settings', 'author-access-controller' ); ?></h1>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'aac_options_group' );
			do_settings_sections( 'aac_options_group' );
			?>
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><?php echo esc_html__( 'Action Mode', 'author-access-controller' ); ?></th>
					<td>
						<select name="aac_mode">
							<option value="redirect_top" <?php selected( get_option( 'aac_mode' ), 'redirect_top' ); ?>><?php echo esc_html__( 'Redirect to Top Page', 'author-access-controller' ); ?></option>
							<option value="error_json" <?php selected( get_option( 'aac_mode' ), 'error_json' ); ?>><?php echo esc_html__( 'Return JSON Error', 'author-access-controller' ); ?></option>
							<option value="allow" <?php selected( get_option( 'aac_mode' ), 'allow' ); ?>><?php echo esc_html__( 'Allow (Not Recommended)', 'author-access-controller' ); ?></option>
						</select>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row"><?php echo esc_html__( 'Error Name (JSON only)', 'author-access-controller' ); ?></th>
					<td><input type="text" name="aac_error_name" value="<?php echo esc_attr( get_option( 'aac_error_name' ) ); ?>" /></td>
				</tr>

				<tr valign="top">
					<th scope="row"><?php echo esc_html__( 'Error Message (JSON only)', 'author-access-controller' ); ?></th>
					<td><input type="text" name="aac_error_message" value="<?php echo esc_attr( get_option( 'aac_error_message' ) ); ?>" size="60" /></td>
				</tr>

				<tr valign="top">
					<th scope="row"><?php echo esc_html__( 'HTTP Status (JSON only)', 'author-access-controller' ); ?></th>
					<td><input type="number" name="aac_error_status" value="<?php echo esc_attr( get_option( 'aac_error_status' ) ); ?>" min="100" max="599" /></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/**
 * author= の早期検出と制御
 */
function aac_block_author_query( $query ) {
	// 管理画面では無効化
	if ( is_admin() ) {
		return;
	}

	// authorパラメータが存在する場合に即時処理
	// @codingStandardsIgnoreStart
	if ( isset( $_GET['author'] ) ) {
	// @codingStandardsIgnoreEnd
		$mode = get_option( 'aac_mode', 'redirect_top' );

		if ( 'allow' === $mode ) {
			return;
		}

		if ( 'redirect_top' === $mode ) {
			wp_safe_redirect( home_url() );
			exit;
		}

		if ( 'error_json' === $mode ) {
			$name    = sanitize_text_field( get_option( 'aac_error_name', 'forbidden_author_access' ) );
			$message = sanitize_text_field( get_option( 'aac_error_message', 'Access denied.' ) );
			$status  = absint( get_option( 'aac_error_status', 403 ) );

			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8', true, $status );

			echo wp_json_encode(
				array(
					'error' => array(
						'name'    => $name,
						'message' => $message,
						'status'  => $status,
					),
				)
			);
			exit;
		}
	}
}
add_action( 'parse_request', 'aac_block_author_query', 0 );