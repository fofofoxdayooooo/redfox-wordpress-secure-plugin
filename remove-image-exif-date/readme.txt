=== Remove Image Exif Data ===
Plugin Name: Remove Image Exif Data
Description: Force deletion of Exif and metadata (camera info, GPS, date, etc.) from uploaded images for enhanced privacy and security.
Plugin URI: https://p-fox.jp/
Stable tag: 1.0
Author: Red Fox(team Red Fox)
Author URI: https://p-fox.jp/
Contributors: teamredfox
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: remove-image-exif-date
Domain Path: /languages
Requires PHP: 7.4
Requires at least: 6.8
Tested up to: 6.8

Automatically removes Exif and metadata from images upon upload using the GD library. Provides admin settings for toggling the feature on or off.

== Description ==

This plugin ensures that uploaded images are completely free of **Exif** and other embedded metadata such as camera model, shooting date, or GPS location.

When enabled, the plugin automatically reloads uploaded image files via the PHP **GD library** and re-saves them, effectively discarding all metadata.  
It helps protect the privacy of website authors and contributors, and reduces the risk of inadvertently exposing sensitive location or device information.

If your website publishes photographs taken by smartphones or digital cameras, enabling this plugin is highly recommended to prevent personal information leaks.

=== Key Features ===

* **Automatic metadata removal:** All Exif and metadata are stripped from uploaded images (JPEG, PNG, GIF, WebP).  
* **Admin control panel:** Enable or disable the feature via *Settings → 画像メタデータ削除*.  
* **GD library check:** Detects if the PHP GD extension is available and displays a warning if not.  
* **Non-destructive processing:** Image quality is preserved (JPEG: 100%, PNG: compression level 9).  
* **Uninstall cleanup:** Removes all plugin options from the database upon uninstall.  

=== Supported Formats ===
JPEG, PNG, GIF, WebP  
*(Depending on GD library configuration.)*

== Installation ==

1. Download the plugin ZIP file.  
2. Go to the WordPress Admin Dashboard → **Plugins → Add New → Upload Plugin**, and select the ZIP file.  
3. Click **Install Now**, then **Activate Plugin**.  
4. Open **Settings → 画像メタデータ削除** to configure the plugin.  
5. Check "メタデータ削除を強制する" and save settings.

== Frequently Asked Questions ==

= Does this plugin reduce image quality? =
No. Images are re-saved using high quality parameters (JPEG: quality 100 / PNG: compression 9), so visible degradation is negligible.

= Does it work for all image formats? =
Yes, for major web formats (JPEG, PNG, GIF, WebP). However, the availability depends on the PHP GD library configuration of your server.

= What if GD is not available? =
If GD is disabled, the plugin displays a warning message in the settings page and skips metadata removal to prevent errors.

= Can I selectively remove metadata for certain uploads? =
Currently, the plugin applies the setting globally. When the toggle is ON, all uploaded images are processed automatically.

= Does it affect existing images in the Media Library? =
No. Only newly uploaded images are processed. Existing files remain unchanged.

== Screenshots ==

1. The “画像メタデータ削除” settings page under “Settings” menu.
2. Checkbox for enabling forced metadata removal.
3. Warning message displayed when PHP GD extension is disabled.

== Changelog ==

= 1.0 =
* Initial release.
* Added forced Exif/metadata removal using GD library.
* Added admin settings page for enabling/disabling the feature.
* Added uninstall cleanup for plugin options.

== Upgrade Notice ==

= 1.0 =
Initial release. No upgrade actions required.

== Notes ==

*This plugin is ideal for photographers, journalists, and content creators who wish to protect their privacy by automatically removing hidden metadata from uploaded images.*