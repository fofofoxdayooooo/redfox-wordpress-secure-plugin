<?php
/**
 * Plugin Name: Custom Author Slug Controller
 * Description: 投稿者スラッグ・authorベース・author=nを完全制御する軽量プラグイン。
 * Version: 1.3.1
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
 * PART 1: 投稿者スラッグ編集 + 非表示設定
 * =========================================================
 */
function casc_add_author_slug_field( $user ) {
	if ( ! current_user_can( 'edit_users' ) ) {
		return;
	}
	$custom_slug = get_user_meta( $user->ID, 'custom_author_slug', true );
	$hide        = get_user_meta( $user->ID, 'hide_author_page', true );
	wp_nonce_field( 'casc_save_author_slug', 'casc_author_nonce' );
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
				<label><input type="checkbox" name="hide_author_page" value="1" <?php checked( $hide, 1 ); ?>> 非表示にする</label><br>
				<span class="description">チェックすると、authorページが404になります。</span>
			</td>
		</tr>
	</table>
	<?php
}
add_action( 'show_user_profile', 'casc_add_author_slug_field' );
add_action( 'edit_user_profile', 'casc_add_author_slug_field' );

/**
 * 保存処理（Nonce検証付き）
 */
function casc_save_author_slug_field( $user_id ) {
	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return false;
	}

	if ( ! isset( $_POST['casc_author_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['casc_author_nonce'] ) ), 'casc_save_author_slug' ) ) {
		return;
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
 * PART 2: authorベース設定をパーマリンク設定画面に追加
 * =========================================================
 */
function casc_add_author_base_setting() {
	$author_base = get_option( 'casc_author_base', 'author' );
	?>
	<h2>投稿者ベース設定</h2>
	<table class="form-table">
		<tr>
			<th scope="row"><label for="casc_author_base">投稿者ベース</label></th>
			<td>
				<input type="text" name="casc_author_base" id="casc_author_base" value="<?php echo esc_attr( $author_base ); ?>" class="regular-text" /><br>
				<p class="description">例：author → writer に変更すると、URLが /writer/〇〇 になります。</p>
				<?php wp_nonce_field( 'casc_update_author_base', 'casc_base_nonce' ); ?>
			</td>
		</tr>
	</table>
	<?php
}
add_action( 'pre_permalinks_settings', 'casc_add_author_base_setting' );

function casc_save_author_base_setting() {
	if ( isset( $_POST['casc_base_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['casc_base_nonce'] ) ), 'casc_update_author_base' ) ) {
		if ( isset( $_POST['casc_author_base'] ) ) {
			$new_base = sanitize_title( wp_unslash( $_POST['casc_author_base'] ) );
			update_option( 'casc_author_base', $new_base );
			global $wp_rewrite;
			$wp_rewrite->author_base = $new_base;
			$wp_rewrite->flush_rules();
		}
	}
}
add_action( 'admin_init', 'casc_save_author_base_setting' );

/**
 * =========================================================
 * PART 3: 投稿者スラッグURL書き換え
 * =========================================================
 */
function casc_filter_author_link( $link, $author_id, $author_nicename ) {
	$custom_slug = get_user_meta( $author_id, 'custom_author_slug', true );
	$base        = get_option( 'casc_author_base', 'author' );
	if ( ! empty( $custom_slug ) ) {
		$link = home_url( '/' . $base . '/' . $custom_slug . '/' );
	}
	return esc_url( $link );
}
add_filter( 'author_link', 'casc_filter_author_link', 10, 3 );

function casc_update_author_structure() {
	global $wp_rewrite;

	// 設定された author_base を取得
	$base = get_option( 'casc_author_base', 'author' );

	// sanitize_title()でスラッグを正規化（例： blog/archive/author → blog/archive/author）
	$base = trim( sanitize_text_field( $base ), '/' );

	// Rewriterに反映
	$wp_rewrite->author_base      = $base;
	$wp_rewrite->author_structure = '/' . $base . '/%author%/';

	// フラッシュはパフォーマンス上ここでは行わない
}
add_action( 'init', 'casc_update_author_structure', 20 );

/**
 * =========================================================
 * PART 4: カスタムスラッグ解析 + 非表示チェック（高速化版）
 * =========================================================
 */
function casc_parse_request( $query ) {
	if ( ! isset( $query->query_vars['author_name'] ) ) {
		return;
	}

	global $wpdb;

	$slug = sanitize_title( $query->query_vars['author_name'] );
	$cache_key = 'casc_user_' . md5( $slug );
	$user = wp_cache_get( $cache_key, 'casc' );
	
	if ( false === $user ) {
		// usermeta テーブルを直接検索（meta_query を使用しない）
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$user_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta}
				 WHERE meta_key = 'custom_author_slug'
				 AND meta_value = %s
				 LIMIT 1",
				$slug
			)
		);

		if ( $user_id ) {
			$user = get_user_by( 'id', (int) $user_id );
		} else {
			$user = null;
		}

		wp_cache_set( $cache_key, $user, 'casc', 3600 );
	}

	if ( $user instanceof WP_User ) {
		$user_id = $user->ID;
		if ( get_user_meta( $user_id, 'hide_author_page', true ) ) {
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
 * PART 6: 非表示ユーザー除外（高速化版）
 * =========================================================
 */
function casc_exclude_hidden_authors( $query ) {
	if ( $query->is_main_query() && ( $query->is_author() || $query->is_home() || $query->is_archive() ) ) {
		global $wpdb;
		
		$cache_key = 'casc_hidden_users';
		$hidden_ids = wp_cache_get( $cache_key, 'casc' );

		if ( false === $hidden_ids ) {
			// usermeta テーブルを直接検索（meta_query 使用回避）
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$hidden_ids = $wpdb->get_col(
				"SELECT user_id FROM {$wpdb->usermeta}
				 WHERE meta_key = 'hide_author_page'
				 AND meta_value = '1'"
			);
			wp_cache_set( $cache_key, $hidden_ids, 'casc', 3600 );
		}

		if ( ! empty( $hidden_ids ) ) {
			$query->set( 'author__not_in', array_map( 'intval', $hidden_ids ) );
		}
	}
}
add_action( 'pre_get_posts', 'casc_exclude_hidden_authors' );