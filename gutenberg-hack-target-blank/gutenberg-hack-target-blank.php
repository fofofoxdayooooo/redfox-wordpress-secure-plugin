<?php
/**
 * Plugin Name: Post Field External Link Controller
 * Description: 投稿ごとにカスタムフィールド「ext_blank」を設定し、1で外部リンクに target="_blank" を付与します。0または未設定の場合は無効です。
 * Version: 1.0
 * Author: Red Fox (team Red Fox)
 * License: GPLv2 or later
 * Text Domain: post-field-external-link-controller
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 編集画面にカスタムフィールドを常時表示
 * 「ext_blank」フィールドをメタボックスとして設置（1: 有効, 0: 無効）
 */
function pfelc_add_meta_box() {
	add_meta_box(
		'pfelc_meta_box',
		esc_html__( '外部リンク設定', 'post-field-external-link-controller' ),
		'pfelc_render_meta_box',
		array( 'post', 'page' ),
		'side',
		'default'
	);
}
add_action( 'add_meta_boxes', 'pfelc_add_meta_box' );

/**
 * メタボックスの内容
 */
function pfelc_render_meta_box( $post ) {
	wp_nonce_field( 'pfelc_save_meta', 'pfelc_meta_nonce' );
	$value = get_post_meta( $post->ID, 'ext_blank', true );

	echo '<label for="pfelc_ext_blank">';
	echo esc_html__( '1で有効 / 0または空で無効', 'post-field-external-link-controller' );
	echo '</label><br />';
	echo '<input type="number" min="0" max="1" id="pfelc_ext_blank" name="pfelc_ext_blank" value="' . esc_attr( $value ) . '" style="width:80px;" />';
	echo '<p class="description">' . esc_html__( '有効時、外部リンクに target="_blank" rel="noopener noreferrer" を付与します。内部リンクは対象外です。', 'post-field-external-link-controller' ) . '</p>';
}

/**
 * 保存処理
 */
function pfelc_save_meta( $post_id ) {
	if ( ! isset( $_POST['pfelc_meta_nonce'] ) || ! wp_verify_nonce( $_POST['pfelc_meta_nonce'], 'pfelc_save_meta' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$val = isset( $_POST['pfelc_ext_blank'] ) ? sanitize_text_field( $_POST['pfelc_ext_blank'] ) : '';
	if ( $val !== '1' ) {
		$val = '0';
	}
	update_post_meta( $post_id, 'ext_blank', $val );
}
add_action( 'save_post', 'pfelc_save_meta' );

/**
 * コンテンツ内リンク変換処理
 */
function pfelc_content_filter( $content ) {
	if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	$post_id = get_the_ID();
	$enable  = get_post_meta( $post_id, 'ext_blank', true );

	if ( $enable !== '1' ) {
		return $content; // 無効なら何もしない
	}

	$home_url = preg_quote( home_url(), '/' ); // 内部リンク判定用
	$content  = preg_replace_callback(
		'/<a\s+[^>]*href=["\'](https?:\/\/[^"\']+)["\'][^>]*>/i',
		function ( $matches ) use ( $home_url ) {
			$url = $matches[1];
			if ( preg_match( '/' . $home_url . '/i', $url ) ) {
				return $matches[0]; // 自サイト内リンクはスルー
			}
			// targetとrelを付与
			if ( ! preg_match( '/target=/i', $matches[0] ) ) {
				$link = str_replace( $url, $url . '" target="_blank" rel="noopener noreferrer', $matches[0] );
				return $link;
			}
			return $matches[0];
		},
		$content
	);

	return $content;
}
add_filter( 'the_content', 'pfelc_content_filter', 999 );
