<?php
/**
 * Plugin Name: Custom Author Slug Controller
 * Description: 投稿者スラッグを自前で編集し、author=n 表示を防止・author一覧にも掲載しないよう制御します。
 * Version: 1.0
 * Author: Red Fox (team Red Fox)
 * License: GPLv2 or later
 * Text Domain: custom-author-slug-controller
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * =========================================================
 * PART 1: 投稿者スラッグ編集機能（管理画面にフィールド追加）
 * =========================================================
 */
function casc_add_author_slug_field( $user ) {
	if ( ! current_user_can( 'edit_users' ) ) {
		return;
	}
	$custom_slug = get_user_meta( $user->ID, 'custom_author_slug', true );
	?>
	<h2>カスタム投稿者スラッグ</h2>
	<table class="form-table">
		<tr>
			<th><label for="custom_author_slug">投稿者スラッグ</label></th>
			<td>
				<input type="text" name="custom_author_slug" id="custom_author_slug" value="<?php echo esc_attr( $custom_slug ); ?>" class="regular-text" /><br>
				<span class="description">投稿者URLの末尾（author/〇〇）を変更できます。</span>
			</td>
		</tr>
		<tr>
			<th><label for="hide_author_page">authorページ非表示</label></th>
			<td>
				<label><input type="checkbox" name="hide_author_page" value="1" <?php checked( get_user_meta( $user->ID, 'hide_author_page', true ), 1 ); ?>> 非表示にする</label><br>
				<span class="description">チェックすると、authorページが404になります。</span>
			</td>
		</tr>
	</table>
	<?php
}
add_action( 'show_user_profile', 'casc_add_author_slug_field' );
add_action( 'edit_user_profile', 'casc_add_author_slug_field' );

/**
 * フィールド保存
 */
function casc_save_author_slug_field( $user_id ) {
	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return false;
	}

	if ( isset( $_POST['custom_author_slug'] ) ) {
		$slug = sanitize_title( wp_unslash( $_POST['custom_author_slug'] ) );
		update_user_meta( $user_id, 'custom_author_slug', $slug );
	}

	$hide = isset( $_POST['hide_author_page'] ) ? 1 : 0;
	update_user_meta( $user_id, 'hide_author_page', $hide );
}
add_action( 'personal_options_update', 'casc_save_author_slug_field' );
add_action( 'edit_user_profile_update', 'casc_save_author_slug_field' );

/**
 * =========================================================
 * PART 2: 投稿者スラッグURL書き換え
 * =========================================================
 */
function casc_filter_author_link( $link, $author_id, $author_nicename ) {
	$custom_slug = get_user_meta( $author_id, 'custom_author_slug', true );
	if ( ! empty( $custom_slug ) ) {
		$link = str_replace( $author_nicename, $custom_slug, $link );
	}
	return $link;
}
add_filter( 'author_link', 'casc_filter_author_link', 10, 3 );

/**
 * =========================================================
 * PART 3: リクエスト時にカスタムスラッグを認識
 * =========================================================
 */
function casc_parse_request( $query ) {
	if ( ! isset( $query->query_vars['author_name'] ) ) {
		return;
	}

	global $wpdb;
	$slug = sanitize_title( $query->query_vars['author_name'] );
	$user_id = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'custom_author_slug' AND meta_value = %s LIMIT 1", $slug ) );

	if ( $user_id ) {
		$hide = get_user_meta( $user_id, 'hide_author_page', true );
		if ( $hide ) {
			status_header( 404 );
			nocache_headers();
			include get_query_template( '404' );
			exit;
		}
		$query->query_vars['author'] = $user_id;
		unset( $query->query_vars['author_name'] );
	}
}
add_action( 'pre_get_posts', 'casc_parse_request' );

/**
 * =========================================================
 * PART 4: author=n 非表示 (リダイレクトまたは404)
 * =========================================================
 */
function casc_block_author_id_query() {
	if ( isset( $_GET['author'] ) && is_numeric( $_GET['author'] ) ) {
		status_header( 404 );
		nocache_headers();
		include get_query_template( '404' );
		exit;
	}
}
add_action( 'template_redirect', 'casc_block_author_id_query' );

/**
 * =========================================================
 * PART 5: ユーザーリストから非表示ユーザー除外
 * =========================================================
 */
function casc_exclude_hidden_authors( $query ) {
	if ( $query->is_author() || $query->is_home() || $query->is_archive() ) {
		$hidden_ids = get_users( array(
			'meta_key'   => 'hide_author_page',
			'meta_value' => 1,
			'fields'     => 'ID',
		) );
		if ( ! empty( $hidden_ids ) ) {
			$query->set( 'author__not_in', $hidden_ids );
		}
	}
}
add_action( 'pre_get_posts', 'casc_exclude_hidden_authors' );