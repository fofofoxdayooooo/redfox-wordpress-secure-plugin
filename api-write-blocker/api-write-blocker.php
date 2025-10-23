<?php
/**
 * Plugin Name: API Write Blocker
 * Description: Strictly blocks write operations such as creating, editing, and deleting posts via REST API, XML-RPC, and key Admin-Ajax endpoints.
 * Plugin URI: https://profiles.wordpress.org/teamredfox/
 * Version: 1.0
 * Author: Red Fox (team Red Fox)
 * Author URI: https://p-fox.jp/
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: api-write-blocker
 * Requires at least: 6.8
 * Requires PHP: 7.4
 */

// Protection against direct file access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin option prefix
define( 'AWB_OPTION_PREFIX', 'awb_');

// ----------------------------------------------------
// 1. Settings Menu and Admin Screen
// ----------------------------------------------------

add_action('admin_menu', 'awb_add_admin_menu');
add_action('admin_init', 'awb_settings_init');

/**
 * Adds a custom settings menu under "Settings"
 */
function awb_add_admin_menu() {
	add_options_page(
		esc_html__('API Write Restriction Settings', 'api-write-blocker'),
		esc_html__('API Write Restriction', 'api-write-blocker'),
		'manage_options',
		'awb-settings-page', // Settings page slug
		'awb_settings_page_callback'
	);
}

/**
 * Displays the content of the settings page
 */
