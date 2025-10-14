<?php
/**
 * Plugin Name: REST API Shield & XML-RPC Blocker
 * Description: A security plugin that controls XML-RPC access and specific WordPress REST API endpoints from anonymous users.
 * Plugin URI: https://p-fox.jp/blog/archive/367/
 * Version: 1.0
 * Author: Red Fox(team Red Fox)
 * Author URI: https://p-fox.jp/
 * Contributors: teamredfox
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: rest-api-shield-xml-rpc-blocker 
 * Requires at least: 6.8
 * Requires PHP: 7.4
 */

// Protection against direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * =========================================================
 * PART 1: XML-RPC Control Feature
 * =========================================================
 */

/**
 * WordPress XML-RPC Blocker (Controllable via settings)
 *
 * @param bool $enabled Current XML-RPC enabled/disabled status
 * @return bool
 */
function wpashield_control_xmlrpc_status( $enabled ) {
    $is_block_enabled = get_option( 'wpashield_block_xmlrpc' );

    if ( $is_block_enabled === '1' ) {
        return false;
    }

    return $enabled;
}
add_filter( 'xmlrpc_enabled', 'wpashield_control_xmlrpc_status' );

/**
 * Terminates access with the configured error code and message if an XML-RPC request is active
 */
function wpashield_block_xmlrpc_prank() {
    $is_block_enabled = get_option( 'wpashield_block_xmlrpc' );
    $raw_error_code   = get_option( 'wpashield_xml_error_code', '403' );
    $is_error_code    = absint( $raw_error_code );

    // Execute only if it is an XML-RPC request AND blocking is enabled
    if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST && $is_block_enabled === '1' ) {

        status_header( $is_error_code );
        
        $custom_error_message = get_option('wpashield_xml_error_message');
        $default_message = __( "Access is denied. It is part of our efforts to prevent information leaks.", 'rest-api-shield-xml-rpc-blocker' );
        
        $error_message = empty($custom_error_message) 
                          ? $default_message
                          : $custom_error_message;

        // L81: Output of unescaped variable `$error_message`
        // The error message is not HTML, so apply simple text escaping (esc_html).
        exit( esc_html( $error_message ) );
    }
}
add_action( 'init', 'wpashield_block_xmlrpc_prank', 1 );

/**
 * =========================================================
 * PART 2: REST API Anonymous Access Restriction Control Feature
 * =========================================================
 */

/**
 * Restricts user-related REST endpoints during anonymous access
 */
