<?php
/**
 * Plugin Name: Admin Whitelist
 * Description: 管理者・編集者・投稿者・寄稿者をホワイトリスト方式で制御。Safe（既存保持）とStrict（ホワイトリスト外は購読者降格）モードを提供。明示的除外機能付き。不正昇格検知・ロックアウト防止・SQL改ざん検知対応。
 * Version: 1.3
 * Author: Red Fox (team Red Fox)
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Whitelist {
	const OPTION_KEY         = 'awh_options';
	const OPTION_WHITELIST   = 'whitelist_user_ids';
	const OPTION_MODE        = 'mode';
	const OPTION_GRANDFATHER = 'grandfather_ids';

	private $admin_caps = array(
		'activate_plugins','update_plugins','delete_plugins','install_plugins',
		'update_core','manage_options','edit_users','create_users','delete_users',
		'promote_users','list_users'
	);

	private $restricted_roles = array( 'administrator','editor','author','contributor' );

	public function __construct() {
		// 権限監視・昇格防止
		add_filter( 'user_has_cap', array( $this, 'filter_user_has_cap' ), 10, 4 );
		add_action( 'init', array( $this, 'strict_mode_demotion' ) );
		add_action( 'init', array( $this, 'verify_roles_integrity' ), 2 );
		add_action( 'admin_init', array( $this, 'admin_context_demotion' ), 1 );
		add_action( 'user_register', array( $this, 'check_new_user_role' ) );
		add_action( 'profile_update', array( $this, 'check_profile_update_role' ), 10, 2 );
		add_action( 'set_user_role', array( $this, 'on_role_change_detected' ), 10, 3 );
		add_filter( 'rest_pre_dispatch', array( $this, 'rest_pre_dispatch' ), 10, 3 );

		// 設定画面
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_awh_remove_user', array( $this, 'handle_whitelist_removal_action' ) );

		// ログイン時チェック
		add_action( 'wp_login', array( $this, 'on_login_check' ), 5, 2 );

		// 有効化・アンインストール
		register_activation_hook( __FILE__, array( $this, 'on_activate' ) );
		register_uninstall_hook( __FILE__, array( 'Admin_Whitelist', 'on_uninstall' ) );

	}

	/* ======================================================
	 * 有効化 / アンインストール
	 * ====================================================== */
	public function on_activate() {
		$admins = get_users( array( 'role__in' => $this->restricted_roles, 'fields' => array( 'ID' ) ) );
		$ids    = array_map( 'absint', wp_list_pluck( $admins, 'ID' ) );

		$opts = array(
			self::OPTION_WHITELIST   => $ids,
			self::OPTION_MODE        => 'safe',
			self::OPTION_GRANDFATHER => $ids,
		);
		update_option( self::OPTION_KEY, $opts, false );


	}

	public static function on_uninstall() {
		delete_option( self::OPTION_KEY );
	}


	/* ======================================================
	 * ログイン時降格チェック
	 * ====================================================== */
	public function on_login_check( $user_login, $user ) {
		$opts = get_option( self::OPTION_KEY, array() );
		$mode = $opts[ self::OPTION_MODE ] ?? 'safe';
		$wl   = (array) ( $opts[ self::OPTION_WHITELIST ] ?? array() );

		if ( 'strict' === $mode && ! in_array( (int) $user->ID, $wl, true ) ) {
			foreach ( $this->restricted_roles as $r ) {
				if ( in_array( $r, (array) $user->roles, true ) ) {
					$user->remove_role( $r );
				}
			}
			$user->add_role( 'subscriber' );
			wp_clear_auth_cookie();
			wp_safe_redirect( wp_login_url() . '?awh=demoted' );
			exit;
		}
	}

	/* ======================================================
	 * 管理画面での強制降格
	 * ====================================================== */
	public function admin_context_demotion() {
		if ( ! is_user_logged_in() || ! is_admin() ) {
			return;
		}

		$current = wp_get_current_user();
		if ( empty( $current->roles ) ) {
			return;
		}

		$opts      = get_option( self::OPTION_KEY, array() );
		$mode      = $opts[ self::OPTION_MODE ] ?? 'safe';
		$whitelist = (array) ( $opts[ self::OPTION_WHITELIST ] ?? array() );

		if ( 'strict' === $mode && ! in_array( $current->ID, $whitelist, true ) ) {
			foreach ( (array) $current->roles as $r ) {
				if ( in_array( $r, $this->restricted_roles, true ) ) {
					$current->remove_role( $r );
				}
			}
			$current->add_role( 'subscriber' );
			wp_die( 'ホワイトリスト外のユーザーは高権限を保持できません。購読者へ降格されました。', 'Access Denied', array( 'response' => 403 ) );
		}
	}

	/* ======================================================
	 * ロール整合性検証（SQL改ざん・不正昇格検知）
	 * ====================================================== */
	public function verify_roles_integrity() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user = wp_get_current_user();
		if ( empty( $user->roles ) ) {
			return;
		}

		$opts      = get_option( self::OPTION_KEY, array() );
		$whitelist = (array) ( $opts[ self::OPTION_WHITELIST ] ?? array() );

		// (A) ロックアウト防止：操作中管理者を自動登録
		$current_id = get_current_user_id();
		if ( current_user_can( 'manage_options' ) && ! in_array( $current_id, $whitelist, true ) ) {
			$whitelist[] = $current_id;
			$opts[ self::OPTION_WHITELIST ] = array_unique( $whitelist );
			update_option( self::OPTION_KEY, $opts, false );
		}

		// (B) ホワイトリスト外：即降格＋停止
		if ( ! in_array( $user->ID, $whitelist, true ) ) {
			foreach ( $this->restricted_roles as $r ) {
				if ( in_array( $r, (array) $user->roles, true ) ) {
					$user->remove_role( $r );
				}
			}
			$user->add_role( 'subscriber' );
			wp_clear_auth_cookie();
			wp_die( '不正な昇格を検出しました。購読者へ降格しました。', 'Access Denied', array( 'response' => 403 ) );
		}

		// (C) 不正昇格リアルタイム停止
		if ( current_user_can( 'administrator' ) && ! in_array( $user->ID, $whitelist, true ) ) {
			foreach ( $this->restricted_roles as $r ) {
				if ( in_array( $r, (array) $user->roles, true ) ) {
					$user->remove_role( $r );
				}
			}
			$user->add_role( 'subscriber' );
			wp_clear_auth_cookie();
			wp_die( 'ホワイトリスト外の権限昇格を検出しました。即時停止しました。', 'Access Denied', array( 'response' => 403 ) );
		}
	}

	/* ======================================================
	 * 設定画面
	 * ====================================================== */
	public function add_settings_page() {
		add_options_page( 'Admin Whitelist 設定', 'Admin Whitelist', 'manage_options', 'admin-whitelist', array( $this, 'render_settings_page' ) );
	}

	public function register_settings() {
		register_setting( 'admin_whitelist_group', self::OPTION_KEY, array( $this, 'sanitize_settings' ) );

		add_settings_section( 'awh_main_section', '基本設定', null, 'admin-whitelist' );
		add_settings_field( self::OPTION_MODE, '動作モード', array( $this, 'render_mode_field' ), 'admin-whitelist', 'awh_main_section' );
		add_settings_field( self::OPTION_WHITELIST, 'ホワイトリスト登録ユーザー', array( $this, 'render_whitelist_field' ), 'admin-whitelist', 'awh_main_section' );
	}


	public function sanitize_settings( $input ) {
		$output     = array();
		$current_id = get_current_user_id();

		$mode = ( isset( $input[ self::OPTION_MODE ] ) && in_array( $input[ self::OPTION_MODE ], array( 'safe', 'strict' ), true ) )
			? $input[ self::OPTION_MODE ]
			: 'safe';
		$output[ self::OPTION_MODE ] = $mode;

		$ids = array();
		if ( ! empty( $input['whitelist_user_ids'] ) && is_array( $input['whitelist_user_ids'] ) ) {
			foreach ( $input['whitelist_user_ids'] as $id ) {
				$id = absint( $id );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}

		// 操作中管理者は必ず保持
		if ( ! in_array( $current_id, $ids, true ) ) {
			$ids[] = $current_id;
		}

		$output[ self::OPTION_WHITELIST ] = array_unique( $ids );
		return $output;
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'アクセス権がありません。' );
		}

		echo '<div class="wrap"><h1>Admin Whitelist 設定</h1>';
		settings_errors();
		echo '<form method="post" action="options.php">';
		settings_fields( 'admin_whitelist_group' );
		do_settings_sections( 'admin-whitelist' );
		submit_button();
		echo '</form>';
		$this->render_whitelist_removal_section();
		echo '</div>';
	}

	public function render_mode_field() {
		$options = get_option( self::OPTION_KEY );
		$mode    = $options[ self::OPTION_MODE ] ?? 'safe';
		echo '<label><input type="radio" name="awh_options[mode]" value="safe" ' . checked( $mode, 'safe', false ) . '> Safe（既存維持）</label><br>';
		echo '<label><input type="radio" name="awh_options[mode]" value="strict" ' . checked( $mode, 'strict', false ) . '> Strict（外部降格）</label>';
	}

	public function render_whitelist_field() {
		$options   = get_option( self::OPTION_KEY );
		$whitelist = (array) ( $options[ self::OPTION_WHITELIST ] ?? array() );
		$current   = get_current_user_id();
		$users     = get_users( array( 'fields' => array( 'ID','user_login','display_name' ) ) );

		foreach ( $users as $u ) {
			$roles    = implode( ',', (array) get_userdata( $u->ID )->roles );
			$disabled = ( $u->ID === $current ) ? 'disabled' : '';
			printf(
				'<label><input type="checkbox" name="awh_options[whitelist_user_ids][]" value="%d" %s %s checked  /> %s (%s) - %s</label><br>',
				esc_attr( $u->ID ),
				checked( in_array( $u->ID, $whitelist, true ), true, false ),
				esc_html( $disabled ),
				esc_html( $u->display_name ),
				esc_html( $u->user_login ),
				esc_html( $roles )
			);
		}
		echo '<p class="description">ログイン中の管理者は常にホワイトリスト対象です。</p>';
	}

	/* ======================================================
	 * 除外処理（強制降格含む）
	 * ====================================================== */
	public function render_whitelist_removal_section() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options   = get_option( self::OPTION_KEY );
		$whitelist = (array) ( $options[ self::OPTION_WHITELIST ] ?? array() );
		$current   = get_current_user_id();

		echo '<hr><h2>ホワイトリスト除外（強制降格）</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'awh_remove_user_action', '_awh_nonce' );
		echo '<input type="hidden" name="action" value="awh_remove_user">';
		echo '<select name="remove_user_id"><option value="">-- 除外対象を選択 --</option>';

		foreach ( $whitelist as $uid ) {
			if ( $uid === $current ) {
				continue;
			}
			$u = get_userdata( $uid );
			if ( $u ) {
				printf( '<option value="%d">%s (%s)</option>', esc_attr( $uid ), esc_html( $u->display_name ), esc_html( $u->user_login ) );
			}
		}
		echo '</select> ';
		submit_button( '除外実行（降格）', 'delete', 'submit', false );
		echo '</form>';
	}

	public function handle_whitelist_removal_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '権限がありません。' );
		}

		check_admin_referer( 'awh_remove_user_action', '_awh_nonce' );
		$current = get_current_user_id();
		$target  = absint( $_POST['remove_user_id'] ?? 0 );

		if ( ! $target || $target === $current ) {
			wp_die( '自分自身は除外できません。', 'Error', array( 'response' => 403 ) );
		}

		$opts      = get_option( self::OPTION_KEY, array() );
		$whitelist = (array) ( $opts[ self::OPTION_WHITELIST ] ?? array() );

		if ( in_array( $target, $whitelist, true ) ) {
			$opts[ self::OPTION_WHITELIST ] = array_values( array_diff( $whitelist, array( $target ) ) );
			update_option( self::OPTION_KEY, $opts, false );

			$u = get_userdata( $target );
			if ( $u instanceof WP_User ) {
				foreach ( (array) $u->roles as $r ) {
					$u->remove_role( $r );
				}
				$u->add_role( 'subscriber' );
			}
		}
		wp_safe_redirect( wp_get_referer() );
		exit;
	}

	/* ======================================================
	 * 権限制御・REST昇格防止
	 * ====================================================== */
	public function filter_user_has_cap( $all_caps, $caps, $args, $user ) {
		$uid       = ( is_object( $user ) && isset( $user->ID ) ) ? (int) $user->ID : (int) $user;
		$opts      = get_option( self::OPTION_KEY, array() );
		$wl        = (array) ( $opts[ self::OPTION_WHITELIST ] ?? array() );
		$mode      = $opts[ self::OPTION_MODE ] ?? 'safe';

		if ( 'strict' === $mode && ! in_array( $uid, $wl, true ) ) {
			foreach ( $this->admin_caps as $cap ) {
				$all_caps[ $cap ] = false;
			}
		}
		return $all_caps;
	}

	public function strict_mode_demotion() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$u = wp_get_current_user();
		if ( empty( $u->roles ) ) {
			return;
		}
		$opts = get_option( self::OPTION_KEY, array() );
		$mode = $opts[ self::OPTION_MODE ] ?? 'safe';
		$wl   = (array) ( $opts[ self::OPTION_WHITELIST ] ?? array() );

		if ( 'strict' === $mode && ! in_array( $u->ID, $wl, true ) ) {
			foreach ( $this->restricted_roles as $r ) {
				if ( in_array( $r, (array) $u->roles, true ) ) {
					$u->remove_role( $r );
				}
			}
			$u->add_role( 'subscriber' );
		}
	}

	public function on_role_change_detected( $user_id, $role, $old_roles ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$opts = get_option( self::OPTION_KEY, array() );
		$wl   = (array) ( $opts[ self::OPTION_WHITELIST ] ?? array() );

		if ( ! in_array( $user_id, $wl, true ) ) {
			foreach ( $this->restricted_roles as $r ) {
				if ( in_array( $r, (array) $user->roles, true ) ) {
					$user->remove_role( $r );
					$user->add_role( 'subscriber' );
					break;
				}
			}
		}
	}

	public function check_profile_update_role( $user_id, $old_user_data ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$opts = get_option( self::OPTION_KEY, array() );
		$wl   = (array) ( $opts[ self::OPTION_WHITELIST ] ?? array() );

		if ( ! in_array( $user_id, $wl, true ) ) {
			foreach ( $this->restricted_roles as $r ) {
				if ( in_array( $r, (array) $user->roles, true ) ) {
					$user->remove_role( $r );
					$user->add_role( 'subscriber' );
					break;
				}
			}
		}
	}

	public function check_new_user_role( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$opts = get_option( self::OPTION_KEY, array() );
		$wl   = (array) ( $opts[ self::OPTION_WHITELIST ] ?? array() );

		if ( ! in_array( $user_id, $wl, true ) ) {
			foreach ( $this->restricted_roles as $r ) {
				if ( in_array( $r, (array) $user->roles, true ) ) {
					$user->remove_role( $r );
					$user->add_role( 'subscriber' );
					break;
				}
			}
		}
	}

	public function rest_pre_dispatch( $response, $server, $request ) {
		$route = $request->get_route();
		if ( false !== strpos( $route, '/wp/v2/users' ) ) {
			$body = $request->get_json_params();
			if ( isset( $body['roles'] ) ) {
				foreach ( (array) $body['roles'] as $role ) {
					if ( in_array( $role, $this->restricted_roles, true ) ) {
						$current = wp_get_current_user();
						$opts    = get_option( self::OPTION_KEY );

						if ( ! in_array( $current->ID, (array) $opts[ self::OPTION_WHITELIST ], true ) ) {
							return new WP_Error(
								'forbidden',
								"Role '{$role}' assignment is blocked by Admin Whitelist.",
								array( 'status' => 403 )
							);
						}
					}
				}
			}
		}
		return $response;
	}
}

new Admin_Whitelist();