function awb_settings_page_callback() {
	if ( ! current_user_can('manage_options') ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html(get_admin_page_title()); ?></h1>
		<form action="options.php" method="post">
			<?php
			settings_fields('awb_options_group');
			do_settings_sections('awb-settings-page');
			submit_button(esc_html__('Save Settings', 'api-write-blocker'));
			?>
		</form>
	</div>
	<?php
}

/**
 * Sanitizes the HTTP status code.
 * Limited to the range 100 to 599.
 * @param int $input Input value
 * @return int The sanitized status code
 */
function awb_sanitize_http_status_code($input) {
	$code = absint($input);
	if ($code < 100 || $code > 599) {
		// Return default 403
		return 403;
	}
	return $code;
}

/**
 * Registers settings and fields
 */
function awb_settings_init() {
	$options = [
		'is_enabled' => [
			'default' => 1,
			'type' => 'boolean',
			'sanitize' => 'absint',
		],
		// REST API individual method settings
		'block_rest_post' => [
			'default' => 1,
			'type' => 'boolean',
			'sanitize' => 'absint',
		],
		'block_rest_put_patch' => [
			'default' => 1,
			'type' => 'boolean',
			'sanitize' => 'absint',
		],
		'block_rest_delete' => [
			'default' => 1,
			'type' => 'boolean',
			'sanitize' => 'absint',
		],
		// REST API Route Whitelist
		'allowed_rest_routes' => [
			'default' => '',
			'type' => 'string',
			'sanitize' => 'awb_sanitize_rest_routes',
		],
		'block_xmlrpc' => [
			'default' => 1,
			'type' => 'boolean',
			'sanitize' => 'absint',
		],
		'allowed_ip' => [
			'default' => '',
			'type' => 'string',
			'sanitize' => 'awb_sanitize_ip_list',
		],
		// Admin-Ajax Action Whitelist
		'allowed_ajax_actions' => [
			'default' => '',
			'type' => 'string',
			'sanitize' => 'awb_sanitize_ajax_actions',
		],

		// --- Custom Error Message Settings ---
		// REST API
		'rest_error_message' => [
			'default' => esc_html__('Write operations to the REST API are forbidden by API Write Blocker.', 'api-write-blocker'),
			'type' => 'string',
			'sanitize' => 'sanitize_text_field'
		],
		'rest_status_code' => [
			'default' => 403,
			'type' => 'integer',
			'sanitize' => 'awb_sanitize_http_status_code'
		],
		// XML-RPC
		'xmlrpc_error_message' => [
			'default' => esc_html__('Write operations to XML-RPC are forbidden by API Write Blocker.', 'api-write-blocker'),
			'type' => 'string',
			'sanitize' => 'sanitize_text_field'
		],
		'xmlrpc_status_code' => [
			'default' => 403,
			'type' => 'integer',
			'sanitize' => 'awb_sanitize_http_status_code'
		],
		// Admin-Ajax
		'ajax_error_message' => [
			'default' => esc_html__('Write operations via Admin-Ajax are forbidden by API Write Blocker.', 'api-write-blocker'),
			'type' => 'string',
			'sanitize' => 'sanitize_text_field'
		],
		'ajax_status_code' => [
			'default' => 403,
			'type' => 'integer',
			'sanitize' => 'awb_sanitize_http_status_code'
		],
	];

	foreach ($options as $key => $props) {
		register_setting('awb_options_group', AWB_OPTION_PREFIX . $key, [
			'default'           => $props['default'],
			'type'              => $props['type'],
			'sanitize_callback' => $props['sanitize'],
		]);
	}

	// --- 1. General Settings Section ---
	add_settings_section(
		'awb_main_section',
		esc_html__('General, XML-RPC, IP, and Admin-Ajax Settings', 'api-write-blocker'),
		'awb_section_callback',
		'awb-settings-page'
	);

	// Field: Global ON/OFF
	add_settings_field(
		AWB_OPTION_PREFIX . 'is_enabled',
		esc_html__('Enable Plugin Functionality', 'api-write-blocker'),
		'awb_is_enabled_callback',
		'awb-settings-page',
		'awb_main_section'
	);
	// Field: XML-RPC Block
	add_settings_field(
		AWB_OPTION_PREFIX . 'block_xmlrpc',
		esc_html__('Block XML-RPC / Admin-Ajax Write Operations', 'api-write-blocker'),
		'awb_checkbox_callback',
		'awb-settings-page',
		'awb_main_section',
		['key' => AWB_OPTION_PREFIX . 'block_xmlrpc', 'description' => esc_html__('Blocks methods/actions related to posting, editing, and deleting via XML-RPC and Admin-Ajax.', 'api-write-blocker')]
	);
	// Field: IP Whitelist
	add_settings_field(
		AWB_OPTION_PREFIX . 'allowed_ip',
		esc_html__('IP Addresses to Exclude from Blocking (Whitelist)', 'api-write-blocker'),
		'awb_allowed_ip_callback',
		'awb-settings-page',
		'awb_main_section'
	);
	// Field: Admin-Ajax Action Whitelist
	add_settings_field(
		AWB_OPTION_PREFIX . 'allowed_ajax_actions',
		esc_html__('Admin-Ajax Action Whitelist', 'api-write-blocker'),
		'awb_allowed_ajax_actions_callback',
		'awb-settings-page',
		'awb_main_section'
	);

	// --- 2. REST API Settings Section ---
	add_settings_section(
		'awb_rest_section',
		esc_html__('REST API Block Settings (Individual Restrictions and Route Whitelist)', 'api-write-blocker'),
		'awb_rest_section_callback',
		'awb-settings-page'
	);

	// Field: POST Block
	add_settings_field(
		AWB_OPTION_PREFIX . 'block_rest_post',
		esc_html__('Block REST API POST Operations', 'api-write-blocker'),
		'awb_checkbox_callback',
		'awb-settings-page',
		'awb_rest_section',
		['key' => AWB_OPTION_PREFIX . 'block_rest_post', 'description' => esc_html__('Blocks POST requests (new creation, form submission, etc.).', 'api-write-blocker')]
	);
	// Field: PUT/PATCH Block
	add_settings_field(
		AWB_OPTION_PREFIX . 'block_rest_put_patch',
		esc_html__('Block REST API PUT/PATCH Operations', 'api-write-blocker'),
		'awb_checkbox_callback',
		'awb-settings-page',
		'awb_rest_section',
		['key' => AWB_OPTION_PREFIX . 'block_rest_put_patch', 'description' => esc_html__('Blocks PUT/PATCH requests (update operations).', 'api-write-blocker')]
	);
	// Field: DELETE Block
	add_settings_field(
		AWB_OPTION_PREFIX . 'block_rest_delete',
		esc_html__('Block REST API DELETE Operations', 'api-write-blocker'),
		'awb_checkbox_callback',
		'awb-settings-page',
		'awb_rest_section',
		['key' => AWB_OPTION_PREFIX . 'block_rest_delete', 'description' => esc_html__('Blocks DELETE requests (deletion operations).', 'api-write-blocker')]
	);

	// Field: REST API Route Whitelist
	add_settings_field(
		AWB_OPTION_PREFIX . 'allowed_rest_routes',
		esc_html__('REST API Route Whitelist (Prefix Match)', 'api-write-blocker'),
		'awb_allowed_rest_routes_callback',
		'awb-settings-page',
		'awb_rest_section'
	);

	// --- 3. Custom Error Message Settings Section ---
	add_settings_section(
		'awb_error_section',
		esc_html__('Custom Error Message Settings', 'api-write-blocker'),
		'awb_error_section_callback',
		'awb-settings-page'
	);

	// REST API Error
	awb_add_error_fields('REST API Block', 'rest');
	// XML-RPC Error
	awb_add_error_fields('XML-RPC Block', 'xmlrpc');
	// Admin-Ajax Error
	awb_add_error_fields('Admin-Ajax Block', 'ajax');
}

/**
 * Helper to add generic error message and status code fields
 */
function awb_add_error_fields($title, $prefix) {
	// Field: Error Message
	add_settings_field(
		AWB_OPTION_PREFIX . $prefix . '_error_message',
		/* translators: %s: Title of the component being configured (e.g., "REST API Block"). */
		sprintf(esc_html__('%s: Error Message', 'api-write-blocker'), $title),
		'awb_text_input_callback',
		'awb-settings-page',
		'awb_error_section',
		[
			'key' => AWB_OPTION_PREFIX . $prefix . '_error_message',
			'type' => 'text',
			'description' => esc_html__('The message returned to the client upon blocking.', 'api-write-blocker')
		]
	);

	// Field: Status Code
	add_settings_field(
		AWB_OPTION_PREFIX . $prefix . '_status_code',
		/* translators: %s: Title of the component being configured (e.g., "REST API Block"). */
		sprintf(esc_html__('%s: Status Code', 'api-write-blocker'), $title),
		'awb_text_input_callback',
		'awb-settings-page',
		'awb_error_section',
		[
			'key' => AWB_OPTION_PREFIX . $prefix . '_status_code',
			'type' => 'number',
			'description' => esc_html__('HTTP status code to return upon blocking (100-599). 403 is recommended for XML-RPC.', 'api-write-blocker')
		]
	);
}

/**
 * Sanitizes the list of IP addresses
 */
function awb_sanitize_ip_list($input) {
	$input = sanitize_textarea_field($input);
	$ips = array_map('trim', explode(',', $input));
	$valid_ips = [];

	foreach ($ips as $ip) {
		if (empty($ip)) continue;
		// Strengthen check for IP address or CIDR format
		if (filter_var($ip, FILTER_VALIDATE_IP) || preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\/\d{1,2}$/', $ip)) {
			$valid_ips[] = $ip;
		}
	}
	return implode(', ', $valid_ips);
}

/**
 * Sanitizes the REST API route list
 * (Sanitizing as plain text line list)
 */
function awb_sanitize_rest_routes($input) {
	$input = sanitize_textarea_field($input);
	$routes = array_map('trim', explode("\n", $input));
	$valid_routes = [];
	foreach ($routes as $route) {
		if (empty($route)) continue;
		// Remove leading slash and save as simple text
		$route = ltrim($route, '/');
		$valid_routes[] = $route;
	}
	// Save separated by newlines
	return implode("\n", $valid_routes);
}

/**
 * Sanitizes the Admin-Ajax action list
 */
function awb_sanitize_ajax_actions($input) {
	$input = sanitize_textarea_field($input);
	$actions = array_map('trim', explode("\n", $input));
	$valid_actions = [];
	foreach ($actions as $action) {
		if (empty($action)) continue;
		// Only allow action names (alphanumeric, hyphen, underscore)
		if (preg_match('/^[\w\-]+$/i', $action)) {
			$valid_actions[] = $action;
		}
	}
	// Save separated by newlines
	return implode("\n", $valid_actions);
}

/**
 * Displays the description for the general settings section
 */
function awb_section_callback() {
	$current_ip = awb_get_user_ip();

	$notice = sprintf(
		/* translators: %s: The user's current IP address. */
		esc_html__(
			'Your current IP address is %s. If you are testing, please whitelist this IP.',
			'api-write-blocker'
		),
		'<strong>' . esc_html( $current_ip ) . '</strong>'
	);

	echo '<p>' . esc_html__(
		'Enabling this setting significantly restricts unauthorized write operations from external sources. Logged-in users are always permitted operations, regardless of IP/route/action whitelists.',
		'api-write-blocker'
	) . '</p>';

	echo '<div class="notice notice-warning inline"><p>' . wp_kses_post( $notice ) . '</p></div>';
}

/**
 * Displays the description for the REST API settings section
 */
function awb_rest_section_callback() {
	echo '<p>' . esc_html__('Finely control REST API write operations by HTTP method. Adding routes to the whitelist allows you to exclude specific API calls from being blocked.', 'api-write-blocker') . '</p>';
	echo '<div class="notice notice-info inline"><p><strong>' . esc_html__('Importance of Route Whitelist:', 'api-write-blocker') . '</strong> ' . esc_html__('Route whitelist validation takes precedence over HTTP method blocking. This allows you to correctly permit unauthenticated POST requests like those from Contact Form 7.', 'api-write-blocker') . '</p></div>';
}

/**
 * Displays the description for the custom error settings section
 */
function awb_error_section_callback() {
	echo '<p>' . esc_html__('You can customize the message and status code returned to the client when a block occurs.', 'api-write-blocker') . '</p>';
}

/**
 * HTML for the global ON/OFF checkbox
 */
function awb_is_enabled_callback() {
	$option_name = AWB_OPTION_PREFIX . 'is_enabled';
	$is_enabled = get_option($option_name, 1);
	?>
	<label for="<?php echo esc_attr($option_name); ?>">
		<input type="checkbox" id="<?php echo esc_attr($option_name); ?>" name="<?php echo esc_attr($option_name); ?>" value="1" <?php checked(1, $is_enabled); ?>>
		<?php esc_html_e('Enable API Write Blocker functionality', 'api-write-blocker'); ?>
	</label>
	<?php
}

/**
 * HTML for a generic checkbox
 */
function awb_checkbox_callback($args) {
	$option_name = $args['key'];
	$is_checked = get_option($option_name);
	?>
	<label for="<?php echo esc_attr($option_name); ?>">
		<input type="checkbox" id="<?php echo esc_attr($option_name); ?>" name="<?php echo esc_attr($option_name); ?>" value="1" <?php checked(1, $is_checked); ?>>
		<?php echo esc_html($args['description']); ?>
	</label>
	<?php
}

/**
 * HTML for a generic text input field
 */
function awb_text_input_callback($args) {
	$option_name = $args['key'];
	$value = get_option($option_name);
	$type = $args['type'] ?? 'text';
	$description = $args['description'] ?? '';

	// Set min/max for status code
	$min = ($type === 'number') ? 100 : '';
	$max = ($type === 'number') ? 599 : '';
	?>
	<input type="<?php echo esc_attr($type); ?>"
			id="<?php echo esc_attr($option_name); ?>"
			name="<?php echo esc_attr($option_name); ?>"
			value="<?php echo esc_attr($value); ?>"
			class="regular-text"
			<?php if ($type === 'number') : ?>
				min="<?php echo esc_attr($min); ?>"
				max="<?php echo esc_attr($max); ?>"
			<?php endif; ?>
			aria-describedby="<?php echo esc_attr($option_name); ?>-description">
	<?php if ( ! empty($description) ) : ?>
		<p class="description" id="<?php echo esc_attr($option_name); ?>-description"><?php echo esc_html($description); ?></p>
	<?php endif; ?>
	<?php
}

/**
 * HTML for the IP address input field
 */
function awb_allowed_ip_callback() {
	$option_name = AWB_OPTION_PREFIX . 'allowed_ip';
	$allowed_ip = get_option($option_name, '');
	?>
	<textarea name="<?php echo esc_attr($option_name); ?>"
			 id="<?php echo esc_attr($option_name); ?>"
			 rows="3"
			 cols="50"
			 class="large-text code"
			 placeholder="<?php esc_attr_e('Example: 192.168.1.1, 10.0.0.0/24', 'api-write-blocker'); ?>"
			 aria-describedby="awb-ip-description"><?php echo esc_textarea($allowed_ip); ?></textarea>
	<p class="description" id="awb-ip-description"><?php esc_html_e('Enter IP addresses or CIDR ranges, separated by commas, to exempt from blocking. Write operations from unauthenticated access not on this list will be blocked.', 'api-write-blocker'); ?></p>
	<?php
}

/**
 * HTML for the REST API route whitelist input field
 */
function awb_allowed_rest_routes_callback() {
	$option_name = AWB_OPTION_PREFIX . 'allowed_rest_routes';
	$allowed_routes = get_option($option_name, '');
	?>
	<textarea name="<?php echo esc_attr($option_name); ?>"
				 id="<?php echo esc_attr($option_name); ?>"
				 rows="5"
				 cols="80"
				 class="large-text code"
				 placeholder="<?php esc_attr_e('Example (1 route per line):\ncontact-form-7/v1/contact-forms/\nmy-plugin/v1/public-data', 'api-write-blocker'); ?>"
				 aria-describedby="awb-routes-description"><?php echo esc_textarea($allowed_routes); ?></textarea>
	<p class="description" id="awb-routes-description">
		<?php esc_html_e('Enter one REST API route (without base URL) per line to exempt from blocking.', 'api-write-blocker'); ?><br>
		<strong><?php esc_html_e('Important:', 'api-write-blocker'); ?></strong><?php esc_html_e('This list works by **prefix match**. For Contact Form 7, register the route up to before the variable ID, like `contact-form-7/v1/contact-forms/`.', 'api-write-blocker'); ?>
	</p>
	<?php
}

/**
 * HTML for the Admin-Ajax action whitelist input field
 */
function awb_allowed_ajax_actions_callback() {
	$option_name = AWB_OPTION_PREFIX . 'allowed_ajax_actions';
	$allowed_actions = get_option($option_name, '');
	?>
	<textarea name="<?php echo esc_attr($option_name); ?>"
			 id="<?php echo esc_attr($option_name); ?>"
			 rows="5"
			 cols="50"
			 class="large-text code"
			 placeholder="<?php esc_attr_e('Example (1 action per line):\nwpcf7-submit\nmy_plugin_form_submit', 'api-write-blocker'); ?>"
			 aria-describedby="awb-ajax-description"><?php echo esc_textarea($allowed_actions); ?></textarea>
	<p class="description" id="awb-ajax-description">
		<?php esc_html_e('Enter one **action name** per line to exempt from blocking via Admin-Ajax.', 'api-write-blocker'); ?><br>
		<?php esc_html_e('This allows specific plugin-specific operations by unauthenticated users, such as Contact Form 7 form submission (usually `wpcf7-submit`), to be permitted.', 'api-write-blocker'); ?>
	</p>
	<?php
}

// ----------------------------------------------------
// 2. Core Blocking Logic
// ----------------------------------------------------

/**
 * Common check: is the plugin enabled and is the IP whitelisted?
 * @return bool true if blocking should be skipped (permitted)
 */
function awb_should_bypass_blocker() {
	// 1. Skip if the global setting is disabled
	if ( ! (bool) get_option(AWB_OPTION_PREFIX . 'is_enabled', 1) ) {
		return true;
	}

	// 2. Logged-in users are always permitted (only restricting external write attempts from unauthenticated users)
	if ( is_user_logged_in() ) {
		return true;
	}

	// 3. IP Whitelist check (applies to unauthenticated access)
	$allowed_ips_str = get_option(AWB_OPTION_PREFIX . 'allowed_ip', '');
	$allowed_ips = array_map('trim', explode(',', $allowed_ips_str));
	$current_ip = awb_get_user_ip();

	if ( $current_ip && ! empty( $allowed_ips ) ) {
		foreach ( $allowed_ips as $ip_or_cidr ) {
			if ( empty( $ip_or_cidr ) ) continue;
			// CIDR range check
			if ( strpos( $ip_or_cidr, '/' ) !== false ) {
				if ( awb_ip_in_range( $current_ip, $ip_or_cidr ) ) {
					return true;
				}
			} elseif ( $current_ip === $ip_or_cidr ) { // Single IP check
				return true;
			}
		}
	}

	return false; // Blocking required (unauthenticated & IP not in list)
}

/**
 * Gets the user's IP address (using WP standard functions, compatible with proxy/CDN)
 */
function awb_get_user_ip() {
	$ip_keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];

	foreach ($ip_keys as $key) {
		if (!empty($_SERVER[$key])) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$header_value = wp_unslash( $_SERVER[$key] );
			$ip_list = explode(',', $header_value);
			$ip = trim($ip_list[0]);

			if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
				return $ip;
			}
		}
	}
	return false;
}

