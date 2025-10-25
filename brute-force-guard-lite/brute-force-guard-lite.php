<?php
/**
 * Plugin Name: BruteForce Guard Lite
 * Description: 軽量なブルートフォース攻撃対策（WPテーブル版）。ログイン試行（成功/失敗）を WP テーブルに記録し、指定回数超過時に一定時間ブロックします。設定・管理画面・アンインストールを安全に実装。
 * Plugin URI: https://p-fox.jp/
 * Version: 1.0
 * Author: Red Fox(team Red Fox)
 * Author URI: https://p-fox.jp/
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: brute-force-guard-lite
 * Requires at least: 6.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BFG_WPTable {
	const OPTION_KEY	=	'bfg_options';
	const TEXT_DOMAIN	=	'brute-force-guard-lite';
	const TABLE_SUFFIX	=	'bfg_logs';
	/** @var wpdb */
	private $wpdb;
	/** @var string */
	private $table_name;
	/** @var array */
	private $options;

	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
		// テーブル名はコンストラクタで一度だけ生成
		$this->table_name = $this->wpdb->prefix . self::TABLE_SUFFIX;

		$this->options = get_option(
			self::OPTION_KEY, array(
				'max_attempts' => 5,
				'block_minutes'	=> 15,
				'log_retention'	=> 86400,
				'log_enabled'	=> 1,
				'trusted_proxies'	=> '',
				)
			);

		add_action( 'init', array( $this, 'maybe_init_db' ) );
		add_action( 'wp_login_failed', array( $this, 'record_failed' ) );
		add_action( 'wp_login', array( $this, 'record_success' ), 10, 2 );
		add_action( 'login_init', array( $this, 'check_block' ) );
		// REST API と XML-RPC のブロックチェックを早期に実行 (priority 5)
		add_action( 'rest_authentication_errors', array( $this, 'check_block' ), 5 );
		add_action( 'xmlrpc_call', array( $this, 'check_block' ), 5 );
		add_action( 'init', array( $this, 'disable_email_login' ), 11 );
		add_filter( 'login_errors', array( $this, 'filter_login_errors' ) );
		add_action( 'init', array( $this, 'cleanup' ) );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
			add_action( 'admin_post_bfg_unblock_ip', array( $this, 'handle_unblock_ip' ) );
		}
	}

	/**
	 * Activation: create table (dbDelta).
	 * Called from procedural activation wrapper.
	 */
	public static function activate() {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;
		$charset_collate = $wpdb->get_charset_collate();

		// ip フィールドの長さを IPv6 に対応できるよう 45 に設定済み (Good)
		$sql = "CREATE TABLE {$table} (
			ip varchar(45) NOT NULL,
			attempts int NOT NULL DEFAULT 0,
			last_attempt bigint NOT NULL DEFAULT 0,
			PRIMARY KEY  (ip)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// set default options if absent
		$defaults = array(
			'max_attempts'	=> 5,
			'block_minutes'	=> 15,
			'log_retention'	=> 86400,
			'log_enabled'	=> 1,
			'trusted_proxies' => '',
		);
		add_option( self::OPTION_KEY, $defaults );
	}

	/**
	 * Ensure DB exists (safety) - Table check improved for better SQL safety.
	 */
	public function maybe_init_db() {
		// wpdb->tables にはすでにプレフィックスが付いているため、LIKE句を使用
		$sql = $this->wpdb->prepare( "SHOW TABLES LIKE %s", $this->table_name );
		// $this->table_name は $wpdb->prefix に続く文字列で、すでに $this->wpdb->esc_like が不要な形式で比較しているため、これで十分安全。
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result	= $this->wpdb->get_var( $sql );

		if ( $result === null ) {
			self::activate();
		}
	}

	public function disable_email_login() {
		if ( $this->options['disable_email'] == 1 ) {
			remove_filter( 'authenticate', 'wp_authenticate_email_password', 20 );
		}
	}

	/**
	 * Get client IP. Supports Trusted Proxies configuration.
	 *
	 * @return string Client IP address, or 'unknown' if validation fails.
	 */
	private function get_ip() {
		// REMOVE phpcs:ignore - input is sanitized below
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$trusted_proxies = array();

		// --- 管理画面設定から読み込み ---
		if ( ! empty( $this->options['trusted_proxies'] ) ) {
			$lines = array_filter( array_map( 'trim', explode( "\n", $this->options['trusted_proxies'] ) ) );
			foreach ( $lines as $line ) {
				// IP/CIDR形式を厳密にチェック
				if ( preg_match( '/^([0-9a-fA-F:\.]+)\/?(\d{1,3})?$/', $line ) ) {
					$trusted_proxies[] = $line;
				}
			}
		}

		$is_trusted_proxy = false;
		foreach ( $trusted_proxies as $cidr ) {
			// IPv4/IPv6対応のCIDRチェックを使用
			if ( self::ip_in_range( $remote_addr, $cidr ) ) {
				$is_trusted_proxy = true;
				break;
			}
		}

		$ip = '';
		if ( $is_trusted_proxy ) {
			// Cloudflare (CF_CONNECTING_IPは最も信頼できるヘッダーとして優先)
			if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				// X-Forwarded-For: 最初のIP（最も左側）をクライアントIPとして使用
				$raw_forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
				// IPアドレスのリストのみを抽出（セキュリティ強化）
				$clean_forwarded = preg_replace( '/[^0-9a-fA-F:., ]/', '', $raw_forwarded );
				$forwarded_ips = array_filter( array_map( 'trim', explode( ',', $clean_forwarded ) ) );
				
				if ( ! empty( $forwarded_ips ) ) {
					$ip_candidate = $forwarded_ips[0];
					// 候補IPが有効なIPであることを確認
					if ( filter_var( $ip_candidate, FILTER_VALIDATE_IP ) ) {
						$ip = $ip_candidate;
					}
				}
			} elseif ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
			}
		}

		// 信頼できないプロキシ、または信頼済みプロキシからクライアントIPを取得できなかった場合のフォールバック
		if ( empty( $ip ) && ! empty( $remote_addr ) ) {
			$ip = $remote_addr;
		}

		// 最終チェック: IPアドレスとして有効でなければ 'unknown'
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) === false ) {
			// `filter_var` が失敗した場合、攻撃者が IP=unknown を共有するリスクを避けるため
			// 最終手段として `REMOTE_ADDR` を再利用するオプションもあるが、今回は `'unknown'` を維持
			// ただし、この `'unknown'` IPへの攻撃はすべての未解決IPユーザーをブロックするDo-Sになるため注意
			$ip = 'unknown';
		}

		return sanitize_text_field( $ip );
	}

	/**
	 * CIDR範囲判定 (IPv4/IPv6対応)
	 * ip2long() は IPv6 に対応しないため、inet_pton() を使用したロジックに修正。
	 */
	private static function ip_in_range( $ip, $cidr ) {
		// CIDR表記でない場合は完全一致のみ
		if ( strpos( $cidr, '/' ) === false ) {
			return $ip === $cidr;
		}

		list( $subnet, $mask ) = explode( '/', $cidr );
		$mask = (int) $mask;

		$ip_bin = @inet_pton( $ip );
		$subnet_bin = @inet_pton( $subnet );

		if ( $ip_bin === false || $subnet_bin === false ) {
			return false; // 無効なIPまたはCIDRフォーマット
		}

		$ip_len = strlen( $ip_bin ); // 4 (IPv4) or 16 (IPv6)

		if ( $ip_len !== strlen( $subnet_bin ) ) {
			return false; // IPv4とIPv6の比較は不可
		}
		
		$max_mask = $ip_len * 8; // 32 or 128
		if ( $mask < 0 || $mask > $max_mask ) {
			return false; // マスクが無効
		}

		// バイト単位でのチェック
		$bytes_to_check = floor( $mask / 8 );
		$bits_to_check  = $mask % 8;

		// 完全にマスクされているバイトを比較
		if ( $bytes_to_check > 0 ) {
			if ( substr( $ip_bin, 0, $bytes_to_check ) !== substr( $subnet_bin, 0, $bytes_to_check ) ) {
				return false;
			}
		}

		// 残りのビットを比較
		if ( $bits_to_check > 0 ) {
			$ip_byte = ord( $ip_bin[ $bytes_to_check ] );
			$subnet_byte = ord( $subnet_bin[ $bytes_to_check ] );
			// 比較マスクを作成
			$diff_mask = ~ ( 0xFF >> $bits_to_check );
			if ( ( $ip_byte & $diff_mask ) !== ( $subnet_byte & $diff_mask ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Record failed login: increment attempts or insert new row.
	 *
	 * @param string $username
	 */
	public function record_failed( $username ) {
		$options = $this->options;
		if ( empty( $options['log_enabled'] ) ) {
			return;
		}

		// 規制対象外ユーザーを除外
		if ( ! empty( $options['allow_users'] ) ) {
			$allowed_users = array_filter( array_map( 'trim', preg_split( '/[\s,]+/', $options['allow_users'] ) ) );
			if ( in_array( $username, $allowed_users, true ) ) {
				return; // 対象外
			}
		}



		$ip = $this->get_ip();
		$time = time();
		
		// check existing - using $this->table_name directly as it is safe (prefixed and defined internally)
		$sql	= "SELECT attempts FROM {$this->table_name} WHERE ip = %s LIMIT 1";
		$row	= $this->wpdb->get_row( $this->wpdb->prepare( $sql, $ip ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $this->table_name is not user input

		if ( $row ) {
			// 既存レコードがある場合
			$attempts = (int) $row['attempts'];
			if ( $attempts < (int) $options['max_attempts'] ) {
				// 上限未満の場合はインクリメント
				$sql = "UPDATE {$this->table_name} SET attempts = attempts + 1, last_attempt = %d WHERE ip = %s";
			} else {
				// 上限に達している場合（試行回数はそのまま維持）
				$sql = "UPDATE {$this->table_name} SET last_attempt = %d WHERE ip = %s";
			}
			$this->wpdb->query( $this->wpdb->prepare( $sql, $time, $ip ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $this->table_name is not user input
		} else {
			// レコードが存在しない場合、新規挿入 (attempts = 1)
			$this->wpdb->insert(
				$this->table_name,
				array(
					'ip' => $ip,
					'attempts' => 1,
					'last_attempt' => $time,
				),
				array( '%s', '%d', '%d' )
			);
		}
	}

	/**
	 * On successful login remove any record for IP.
	 * @param string $user_login
	 * @param WP_User $user
	 */
	public function record_success( $user_login, $user ) {
		$options = $this->options;
		if ( empty( $options['log_enabled'] ) ) {
			return;
		}
		// 規制対象外ユーザーはスキップ
		if ( ! empty( $options['allow_users'] ) ) {
			$allowed_users = array_filter( array_map( 'trim', preg_split( '/[\s,]+/', $options['allow_users'] ) ) );
			if ( in_array( $user_login, $allowed_users, true ) ) {
				return;
			}
		}
		$ip = $this->get_ip();
		// 成功時はログを削除し、完全にリセットする
		$this->wpdb->delete( $this->table_name, array( 'ip' => $ip ), array( '%s' ) );
	}

	/**
	 * Check whether current IP is blocked; if so, die with 403.
	 */
	public function check_block() {
		$options = $this->options;
		$ip = $this->get_ip();
		$time = time();

		$sql = "SELECT attempts, last_attempt FROM {$this->table_name} WHERE ip = %s LIMIT 1";
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( $sql, $ip ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $this->table_name is not user input
			ARRAY_A
		);

		if ( $row && (int)$row['attempts'] >= (int)$options['max_attempts'] ) {
			$elapsed = $time - (int)$row['last_attempt'];
			$block_seconds = (int)$options['block_minutes'] * 60;
			
			if ( $elapsed < $block_seconds ) {
				// ブロック時間内 -> ブロック
				
				// Admin設定のステータス/メッセージを取得、未設定の場合はデフォルトを使用
				$status = ! empty( $options['block_status'] )
					? sanitize_text_field( $options['block_status'] )
					: 'fatal_error';
				$code = ! empty( $options['block_code'] )
					? (int) $options['block_code']
					: 403;
				$message = ! empty( $options['block_message'] )
					? sanitize_text_field( $options['block_message'] )
					: sprintf(
						/* translators: %s: minutes */
						esc_html( 'Access temporarily blocked due to multiple failed login attempts. Please try again later (%s minutes).', self::TEXT_DOMAIN ),
						(int)$options['block_minutes']
					);
				$rest_message = ! empty( $options['block_rest_message'] )
					? sanitize_text_field( $options['block_rest_message'] )
					: sprintf(
						/* translators: %s: minutes */
						esc_html( 'Access temporarily blocked due to multiple failed login attempts. Please try again later (%s minutes).', self::TEXT_DOMAIN ),
						(int)$options['block_minutes']
					);
				
				// REST API リクエスト
				if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
					return new WP_Error( $status, $rest_message, array( 'status' => $code ) );
				}
				// XML-RPC リクエスト
				if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
					// XML-RPC はシンプルな出力を好むため、exitを使用
					exit( esc_html( $message ) );
				}
				// 通常のログイン/管理画面アクセス
				wp_die( esc_html( $message ), esc_html( 'Access blocked', self::TEXT_DOMAIN ), array( 'response' => esc_html($code) ) );

			} else {
				// ブロック期間 expired -> attemptsをリセット
				$sql = "UPDATE {$this->table_name} SET attempts = 0, last_attempt = %d WHERE ip = %s";
				$this->wpdb->query( $this->wpdb->prepare( $sql, $time, $ip ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $this->table_name is not user input
			}
		}
	}

	/**
	 * Cleanup old logs (called on init)
	 */
	public function cleanup() {
		$options = $this->options;
		$threshold = time() - (int)$options['log_retention'];
		
		$sql = "DELETE FROM {$this->table_name} WHERE last_attempt < %d AND attempts < %d"; // attempts < 1 (or 0) のもののみを削除する方がより安全だが、ここでは元のコードのロジックに近づける (attemptsは0にリセットされるため)
		$this->wpdb->query(
			$this->wpdb->prepare( $sql, $threshold, (int)$options['max_attempts'] ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $this->table_name is not user input
		);
	}

	/* ------------------------
	 Settings / Admin UI
	 ------------------------ */

	public function add_admin_menu() {
		add_options_page(
			esc_html( 'BruteForce Guard Lite', self::TEXT_DOMAIN ),
			esc_html( 'BruteForce Guard Lite', self::TEXT_DOMAIN ),
			'manage_options',
			'bfg-lite',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting( 'bfg_lite_group', self::OPTION_KEY, array( $this, 'sanitize_options' ) );
		add_settings_section( 'bfg_main', esc_html( 'Basic settings', self::TEXT_DOMAIN ), null, 'bfg-lite' );
		add_settings_field( 'max_attempts', esc_html( 'Max attempts', self::TEXT_DOMAIN ), array( $this, 'field_max_attempts' ), 'bfg-lite', 'bfg_main' );
		add_settings_field( 'block_minutes', esc_html( 'Block minutes', self::TEXT_DOMAIN ), array( $this, 'field_block_minutes' ), 'bfg-lite', 'bfg_main' );
		add_settings_field( 'block_status', esc_html( 'Custom block status', self::TEXT_DOMAIN ), array( $this, 'field_block_status' ), 'bfg-lite', 'bfg_main' );
		add_settings_field( 'block_code', esc_html( 'Custom block code', self::TEXT_DOMAIN ), array( $this, 'field_block_code' ), 'bfg-lite', 'bfg_main' );
		add_settings_field( 'block_message', esc_html( 'Custom block message', self::TEXT_DOMAIN ), array( $this, 'field_block_message' ), 'bfg-lite', 'bfg_main' );
		add_settings_field( 'block_rest_message', esc_html( 'Custom rest message', self::TEXT_DOMAIN ), array( $this, 'field_block_rest_message' ), 'bfg-lite', 'bfg_main' );
		add_settings_field( 'login_error_message', esc_html( 'Custom login error message', self::TEXT_DOMAIN ), array( $this, 'field_login_error_message' ), 'bfg-lite', 'bfg_main');
		add_settings_field( 'log_retention', esc_html( 'Log retention (seconds)', self::TEXT_DOMAIN ), array( $this, 'field_log_retention' ), 'bfg-lite', 'bfg_main' );
		add_settings_field( 'log_enabled', esc_html( 'Enable logging', self::TEXT_DOMAIN ), array( $this, 'field_log_enabled' ), 'bfg-lite', 'bfg_main' );
		add_settings_field( 'disable_email', esc_html( 'Disable e-mail Login', self::TEXT_DOMAIN ), array( $this, 'field_disable_email' ), 'bfg-lite', 'bfg_main' );
		add_settings_field( 'trusted_proxies', esc_html( 'Trusted proxy IPs (one per line, CIDR supported)', self::TEXT_DOMAIN ), array( $this, 'field_trusted_proxies' ), 'bfg-lite', 'bfg_main');
		add_settings_field( 'allow_users', esc_html( 'Users not subject to regulation', self::TEXT_DOMAIN ), array( $this, 'field_allow_users' ), 'bfg-lite', 'bfg_main');
	}

	public function sanitize_options( $input ) {
		return array(
			'max_attempts'	=> isset( $input['max_attempts'] ) ? absint( $input['max_attempts'] ) : 5,
			'block_minutes'	=> isset( $input['block_minutes'] ) ? absint( $input['block_minutes'] ) : 15,
			'log_retention'	=> isset( $input['log_retention'] ) ? absint( $input['log_retention'] ) : 86400,
			'log_enabled'	=> isset( $input['log_enabled'] ) && $input['log_enabled'] ? 1 : 0,
			// テキストフィールドは全て sanitize_text_field でXSSを防止
			'block_status' => isset( $input['block_status'] ) ? sanitize_text_field( $input['block_status'] ) : '',
			'block_code' => isset( $input['block_code'] ) ? absint( $input['block_code'] ) : '',
			'block_message' => isset( $input['block_message'] ) ? sanitize_text_field( $input['block_message'] ) : '',
			'block_rest_message' => isset( $input['block_rest_message'] ) ? sanitize_text_field( $input['block_rest_message'] ) : '',
			'login_error_message' => isset( $input['login_error_message'] ) ? sanitize_text_field( $input['login_error_message'] ) : '',
			'disable_email'	=> isset( $input['disable_email'] ) && $input['disable_email'] ? 1 : 0,
			// テキストエリアは sanitize_textarea_field でXSSを防止
			'trusted_proxies' => isset( $input['trusted_proxies'] ) ? sanitize_textarea_field( $input['trusted_proxies'] ) : '',
			'allow_users' => isset( $input['allow_users'] ) ? sanitize_textarea_field( $input['allow_users'] ) : '',
		);
	}

	/* field callbacks (Output is correctly escaped with esc_attr/esc_html) */
	public function field_max_attempts() {
		$val = isset( $this->options['max_attempts'] ) ? (int)$this->options['max_attempts'] : 5;
		printf(
			'<input type="number" name="%s[max_attempts]" value="%d" min="1" max="50" />',
			esc_attr( self::OPTION_KEY ),
			esc_attr( $val )
		);
	}

	public function field_block_minutes() {
		$val = isset( $this->options['block_minutes'] ) ? (int)$this->options['block_minutes'] : 15;
		printf(
			'<input type="number" name="%s[block_minutes]" value="%d" min="1" max="1440" />',
			esc_attr( self::OPTION_KEY ),
			esc_attr( $val )
		);
	}

	public function field_log_retention() {
		$val = isset( $this->options['log_retention'] ) ? (int)$this->options['log_retention'] : 86400;
		printf(
			'<input type="number" name="%s[log_retention]" value="%d" min="60" max="31536000" />',
			esc_attr( self::OPTION_KEY ),
			esc_attr( $val )
		);
	}

	public function field_log_enabled() {
		$checked = isset( $this->options['log_enabled'] ) && $this->options['log_enabled'] ? 'checked' : '';
		printf(
			'<label><input type="checkbox" name="%s[log_enabled]" value="1" %s /> %s</label>',
			esc_attr( self::OPTION_KEY ),
			esc_attr( $checked ),
			esc_html( 'Enable logging of attempts', self::TEXT_DOMAIN )
		);
	}

	public function field_block_status() {
		$val = isset( $this->options['block_status'] ) ? esc_attr( $this->options['block_status'] ) : '';
		printf(
			'<input type="text" name="%s[block_status]" value="%s" size="80" placeholder="error" />',
			esc_attr( self::OPTION_KEY ),
			esc_html($val) // esc_attr is used inside the assignment of $val
		);
	}

	public function field_block_code() {
		$val = isset( $this->options['block_code'] ) ? esc_attr( $this->options['block_code'] ) : '';
		printf(
			'<input type="number" name="%s[block_code]" value="%s" size="80" placeholder="403" min="200" max="599" />',
			esc_attr( self::OPTION_KEY ),
			esc_html($val) // esc_attr is used inside the assignment of $val
		);
	}

	public function field_block_message() {
		$val = isset( $this->options['block_message'] ) ? esc_attr( $this->options['block_message'] ) : '';
		printf(
			'<input type="text" name="%s[block_message]" value="%s" size="80" placeholder="Access temporarily blocked." />',
			esc_attr( self::OPTION_KEY ),
			esc_html($val) // esc_attr is used inside the assignment of $val
		);
	}

	public function field_block_rest_message() {
		$val = isset( $this->options['block_rest_message'] ) ? esc_attr( $this->options['block_rest_message'] ) : '';
		printf(
			'<input type="text" name="%s[block_rest_message]" value="%s" size="80" placeholder="Access temporarily blocked." />',
			esc_attr( self::OPTION_KEY ),
			esc_html($val) // esc_attr is used inside the assignment of $val
		);
	}

	public function field_login_error_message() {
		$val = isset( $this->options['login_error_message'] ) ? esc_attr( $this->options['login_error_message'] ) : '';
		printf(
			'<input type="text" name="%s[login_error_message]" value="%s" size="80" placeholder="ログイン情報が正しくありません。" />',
			esc_attr( self::OPTION_KEY ),
			esc_html($val) // esc_attr is used inside the assignment of $val
		);
	}
	
	public function field_trusted_proxies() {
		$val = isset( $this->options['trusted_proxies'] ) ? ( $this->options['trusted_proxies'] ) : '';
		// フィールド名に self::OPTION_KEY を使用 (sanitize_text_field は不要)
		$field_name = self::OPTION_KEY;
		
		// テキストエリアの出力は esc_textarea で安全にエスケープ
		echo '<textarea name="' . esc_attr( $field_name ) . '[trusted_proxies]" rows="4" cols="80" placeholder="例: 173.245.48.0/20&#10;2400:cb00::/32">' . esc_textarea($val) . '</textarea>';
		echo '<p class="description">' . esc_html( '信頼するプロキシのCIDRまたはIPを1行ずつ入力してください。Cloudflare等の環境ではこの設定を利用します。', self::TEXT_DOMAIN ) . '</p>';
	}
	
	public function field_disable_email() {
		$checked = isset( $this->options['disable_email'] ) && $this->options['disable_email'] ? 'checked' : '';
		printf(
			'<label><input type="checkbox" name="%s[disable_email]" value="1" %s /> %s</label>',
			esc_attr( self::OPTION_KEY ),
			esc_attr( $checked ),
			esc_html( 'Disable E-mail Login', self::TEXT_DOMAIN )
		);
	}
	
	public function field_allow_users() {
		$val = isset( $this->options['allow_users'] ) ? ( $this->options['allow_users'] ) : '';
		// フィールド名に self::OPTION_KEY を使用 (sanitize_text_field は不要)
		$field_name = self::OPTION_KEY;
		
		// テキストエリアの出力は esc_textarea で安全にエスケープ
		echo '<textarea name="' . esc_attr( $field_name ) . '[allow_users]" rows="4" cols="80" placeholder="例: user1 user2">' . esc_textarea($val) . '</textarea>';
		echo '<p class="description">' . esc_html( 'ログイン制限を適用しないユーザーの一覧を入力してください。これらのユーザーIDが入力された場合はカウント対象外とします。', self::TEXT_DOMAIN ) . '</p>';
	}

	public function filter_login_errors( $error ) {
		// カスタムメッセージが設定されている場合はそれを使用
	    if ( ! empty( $this->options['login_error_message'] ) ) {
			return esc_html( $this->options['login_error_message'] ); // 出力を適切にエスケープ
	    }
		// デフォルトメッセージを返す場合
	    return esc_html( 'ログイン情報が正しくありません。', self::TEXT_DOMAIN );
	}

	/**
	 * Settings page + blocked IP listing
	 */
	public function render_settings_page() {
		// reload options (in case changed)
		$this->options = get_option( self::OPTION_KEY, $this->options );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html( 'BruteForce Guard Lite Settings', self::TEXT_DOMAIN ) . '</h1>';

		echo '<form method="post" action="options.php">';
		settings_fields( 'bfg_lite_group' );
		do_settings_sections( 'bfg-lite' );
		submit_button();
		echo '</form>';

		// Blocked IPs
		echo '<h2>' . esc_html( 'Blocked IPs', self::TEXT_DOMAIN ) . '</h2>';
		$this->render_blocked_ips_table();

		echo '</div>';
	}

	/**
	 * Render blocked ips table (simple)
	 */
	private function render_blocked_ips_table() {
		$options = $this->options;
		
		// attempts が 1 以上（つまりログが残っているIP）を表示
		$sql = "SELECT ip, attempts, last_attempt FROM {$this->table_name} WHERE attempts >= %d ORDER BY last_attempt DESC LIMIT 200";
		$rows = $this->wpdb->get_results($this->wpdb->prepare( $sql, 1 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $this->table_name is not user input

		echo '<table class="widefat fixed" cellspacing="0">';
		echo '<thead><tr><th>' . esc_html( 'IP', self::TEXT_DOMAIN ) . '</th><th>' . esc_html( 'Attempts', self::TEXT_DOMAIN ) . '</th><th>' . esc_html( 'Last Attempt', self::TEXT_DOMAIN ) . '</th><th>' . esc_html( 'Action', self::TEXT_DOMAIN ) . '</th></tr></thead>';
		echo '<tbody>';

		if ( $rows ) {
			foreach ( $rows as $row ) {
				// 出力されるデータは全て適切にエスケープ
				$ip = esc_html( $row['ip'] );
				$attempts = esc_html( (int)$row['attempts'] );
				$time = esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int)$row['last_attempt'] ) );

				$action_url = admin_url( 'admin-post.php' );
				// IPアドレスごとに一意のNonceアクション名を設定 (CSRF対策)
				$nonce_action = 'bfg_unblock_ip_' . $row['ip'];
				$nonce_field = wp_nonce_field( $nonce_action, '_bfg_nonce', true, false );
				
				printf(
					'<tr><td>%s</td><td>%s</td><td>%s</td><td>
						<form method="post" action="%s" style="display:inline;">
							%s
							<input type="hidden" name="action" value="bfg_unblock_ip" />
							<input type="hidden" name="ip" value="%s" />
							<input type="submit" class="button" value="%s" />
						</form>
					</td></tr>',
					esc_attr($ip),
					esc_attr($attempts),
					esc_attr($time),
					esc_url( $action_url ),
					/* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ 
					$nonce_field,
					esc_attr( $row['ip'] ),
					esc_attr( 'Unblock', self::TEXT_DOMAIN )
				);
			}
		} else {
			echo '<tr><td colspan="4">' . esc_html( 'No blocked IPs.', self::TEXT_DOMAIN ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Handle unblock request from admin-post
	 */
	public function handle_unblock_ip() {
		// 1. Capability check (Authorization)
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( 'Not allowed', self::TEXT_DOMAIN ) );
		}

		$ip = isset( $_POST['ip'] ) ? sanitize_text_field( wp_unslash( $_POST['ip'] ) ) : '';
		if ( ! $ip ) {
			wp_safe_redirect( wp_get_referer() ?: admin_url( 'options-general.php?page=bfg-lite' ) );
			exit;
		}

		// 2. Nonce check (CSRF Protection)
		$nonce_action = 'bfg_unblock_ip_' . $ip;
		$nonce = isset( $_POST['_bfg_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_bfg_nonce'] ) ) : '';
		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			wp_die( esc_html( 'Nonce verification failed', self::TEXT_DOMAIN ) );
		}

		// 3. Action (SQL Injection safe via $wpdb->delete)
		// 管理画面からの「ブロック解除」時は、レコードを完全に削除して即座にブロックを解除します
		$this->wpdb->delete( $this->table_name, array( 'ip' => $ip ), array( '%s' ) );

		// 4. Redirect
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'options-general.php?page=bfg-lite' ) );
		exit;
	}

	/**
	 * Static uninstall - drop table and delete options.
	 * Called by procedural uninstall wrapper below.
	 */
	public static function uninstall() {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;
		// テーブル名は内部的に生成されたものであり、安全にエスケープして DROP TABLE に使用
		$table = esc_sql( $table );
		$sql = "DROP TABLE IF EXISTS `$table`";
		/* phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
		$wpdb->query( $sql );

		delete_option( self::OPTION_KEY );
	}
}

/* ------------------------
 Procedural activation / uninstall wrappers
 (register_*_hook requires callable in global scope)
 ------------------------ */

/**
 * Activation wrapper
 */
function bfg_plugin_activate() {
	BFG_WPTable::activate();
}
register_activation_hook( __FILE__, 'bfg_plugin_activate' );

/**
 * Uninstall wrapper
 */
function bfg_plugin_uninstall() {
	BFG_WPTable::uninstall();
}
register_uninstall_hook( __FILE__, 'bfg_plugin_uninstall' );

/* Initialize plugin */
$bfg_instance = new BFG_WPTable();
