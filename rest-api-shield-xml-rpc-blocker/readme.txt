=== REST API Shield & XML-RPC Blocker ===
Plugin Name: REST API Shield & XML-RPC Blocker
Description: A security plugin that controls XML-RPC access and specific WordPress REST API endpoints from anonymous users.
Plugin URI: https://p-fox.jp/blog/archive/367/
Stable tag: 1.0
Author: Red Fox(team Red Fox)
Author URI: https://p-fox.jp/
Contributors: teamredfox
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: wp-api-shield
Domain Path: /languages
Requires PHP: 7.4
Requires at least: 6.8
Tested up to: 6.8

A security plugin that controls XML-RPC access and specific WordPress REST API endpoints from anonymous users.

== Description ==

This plugin is designed to fundamentally strengthen the security of your WordPress site.

By default, WordPress exposes REST API endpoints like the user list (/wp/v2/users) even to unauthenticated users (anonymous users). This poses a risk of information leakage and can serve as a stepping stone for brute-force attacks by enabling username enumeration.

Using this plugin, you can finely adjust the following security settings from the "Settings" -> "General" page in the administration area.

# Key Security Features
##REST API Anonymous Access Restriction:

* Core endpoints (such as users, comments, media) and broad routes added by plugins can be specified as a blacklist.

* Routes necessary for blog display (such as wp/v2/posts) can be specified as a whitelist to exempt them from restrictions.

* Configure the HTTP status code (e.g., 403 Forbidden) and a custom error message to return upon access denial, preventing attackers from gaining insight into your site structure.

##Complete XML-RPC Blocking:

* Completely disable the XML-RPC functionality (xmlrpc.php) at the core WordPress level.

* When an attacker attempts access, the plugin responds with a specified HTTP status code and a custom error message, deceptively denying access.

This plugin is highly recommended for all WordPress sites that require enhanced security.

== Installation ==

1. Download the ZIP file and go to the WordPress admin menu "Plugins" > "Add New" > "Upload Plugin" to install it.

2. OR, unzip the downloaded file and upload the contents to the /wp-content/plugins/ directory.

3. Activate "REST API Shield & XML-RPC Blocker" in the WordPress admin menu "Plugins".

4. Navigate to the "API Security Settings" section at the bottom of the "Settings" > "General" page to adjust your configuration.

== Frequently Asked Questions ==

= Why is it necessary to restrict anonymous access to the REST API? =

Some REST API endpoints publish sensitive information that can be exploited by attackers, such as user display names and media details. Restricting anonymous access prevents the risk of this information leaking externally.

= Will blocking the REST API affect theme or plugin functionality? =

There is a possibility it could cause issues. Specifically, if a theme or plugin uses the REST API to load dynamic content for logged-out visitors (e.g., contact forms, dynamic widgets), that functionality might be blocked. In such cases, please add the relevant API route to the Whitelist (Allowed Routes).

= Should I disable XML-RPC? =

In most cases, we strongly recommend disabling it. XML-RPC was primarily used for remote publishing (e.g., older mobile apps), but the REST API is now the standard. Since xmlrpc.php is a prime target for brute-force attacks, you should disable it if you do not require remote publishing.

== Screenshots ==

1. The 'API Security Settings' section added to the 'Settings' -> 'General' page in the admin area.

2. REST API route Blacklist configuration screen.

3. XML-RPC complete blocking and custom response settings.

== Changelog ==

= 1.0.0 =

* Initial Release.

* Added XML-RPC enable/disable and custom response configuration features.

* Added REST API anonymous access restriction feature (Blacklist/Whitelist).

* Added configuration for custom error messages and HTTP status codes.

== Upgrade Notice ==

= 1.0.0 =
Initial release. No special upgrade notice is needed for this version.