/**
 * Checks if an IP address is within a CIDR range
 */
function awb_ip_in_range( $ip, $range ) {
	if ( strpos( $range, '/' ) === false ) {
		return $ip === $range;
	}
	list( $range, $netmask ) = explode( '/', $range, 2 );
	$range_long = ip2long( $range );
	$ip_long = ip2long( $ip );
	$netmask_long = ~(pow( 2, ( 32 - $netmask ) ) - 1);
	return ( $ip_long & $netmask_long ) == ( $range_long & $netmask_long );
}

/**
 * Checks if the REST API route is included in the whitelist (prefix match)
 * @param string $route Current request route
 * @return bool true if allowed
 */
function awb_is_rest_route_allowed( $route ) {
	$allowed_routes_str = get_option( AWB_OPTION_PREFIX . 'allowed_rest_routes', '' );
	if ( empty( $allowed_routes_str ) ) {
		return false;
	}

	$routes = array_filter( array_map( 'trim', explode( "\n", $allowed_routes_str ) ) );
	if ( empty( $routes ) ) {
		return false;
	}

	$route = ltrim( $route, '/' ); // Remove leading slash from the request route

	foreach ( $routes as $allowed_route ) {
		if ( empty( $allowed_route ) ) continue;

		$allowed_route = ltrim( $allowed_route, '/' ); // Remove leading slash from the whitelist route

		// Check for prefix match using strpos()
		if ( strpos( $route, $allowed_route ) === 0 ) {
			return true;
		}
	}

	return false;
}