add_filter( 'rest_pre_dispatch', function( $result, $server, $request ) {
    // ... (Logic within the function remains unchanged)
    $is_block_enabled = get_option( 'wpashield_block_rest_anon' );
    $raw_error_code   = get_option( 'wpashield_rest_error_code', '403' );
    $is_error_code    = absint( $raw_error_code );

    // If blocking is not enabled, exit without processing
    if ( $is_block_enabled !== '1' ) {
        return $result;
    }

    // Do not affect if a result already exists, there is an error, or the user is logged in
    if (true === $result || is_wp_error($result) || is_user_logged_in()) {
      return $result;
    }

    $route = $request->get_route();

    if ( empty( $route ) ) {
        return $result;
    }

    // Remove the leading '/' from the route
    $route_cleaned = ltrim( $route, '/' );

    // --- Whitelist (Allow List) Check ---
    $allowed_routes_raw  = get_option( 'wpashield_allowed_rest_routes' );
    $allowed_routes_list = array_filter( array_map( 'trim', explode( "\n", $allowed_routes_raw ) ) );
    
    // Detect static or dynamic whitelist entries
    if ( ! empty( $allowed_routes_list ) ) {
        $pattern_part = implode( '|', array_map( 'preg_quote', $allowed_routes_list ) );
        $regex_pattern_allow = '#^/(' . $pattern_part . ')(?:/.*)?$#i';

        if ( preg_match( $regex_pattern_allow, $route ) ) {
            return $result; // Allowed (Passed whitelist)
        }
    }
    // --- Whitelist (Allow List) Check END ---


    // --- Blacklist (Block List) Check ---

    $blocked_routes_match = false;

    // 1. Block list for core endpoints under wp/v2/
    $blocked_endpoints_core_raw = get_option( 'wpashield_blocked_rest_routes' );
    $endpoints_core_list        = array_filter( array_map( 'trim', explode( "\n", $blocked_endpoints_core_raw ) ) );

    if ( ! empty( $endpoints_core_list ) ) {
        $pattern_part_core = implode( '|', array_map( 'preg_quote', $endpoints_core_list ) );
        $regex_pattern_core = '#^/wp/v2/(' . $pattern_part_core . ')(?:/.*)?$#';
        
        if ( preg_match( $regex_pattern_core, $route ) ) {
            $blocked_routes_match = true;
        }
    }

    // 2. Broad block list for plugins and custom endpoints
    $blocked_endpoints_plugin_raw = get_option( 'wpashield_blocked_rest_plugin' );
    $endpoints_plugin_list        = array_filter( array_map( 'trim', explode( "\n", $blocked_endpoints_plugin_raw ) ) );

    if ( ! $blocked_routes_match && ! empty( $endpoints_plugin_list ) ) {
        $pattern_part_plugin = implode( '|', array_map( 'preg_quote', $endpoints_plugin_list ) );
        $regex_pattern_plugin = '#^/(' . $pattern_part_plugin . ')(?:/.*)?$#';
        
        if ( preg_match( $regex_pattern_plugin, $route ) ) {
            $blocked_routes_match = true;
        }
    }

    // --- Blacklist (Block List) Check END ---

    // If the route matches a block target
    if ( $blocked_routes_match ) { 
        
        $custom_error_message = get_option('wpashield_rest_error_message');
        $default_message = __( "Access is denied. It is part of our efforts to prevent information leaks.", 'rest-api-shield-xml-rpc-blocker' );
        
        $error_message = empty($custom_error_message) 
                          ? $default_message
                          : $custom_error_message;

        // Return a standardized error (same format as when an ID doesn't exist)
        return new WP_Error(
            "rest_data_access_forbidden",
            // REST API error messages are used as strings in the WP_Error object, so no escaping is necessary here
            $error_message, 
            array( 'status' => $is_error_code )
        );
    }

    return $result;
}, 1, 3 ); 


/**
 * =========================================================
 * PART 3: Admin Screen: Add Control Panel using Settings API
 * =========================================================
 */

/**
 * Admin Screen: Adds security setting fields to the "Settings" -> "General" page.
 */
