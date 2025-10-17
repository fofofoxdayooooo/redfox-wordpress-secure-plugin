<?php
/**
 * Plugin Name: Media Access Restrictor (Fixed)
 * Description: 編集者の閲覧範囲設定がAjax経由でも反映されるように修正。
 * Plugin URI: https://p-fox.jp/
 * Version: 1.0
 * Author: Red Fox(team Red Fox)
 * Author URI: https://p-fox.jp/
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: media-access-restrictor
 * Requires at least: 6.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ------------------------------------------------------------
// 設定項目を追加
// ------------------------------------------------------------
add_action( 'admin_init', 'mar_add_settings_field' );
function mar_add_settings_field() {
	add_settings_section(
		'mar_section',
		esc_html__( 'メディア閲覧制限設定', 'media-access-restrictor' ),
		'__return_false',
		'media'
	);

	add_settings_field(
		'mar_allow_editors_view_all',
		esc_html__( '編集者も全メディアを閲覧できるようにする', 'media-access-restrictor' ),
		'mar_render_checkbox_field',
		'media',
		'mar_section'
	);

	register_setting(
		'media',
		'mar_allow_editors_view_all',
		array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		)
	);
}

function mar_render_checkbox_field() {
	$checked = get_option( 'mar_allow_editors_view_all', false );
	echo '<label><input type="checkbox" name="mar_allow_editors_view_all" value="1" ' . checked( 1, $checked, false ) . ' />';
	echo esc_html__( '編集者をメディア全件閲覧の対象に含める', 'media-access-restrictor' );
	echo '</label>';
}

/**
 * 管理画面のメディア一覧と Ajax (query-attachments) 双方を制御
 */
add_action( 'pre_get_posts', 'mar_restrict_media_query_fix' );
function mar_restrict_media_query_fix( $query ) {

	// attachment 以外は対象外
	if ( empty( $query->query_vars['post_type'] ) || 'attachment' !== $query->query_vars['post_type'] ) {
		return;
	}

	// ログインしていない場合は強制的に空
	if ( ! is_user_logged_in() ) {
		$query->set( 'author__in', array( 0 ) );
		return;
	}

	$user_id = get_current_user_id();

	// 管理者は全件OK
	if ( current_user_can( 'manage_options' ) ) {
		return;
	}

	// 編集者の扱い
	if ( current_user_can( 'edit_others_posts' ) ) {
		$allow_editors = get_option( 'mar_allow_editors_view_all', false );
		if ( $allow_editors ) {
			return; // 許可ONなら全件OK
		}
	}

	// それ以外 or 編集者チェックOFF → 自分のファイルのみ
	$query->set( 'author', $user_id );
}

/**
 * REST API側（ブロックエディタ/Gutenberg対応）
 */
add_filter( 'rest_media_query', 'mar_rest_media_query_fix', 10, 2 );
function mar_rest_media_query_fix( $args, $request ) {
	if ( ! is_user_logged_in() ) {
		$args['author__in'] = array( 0 );
		return $args;
	}

	// 管理者は全件
	if ( current_user_can( 'manage_options' ) ) {
		return $args;
	}

	// 編集者設定チェック
	if ( current_user_can( 'edit_others_posts' ) && get_option( 'mar_allow_editors_view_all', false ) ) {
		return $args;
	}

	$args['author'] = get_current_user_id();
	return $args;
}
