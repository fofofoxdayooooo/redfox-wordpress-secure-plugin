<?php
/**
 * Plugin Name: Author Access Controller
 * Description: ?author= など特定スラッグへのアクセスを制御し、リダイレクト・JSONエラー・許可を選択できるセキュリティプラグインです。
 * Version: 1.2
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
	add_option( 'aac_denied_slugs', 'author,author_name,author__in,author__not_in,author_slug,author_login,feed_author,feed_author_name,user,user_id,user_login,user_nicename,users,display_name,post_author,orderby,meta_key,_author,_user,_display,_author_name,feed,feed_author_combo' ); // デフォルト拒否キー

	register_setting( 'aac_options_group', 'aac_mode', array( 'sanitize_callback' => 'sanitize_text_field' ) );
	register_setting( 'aac_options_group', 'aac_error_name', array( 'sanitize_callback' => 'sanitize_text_field' ) );
	register_setting( 'aac_options_group', 'aac_error_message', array( 'sanitize_callback' => 'sanitize_text_field' ) );
	register_setting( 'aac_options_group', 'aac_error_status', array( 'sanitize_callback' => 'absint' ) );
	register_setting( 'aac_options_group', 'aac_denied_slugs', array( 'sanitize_callback' => 'aac_sanitize_slugs' ) );
}
add_action( 'admin_init', 'aac_register_settings' );

/**
 * スラッグ入力サニタイズ
 */
function aac_sanitize_slugs( $input ) {
	if ( empty( $input ) ) {
		return '';
	}
	$slugs = array_map( 'trim', explode( ',', $input ) );
	$sanitized = array();
	foreach ( $slugs as $slug ) {
		if ( preg_match( '/^[a-zA-Z0-9_\-\[\]]+$/', $slug ) ) {
			$sanitized[] = $slug;
		}
	}
	return implode( ',', array_unique( $sanitized ) );
}

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
					<th scope="row"><?php echo esc_html__( 'Denied Slugs (comma-separated)', 'author-access-controller' ); ?></th>
					<td>
						<textarea name="aac_denied_slugs" rows="3" cols="60"><?php echo esc_textarea( get_option( 'aac_denied_slugs', '' ) ); ?></textarea>
						<p class="description"><?php echo esc_html__( 'Specify query keys (e.g., author, author_name) to block. Separate with commas.', 'author-access-controller' ); ?></p>
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
	if ( is_admin() ) {
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$blocked_keys = array_map( 'trim', explode( ',', get_option( 'aac_denied_slugs', '' ) ) );

	foreach ( $blocked_keys as $key ) {
		if ( $key !== '' && isset( $_GET[ $key ] ) ) {
			aac_execute_author_block();
			return;
		}
	}
	// phpcs:enable
}
add_action( 'parse_request', 'aac_block_author_query', 0 );

/**
 * ブロック実行処理
 */
function aac_execute_author_block() {
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
