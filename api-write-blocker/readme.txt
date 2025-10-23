=== API Write Blocker ===
Plugin Name: API Write Blocker
Description: A security plugin that blocks unauthorized write operations via REST API, XML-RPC, and Admin-Ajax endpoints.
Plugin URI: https://p-fox.jp/
Stable tag: 1.0
Author: Red Fox (team Red Fox)
Author URI: https://p-fox.jp/
Contributors: teamredfox
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: api-write-blocker
Domain Path: /languages
Requires PHP: 7.4
Requires at least: 6.8
Tested up to: 6.8

A plugin to control the operation of admin-ajax.php, REST API, and xmlrpc.

== Description ==

**API Write Blocker** is a security-focused plugin that prevents unauthorized or anonymous users from executing write operations through REST API, XML-RPC, and Admin-Ajax interfaces.

Unlike generic API blockers, this plugin enables *fine-grained control* over which HTTP methods (POST, PUT/PATCH, DELETE) are allowed, supports whitelist-based exceptions, and protects core endpoints without interfering with legitimate functionalities such as contact form submissions or plugin integrations.

### 🔐 Key Features

**REST API Method-Level Blocking**
* Independently block POST, PUT/PATCH, and DELETE requests.
* Whitelist specific REST routes (prefix match supported) to allow legitimate access (e.g., contact forms).
* Configure a custom HTTP status code and error message per request type.

**XML-RPC Write Operation Blocking**
* Disable only dangerous write-related XML-RPC methods (e.g., `wp.newPost`, `metaWeblog.editPost`) while keeping harmless calls untouched.
* Return a custom status code and error message for blocked XML-RPC operations.

**Admin-Ajax Write Protection**
* Blocks known sensitive write-related Ajax actions (e.g., `save-post`, `upload-attachment`) for unauthenticated users.
* Whitelist specific actions used by safe plugins like Contact Form 7.

**Flexible Exceptions**
* Authenticated users are always allowed by default.
* IP Whitelist support (including CIDR ranges) for external systems or trusted clients.

**Custom Response Messages**
* Return custom error messages and HTTP status codes for each interface: REST, XML-RPC, and Admin-Ajax.

This plugin is ideal for hardening your WordPress site without breaking functionality.

== Installation ==

1. Download the ZIP file and install it from "Plugins" > "Add New" > "Upload Plugin".
2. OR, unzip the plugin and upload it to the `/wp-content/plugins/` directory.
3. Activate "API Write Blocker" from "Plugins" in the admin panel.
4. Go to "Settings" > "API/Write Restriction" to configure the plugin.

== Frequently Asked Questions ==

= Will this plugin block Contact Form 7 or similar plugins? =

No, as long as you whitelist the required routes (e.g., `contact-form-7/v1/contact-forms`) and Ajax actions (e.g., `wpcf7-submit`). The plugin is designed to safely allow necessary requests.

= Is it safe to disable write methods in the REST API? =

Yes. Many sites do not use REST-based write operations publicly. By default, WordPress allows unauthenticated POST, PUT, and DELETE calls which may be exploited by attackers. This plugin disables them unless explicitly allowed.

= Can I block XML-RPC write methods without disabling XML-RPC entirely? =

Yes. This plugin blocks only post-related XML-RPC methods and lets other functions like pingbacks or basic metaWeblog info pass, if desired.

= What happens to authenticated users? =

Authenticated (logged-in) users are always allowed to execute requests. This plugin mainly protects against unauthorized, anonymous, or non-whitelisted users.

== Screenshots ==

1. Settings UI under "Settings" > "API/Write Restriction".
2. REST API write method controls and whitelist management.
3. IP whitelist and Ajax action whitelist settings.
4. Custom error message configuration screen.

== Changelog ==

= 1.0 =
* Initial release.
* REST API write method blocking (POST, PUT/PATCH, DELETE).
* XML-RPC method-level write blocking.
* Admin-Ajax write action blocking with whitelist.
* IP and route/action whitelists.
* Custom status code and message per interface.

== Upgrade Notice ==

= 1.0 =
Initial release. No upgrade concerns.