function wpashield_settings_init() {
    // Register the settings options
    register_setting( 'general', 'wpashield_block_xmlrpc', array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '1', 
        'show_in_rest'      => false,
    ) );
    register_setting( 'general', 'wpashield_block_rest_anon', array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '1', 
        'show_in_rest'      => false,
    ) );
    register_setting( 'general', 'wpashield_blocked_rest_routes', array(
        'type'              => 'string',
        'sanitize_callback' => 'wp_kses_post',
        'default'           => "users\ncomments\nmedia",
        'show_in_rest'      => false,
    ) );
    register_setting( 'general', 'wpashield_blocked_rest_plugin', array(
        'type'              => 'string',
        'sanitize_callback' => 'wp_kses_post',
        'default'           => "",
        'show_in_rest'      => false,
    ) );
    register_setting( 'general', 'wpashield_allowed_rest_routes', array(
        'type'              => 'string',
        'sanitize_callback' => 'wp_kses_post',
        'default'           => "wp/v2/posts\nwp/v2/pages\noembed/1.0",
        'show_in_rest'      => false,
    ) );
    register_setting( 'general', 'wpashield_rest_error_message', array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field', 
        'default'           => 'Access is denied. This measure is active to prevent information leaks of user and media data.',
        'show_in_rest'      => false,
    ) );
    register_setting( 'general', 'wpashield_xml_error_message', array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field', 
        'default'           => 'Access is denied. This measure is active to prevent information leaks of user and media data.',
        'show_in_rest'      => false,
    ) );
    register_setting( 'general', 'wpashield_rest_error_code', array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '403',
        'show_in_rest'      => false,
    ) );
    register_setting( 'general', 'wpashield_xml_error_code', array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '403',
        'show_in_rest'      => false,
    ) );

    // 7. Add settings section
    add_settings_section(
        'wpashield_security_settings_section',
        __( 'API Security Settings', 'rest-api-shield-xml-rpc-blocker' ),
        'wpashield_security_settings_section_callback',
        'general' // Added to the General settings page
    );

    // 8. Add XML-RPC settings field
    add_settings_field(
        'wpashield_block_xmlrpc',
        __( 'XML-RPC Access Control', 'rest-api-shield-xml-rpc-blocker' ),
        'wpashield_block_xmlrpc_callback',
        'general',
        'wpashield_security_settings_section'
    );
    
    // 9. Add REST API enable/disable settings field
    add_settings_field(
        'wpashield_block_rest_anon',
        __( 'REST API Anonymous User Data Exposure Restriction', 'rest-api-shield-xml-rpc-blocker' ),
        'wpashield_block_rest_anon_callback',
        'general',
        'wpashield_security_settings_section'
    );

    // 10. Add REST API route settings fields
    add_settings_field(
        'wpashield_blocked_rest_routes',
        __( 'Core Blocked REST API Endpoints (wp/v2/)', 'rest-api-shield-xml-rpc-blocker' ),
        'wpashield_blocked_rest_routes_callback',
        'general',
        'wpashield_security_settings_section'
    );
    add_settings_field(
        'wpashield_blocked_rest_plugin',
        __( 'Broader Blocked REST API Endpoints (Custom)', 'rest-api-shield-xml-rpc-blocker' ),
        'wpashield_blocked_rest_plugin_callback',
        'general',
        'wpashield_security_settings_section'
    );
    add_settings_field(
        'wpashield_allowed_rest_routes',
        __( 'REST API Routes Allowed for Anonymous Access (Whitelist)', 'rest-api-shield-xml-rpc-blocker' ),
        'wpashield_allowed_rest_routes_callback',
        'general',
        'wpashield_security_settings_section'
    );

    // 11. Add error code/message settings fields
    add_settings_field(
        'wpashield_rest_error_code',
        __( 'REST API Error Code', 'rest-api-shield-xml-rpc-blocker' ),
        'wpashield_rest_error_code_callback',
        'general',
        'wpashield_security_settings_section'
    );

    add_settings_field(
        'wpashield_xml_error_code',
        __( 'XML-RPC Error Code', 'rest-api-shield-xml-rpc-blocker' ),
        'wpashield_xml_error_code_callback',
        'general',
        'wpashield_security_settings_section'
    );

    add_settings_field(
        'wpashield_rest_error_message',
        __( 'REST API Block Error Message', 'rest-api-shield-xml-rpc-blocker' ),
        'wpashield_rest_error_message_callback',
        'general',
        'wpashield_security_settings_section'
    );
    add_settings_field(
        'wpashield_xml_error_message',
        __( 'XML-RPC Block Error Message', 'rest-api-shield-xml-rpc-blocker' ),
        'wpashield_xml_error_message_callback',
        'general',
        'wpashield_security_settings_section'
    );
}
add_action( 'admin_init', 'wpashield_settings_init' );

/**
 * Setting Section Heading Description
 */
function wpashield_security_settings_section_callback() {
    // L384: Use esc_html__ to translate and escape for simple text output simultaneously
    echo '<p>' . esc_html__( 'Adjust security settings for WordPress REST API and XML-RPC.', 'rest-api-shield-xml-rpc-blocker' ) . '</p>';
}

/**
 * Render XML-RPC Settings Field (Checkbox)
 */