/**
 * Checks if the Admin-Ajax action is included in the whitelist
 * @param string $action Current request action
 * @return bool true if allowed
 */
function awb_is_ajax_action_allowed( $action ) {
	$allowed_actions_str = get_option( AWB_OPTION_PREFIX . 'allowed_ajax_actions', '' );
	if ( empty( $allowed_actions_str ) ) {
		return false;
	}

	$actions = array_filter( array_map( 'trim', explode( "\n", $allowed_actions_str ) ) );

	// Check if the action exactly matches an item in the list
	return in_array( $action, $actions, true );
}


// --- REST API Blocking Logic ---
add_filter('rest_pre_dispatch', 'awb_rest_pre_dispatch_blocker', 0, 3);

/**
 * Blocks REST API write operations (rest_pre_dispatch)
 */
function awb_rest_pre_dispatch_blocker($result, $server, $request) {
	// 1. Skip if global bypass conditions are met (logged-in, IP whitelist)
	if ( awb_should_bypass_blocker() ) {
		return $result;
	}

	// 2. Route Whitelist check **(Highest Priority)**
	// If it matches the whitelist, permit regardless of the method
	if ( awb_is_rest_route_allowed( $request->get_route() ) ) {
		return $result;
	}

	// 3. Blocking check based on write method
	$method = $request->get_method();

	$block_post = (bool) get_option(AWB_OPTION_PREFIX . 'block_rest_post', 1);
	$block_put_patch = (bool) get_option(AWB_OPTION_PREFIX . 'block_rest_put_patch', 1);
	$block_delete = (bool) get_option(AWB_OPTION_PREFIX . 'block_rest_delete', 1);

	$is_blocked_method = false;

	if ( $method === 'POST' && $block_post ) {
		$is_blocked_method = true;
	} elseif ( in_array( $method, array( 'PUT', 'PATCH' ), true ) && $block_put_patch ) {
		$is_blocked_method = true;
	} elseif ( $method === 'DELETE' && $block_delete ) {
		$is_blocked_method = true;
	}

	if ( $is_blocked_method ) {
		// Get custom error message and status code
		$message = get_option(AWB_OPTION_PREFIX . 'rest_error_message');
		$status = get_option(AWB_OPTION_PREFIX . 'rest_status_code');

		// Block unauthorized write operation
		return new WP_Error(
			'awb_rest_write_forbidden',
			esc_html($message), // Message is escaped with esc_html
			['status' => absint($status)]
		);
	}

	return $result;
}


