<?php
/**
 * Plugin Name: Post Field Display & Link Controller
 * Description: 投稿ごとのカスタムフィールド（no_thumbnail, full_content, link_attachments, ext_blank）を統合管理。外部リンクや画像リンクを自動制御します。
 * Version: 2.0
 * Author: Red Fox (team Red Fox)
 * License: GPLv2 or later
 * Text Domain: post-field-external-link-controller
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* =========================================================
 * PART 1: 共通バリデーション・デフォルト処理
 * ========================================================= */
function pfdlc_validate_binary_field( $value, $default = '0' ) {
	$value = sanitize_text_field( $value );
	return ( $value === '1' || $value === '0' ) ? $value : $default;
}
add_filter( 'get_post_metadata', function( $value, $object_id, $meta_key, $single ) {
	$fields = array( 'no_thumbnail', 'full_content', 'link_attachments', 'ext_blank' );
	if ( $single && in_array( $meta_key, $fields, true ) && ( '' === $value || false === $value || array() === $value ) ) {
		return '0';
	}
	return $value;
}, 10, 4 );

/* =========================================================
 * PART 2: メタボックス登録
 * ========================================================= */
function pfdlc_add_meta_box() {
	add_meta_box(
		'pfdlc_meta_box',
		esc_html__( '投稿設定（外部リンク・表示制御）', 'post-field-display-link-controller' ),
		'pfdlc_render_meta_box',
		array( 'post', 'page' ),
		'normal',
		'default'
	);
}
add_action( 'add_meta_boxes', 'pfdlc_add_meta_box' );

/* =========================================================
 * PART 3: メタボックスの内容
 * ========================================================= */
function pfdlc_render_meta_box( $post ) {
	wp_nonce_field( 'pfdlc_save_meta', 'pfdlc_meta_nonce' );

	$fields = array(
		'no_thumbnail'     => get_post_meta( $post->ID, 'no_thumbnail', true ),
		'full_content'     => get_post_meta( $post->ID, 'full_content', true ),
		'link_attachments' => get_post_meta( $post->ID, 'link_attachments', true ),
		'ext_blank'        => get_post_meta( $post->ID, 'ext_blank', true ),
	);
	?>
	<style>
		.pfdlc-label { display:block; margin-top:10px; font-weight:600; }
		.pfdlc-input { width:50px; text-align:center; padding:5px; border:1px solid #ccc; border-radius:4px; }
		.pfdlc-desc { font-size:12px; color:#666; margin-top:4px; }
	</style>

	<?php foreach ( $fields as $key => $val ) : ?>
		<div>
			<label for="<?php echo esc_attr( $key ); ?>" class="pfdlc-label"><?php echo esc_html( $key ); ?></label>
			<input type="text" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $val ); ?>" class="pfdlc-input" placeholder="0 or 1" />
			<p class="pfdlc-desc">
				<?php
				switch ( $key ) {
					case 'no_thumbnail':
						echo esc_html__( '1: サムネイル非表示 / 0: 表示', 'post-field-display-link-controller' );
						break;
					case 'full_content':
						echo esc_html__( '1: 全文表示 / 0: 抜粋表示（500文字超で強制0）', 'post-field-display-link-controller' );
						break;
					case 'link_attachments':
						echo esc_html__( '1: 投稿画像を添付ページにリンク / 0: 無効', 'post-field-display-link-controller' );
						break;
					case 'ext_blank':
						echo esc_html__( '1: 外部リンクを新しいタブで開く / 0: 無効', 'post-field-display-link-controller' );
						break;
				}
				?>
			</p>
		</div>
	<?php endforeach; ?>
	<?php
}

/* =========================================================
 * PART 4: 保存処理
 * ========================================================= */
function pfdlc_save_meta( $post_id ) {
	if ( ! isset( $_POST['pfdlc_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pfdlc_meta_nonce'] ) ), 'pfdlc_save_meta' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
	if ( ! current_user_can( 'edit_post', $post_id ) ) return;

	$post = get_post( $post_id );
	if ( ! $post ) return;

	$fields = array( 'no_thumbnail', 'full_content', 'link_attachments', 'ext_blank' );
	$data   = array();

	foreach ( $fields as $key ) {
		$input_value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '0';
		$data[ $key ] = pfdlc_validate_binary_field( $input_value );
	}

	// 本文500文字超過 → full_content=0強制
	if ( mb_strlen( wp_strip_all_tags( $post->post_content ) ) > 500 ) {
		$data['full_content'] = '0';
	}

	// full_content=1 → no_thumbnail=1を強制
	if ( '1' === $data['full_content'] ) {
		$data['no_thumbnail'] = '1';
	}

	foreach ( $data as $key => $val ) {
		update_post_meta( $post_id, $key, $val );
	}
}
add_action( 'save_post', 'pfdlc_save_meta' );

/* =========================================================
 * PART 5: コンテンツ変換（外部リンク＋画像リンク）
 * ========================================================= */
function pfdlc_content_filter( $content ) {
	if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	global $post;
	$ext_blank        = get_post_meta( $post->ID, 'ext_blank', true );
	$link_attachments = get_post_meta( $post->ID, 'link_attachments', true );

	// --- 外部リンク target="_blank" ---
	if ( '1' === $ext_blank ) {
		$home = preg_quote( home_url(), '/' );
		$content = preg_replace_callback(
			'/<a\s+[^>]*href=["\'](https?:\/\/[^"\']+)["\'][^>]*>/i',
			function ( $m ) use ( $home ) {
				$url = $m[1];
				if ( preg_match( '/' . $home . '/i', $url ) ) return $m[0];
				if ( ! preg_match( '/target=/i', $m[0] ) ) {
					return str_replace( $url, $url . '" target="_blank" rel="noopener noreferrer', $m[0] );
				}
				return $m[0];
			},
			$content
		);
	}

	// --- 添付画像リンク化 ---
	if ( '1' === $link_attachments ) {
		$content = preg_replace_callback(
			'/<img[^>]*?class=["\'][^"\']*wp-image-(\d+)[^"\']*["\'][^>]*?>/i',
			function ( $matches ) {
				$img_tag = $matches[0];
				$attachment_id = (int) $matches[1];
				if ( $attachment_id ) {
					$link = get_attachment_link( $attachment_id );
					if ( $link && ! is_wp_error( $link ) ) {
						return '<a href="' . esc_url( $link ) . '">' . $img_tag . '</a>';
					}
				}
				return $img_tag;
			},
			$content
		);
	}

	return $content;
}
add_filter( 'the_content', 'pfdlc_content_filter', 15 );