function wpashield_block_xmlrpc_callback() {
    $is_enabled = get_option( 'wpashield_block_xmlrpc' ) === '1' ? 'checked="checked"' : '';

    echo '<label for="wpashield_block_xmlrpc">';
    // L396: Use esc_attr for HTML attribute value (checked="checked")
    echo '<input name="wpashield_block_xmlrpc" type="checkbox" id="wpashield_block_xmlrpc" value="1" ' . esc_attr( $is_enabled ) . ' />';
    // L398: Use esc_html__ for simple text output
    echo ' ' . esc_html__( 'Block XML-RPC requests (Recommended).', 'rest-api-shield-xml-rpc-blocker' );
    echo '</label>';
    // L401: Use wp_kses_post because HTML tags are used in the description
    echo '<p class="description">' . wp_kses_post( __( 'If checked, access to XML-RPC (/xmlrpc.php) is completely disabled. Uncheck this if remote posting from mobile apps or similar services is required.', 'rest-api-shield-xml-rpc-blocker' ) ) . '</p>';
}

/**
 * Render REST API Settings Field (Checkbox)
 */
function wpashield_block_rest_anon_callback() {
    $is_enabled = get_option( 'wpashield_block_rest_anon' ) === '1' ? 'checked="checked"' : '';

    echo '<label for="wpashield_block_rest_anon">';
    // L413: Use esc_attr for HTML attribute value (checked="checked")
    echo '<input name="wpashield_block_rest_anon" type="checkbox" id="wpashield_block_rest_anon" value="1" ' . esc_attr( $is_enabled ) . ' />';
    // L415: Use esc_html__ for simple text output
    echo ' ' . esc_html__( 'Restrict blocked REST API endpoints during anonymous access (Recommended).', 'rest-api-shield-xml-rpc-blocker' );
    echo '</label>';
    // L418: Use wp_kses_post because HTML tags are used in the description
    echo '<p class="description">' . wp_kses_post( __( 'If checked, requests from logged-out users to endpoints like /users or /comments will return the specified error.', 'rest-api-shield-xml-rpc-blocker' ) ) . '</p>';
}

/**
 * Render Core Blocked Route Settings Field (Textarea)
 */
function wpashield_blocked_rest_routes_callback() {
    $routes = get_option( 'wpashield_blocked_rest_routes' );

    // Textarea value is already escaped with esc_textarea
    echo '<textarea name="wpashield_blocked_rest_routes" id="wpashield_blocked_rest_routes" class="large-text code" rows="5" cols="50">' . esc_textarea( $routes ) . '</textarea>';
    // L430: Use wp_kses_post because HTML tags (<strong>, <code>) are included in the description
    echo '<p class="description">' . wp_kses_post( __( 'Enter the <strong>base name of the endpoint</strong> following "wp/v2/" on <strong>separate lines</strong> (e.g., <code>users</code>, <code>media</code>). This protects these endpoints and their child routes (e.g., /users/1) from anonymous access.', 'rest-api-shield-xml-rpc-blocker' ) ) . '</p>';
}

/**
 * Render Broad Blocked Route Settings Field (Textarea)
 */
function wpashield_blocked_rest_plugin_callback() {
    $routes = get_option( 'wpashield_blocked_rest_plugin' );

    echo '<textarea name="wpashield_blocked_rest_plugin" id="wpashield_blocked_rest_plugin" class="large-text code" rows="5" cols="50">' . esc_textarea( $routes ) . '</textarea>';
    // L442: Use wp_kses_post because HTML tags are included in the description
    echo '<p class="description">' . wp_kses_post( __( 'Enter the <strong>base route prefix of the endpoint</strong> on <strong>separate lines</strong> (e.g., <code>my-plugin/v1</code>). This blocks all routes starting with this prefix. Note: Since this is a broad block, be cautious as it may interfere with the functionality of some plugins.', 'rest-api-shield-xml-rpc-blocker' ) ) . '</p>';
}

/**
 * Render Allowed Route (Whitelist) Settings Field (Textarea)
 */