// --- XML-RPC Blocking ---
add_filter('xmlrpc_methods', 'awb_block_xmlrpc_methods');

/**
 * Overwrites XML-RPC write-related methods to assign a block handler
 */
function awb_block_xmlrpc_methods($methods) {
	// 1. Skip if XML-RPC blocking is disabled in settings (linked to Admin-Ajax)
	if ( ! (bool) get_option(AWB_OPTION_PREFIX . 'block_xmlrpc', 1) ) {
		return $methods;
	}

	// 2. Skip if IP whitelisted or user is logged in
	if ( awb_should_bypass_blocker() ) {
		return $methods;
	}

	// 3. Methods related to post creation/editing/deletion are targeted for blocking
	$writing_methods = [
		'wp.newPost',
		'wp.editPost',
		'wp.deletePost',
		'metaWeblog.newPost',
		'metaWeblog.editPost',
		'blogger.newPost',
		'blogger.editPost',
		'wp.uploadFile',
	];

	foreach ($writing_methods as $method) {
		$methods[$method] = 'awb_xmlrpc_block_handler';
	}

	return $methods;
}

/**
 * XML-RPC Block Handler
 */
function awb_xmlrpc_block_handler() {
	// Get custom error message and status code
	$message = get_option(AWB_OPTION_PREFIX . 'xmlrpc_error_message');
	// In XML-RPC, we use IXR_Error codes, so we also use the status code
	$status = get_option(AWB_OPTION_PREFIX . 'xmlrpc_status_code');

	// IXR_Error is required for WP core XML-RPC processing. Use the HTTP status code as the error code.
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
	return new IXR_Error(absint($status), esc_html($message));
}


// --- Admin-Ajax Blocking ---
add_action('init', 'awb_block_admin_ajax_write', 0);

/**
 * Blocks write operations via Admin-Ajax
 */
function awb_block_admin_ajax_write() {
	// 1. Check if it's an Admin-Ajax request
	if ( ! ( defined('DOING_AJAX') && DOING_AJAX ) ) {
		return;
	}

	// 2. Check if it's a POST request (Admin-Ajax write operations are usually POST)
	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) !== 'POST' ) {
		return;
	}

	// 3. Check if XML-RPC block is enabled (Admin-Ajax linked to XML-RPC setting)
	if ( ! (bool) get_option(AWB_OPTION_PREFIX . 'block_xmlrpc', 1) ) {
		return;
	}

	// 4. Check global settings and bypass conditions
	if ( awb_should_bypass_blocker() ) {
		return;
	}

	// 5. Get the Admin-Ajax action
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$action = isset($_REQUEST['action']) ? sanitize_key($_REQUEST['action']) : '';

	// 6. Skip if action is in the whitelist
	if ( ! empty( $action ) && awb_is_ajax_action_allowed( $action ) ) {
		return;
	}

	// 7. Check for Admin-Ajax writing actions
	$writing_actions = [
		'edit-post',
		'delete-post',
		'add-post',
		'inline-save',
		'save-post',
		'trash-post',
		'untrash-post',
		'upload-attachment',
		'set-attachment-thumbnail',
		'add-user',
		'delete-comment',
	];

	if ( in_array($action, $writing_actions, true) ) {
		// Get custom error message and status code
		$message = get_option(AWB_OPTION_PREFIX . 'ajax_error_message');
		$status = get_option(AWB_OPTION_PREFIX . 'ajax_status_code');

		// Send 403 Forbidden header and terminate processing
		wp_send_json_error(
			[
				'message' => esc_html($message)
			],
			absint($status)
		);
	}
}


// --------------------------------------------------------
// 3. Uninstall Process (Cleanup)
// --------------------------------------------------------

/**
 * Cleans up database options when the plugin is deleted
 */
function awb_uninstall_cleanup() {
	$options_to_delete = [
		'is_enabled', 'block_rest_post', 'block_rest_put_patch', 'block_rest_delete',
		'allowed_rest_routes', 'block_xmlrpc', 'allowed_ip', 'allowed_ajax_actions',
		'rest_error_message', 'rest_status_code', 'xmlrpc_error_message', 'xmlrpc_status_code',
		'ajax_error_message', 'ajax_status_code'
	];

	foreach ($options_to_delete as $option) {
		delete_option( AWB_OPTION_PREFIX . $option );
	}
}
// Register function to be executed when the plugin is uninstalled
register_uninstall_hook( __FILE__, 'awb_uninstall_cleanup' );