function wpashield_allowed_rest_routes_callback() {
    $routes = get_option( 'wpashield_allowed_rest_routes' );

    echo '<textarea name="wpashield_allowed_rest_routes" id="wpashield_allowed_rest_routes" class="large-text code" rows="5" cols="50">' . esc_textarea( $routes ) . '</textarea>';
    // L454: Use wp_kses_post because HTML tags (<br>, <strong>) are included in the description
    echo '<p class="description">' . wp_kses_post( __( 'Enter the <strong>prefix of the REST API route you wish to allow anonymous access to</strong>, on <strong>separate lines</strong> (e.g., <code>wp/v2/posts</code>, <code>custom/v1/data</code>).<br><strong>Routes not in this list will be restricted if they are also included in the block list.</strong>', 'rest-api-shield-xml-rpc-blocker' ) ) . '</p>';
}

/**
 * Render REST API Error Message Settings Field (Textarea)
 */
function wpashield_rest_error_message_callback() {
    $message = get_option( 'wpashield_rest_error_message' );

    // Textarea value is already escaped with esc_textarea
    echo '<textarea name="wpashield_rest_error_message" id="wpashield_rest_error_message" class="large-text code" rows="3" cols="50">' . esc_textarea( $message ) . '</textarea>';
    // L466: Use esc_html__ because no HTML tags are included in the description
    echo '<p class="description">' . esc_html__( 'The content of the error message returned when REST API access is blocked.', 'rest-api-shield-xml-rpc-blocker' ) . '</p>';
}

/**
 * Render XML-RPC Error Message Settings Field (Textarea)
 */
function wpashield_xml_error_message_callback() {
    $message = get_option( 'wpashield_xml_error_message' );

    // Textarea value is already escaped with esc_textarea
    echo '<textarea name="wpashield_xml_error_message" id="wpashield_xml_error_message" class="large-text code" rows="3" cols="50">' . esc_textarea( $message ) . '</textarea>';
    // L478: Use esc_html__ because no HTML tags are included in the description
    echo '<p class="description">' . esc_html__( 'The content of the error message returned when XML-RPC access is blocked.', 'rest-api-shield-xml-rpc-blocker' ) . '</p>';
}

/**
 * Render REST API Error Code Settings Field (Input)
 */
function wpashield_rest_error_code_callback() {
    $code = get_option( 'wpashield_rest_error_code' );

    // Input value is escaped with esc_attr
    echo '<input name="wpashield_rest_error_code" id="wpashield_rest_error_code" class="regular-text code" value="' . esc_attr( $code ) . '" />';
    // L489: Use esc_html__ because no HTML tags are included in the description
    echo '<p class="description">' . esc_html__( 'Specify the HTTP status code for the REST API error response (e.g., 403, 404).', 'rest-api-shield-xml-rpc-blocker' ) . '</p>';
}

/**
 * Render XML-RPC Error Code Settings Field (Input)
 */
function wpashield_xml_error_code_callback() {
    $code = get_option( 'wpashield_xml_error_code' );

    // Input value is escaped with esc_attr
    echo '<input name="wpashield_xml_error_code" id="wpashield_xml_error_code" class="regular-text code" value="' . esc_attr( $code ) . '" />';
    // L500: Use esc_html__ because no HTML tags are included in the description
    echo '<p class="description">' . esc_html__( 'Specify the HTTP status code for the XML-RPC error response (e.g., 403, 404).', 'rest-api-shield-xml-rpc-blocker' ) . '</p>';
}

// Cleanup database options upon plugin uninstallation (Recommended)
register_uninstall_hook( __FILE__, 'wpashield_uninstall_cleanup' );
function wpashield_uninstall_cleanup() {
    $options_to_delete = array(
        'wpashield_block_xmlrpc',
        'wpashield_block_rest_anon',
        'wpashield_blocked_rest_routes',
        'wpashield_blocked_rest_plugin',
        'wpashield_rest_error_message',
        'wpashield_xml_error_message',
        'wpashield_rest_error_code',
        'wpashield_xml_error_code',
        'wpashield_allowed_rest_routes',
    );
    foreach ( $options_to_delete as $option ) {
        delete_option( $option );
    }
}
