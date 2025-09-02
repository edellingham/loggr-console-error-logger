=== Console Error Logger ===
Contributors: yourname
Tags: javascript, errors, debugging, console, ajax, login, monitoring
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Captures and logs browser console errors, JavaScript errors, and AJAX failures to help diagnose client-side issues, especially login problems.

== Description ==

Console Error Logger is a powerful WordPress plugin designed to capture and log client-side errors that occur in users' browsers. It's particularly useful for diagnosing login issues, such as when users report that the login "keeps spinning" without completing.

= Key Features =

* **Comprehensive Error Capture**: Logs JavaScript errors, console errors/warnings, AJAX failures, resource loading errors, and unhandled promise rejections
* **Login-Specific Monitoring**: Special detection for login timeouts and spinning login issues
* **Detailed Error Information**: Captures error messages, stack traces, source files, line numbers, and user context
* **Admin Interface**: View, filter, and manage error logs from the WordPress admin panel
* **Dashboard Widget**: Quick overview of recent errors directly on your WordPress dashboard
* **Performance Optimized**: Rate limiting and batch processing to minimize server impact
* **Security First**: Nonce verification, input sanitization, and sensitive data redaction
* **Flexible Configuration**: Control monitoring scope, retention periods, and cleanup settings

= Use Cases =

* Diagnose "spinning login" issues without direct browser access
* Monitor JavaScript errors across your site
* Track AJAX failures and API integration issues
* Identify broken resources (CSS, JS, images)
* Debug promise-based code issues
* Monitor site performance problems

= Privacy & Security =

* All data is stored locally in your WordPress database
* Sensitive information is automatically redacted from stack traces
* IP addresses can be anonymized if required
* Full control over data retention and cleanup

== Installation ==

1. Upload the `console-error-logger` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to Tools > Console Errors to view the admin interface
4. Configure settings under the Settings tab

= Automatic Installation =

1. Log in to your WordPress dashboard
2. Navigate to Plugins > Add New
3. Search for "Console Error Logger"
4. Click "Install Now" and then "Activate"

== Frequently Asked Questions ==

= Does this plugin slow down my site? =

No. The plugin uses efficient JavaScript that runs asynchronously and includes rate limiting to prevent performance impact. Error logging happens via AJAX in the background.

= Where is the error data stored? =

All error data is stored in a custom table in your WordPress database. No data is sent to external services.

= Can I use this on a production site? =

Yes, the plugin is designed to be production-ready with features like rate limiting, data cleanup, and minimal performance impact.

= How do I clear old error logs? =

You can manually clear all logs using the "Clear All Logs" button in the admin interface, or configure automatic cleanup in the Settings tab.

= Does it work with caching plugins? =

Yes, the plugin is compatible with caching plugins as it uses AJAX for error reporting, which bypasses page caching.

= Can I export error logs? =

Currently, logs can be viewed and copied from the admin interface. CSV export functionality is planned for a future release.

= Is multisite supported? =

Yes, the plugin works with WordPress multisite installations. Each site maintains its own error log.

== Screenshots ==

1. Error logs list view with filtering options
2. Detailed error information display
3. Statistics dashboard showing error trends
4. Settings page for configuration
5. Dashboard widget for quick overview
6. Login timeout detection in action

== Changelog ==

= 1.0.0 =
* Initial release
* Core error capture functionality
* Login timeout detection
* Admin interface with filtering and search
* Dashboard widget
* Settings for monitoring control
* Rate limiting and security features

== Upgrade Notice ==

= 1.0.0 =
Initial release of Console Error Logger.

== Technical Details ==

= Database Table Structure =

The plugin creates a custom table with the following structure:
* Indexed for optimal query performance
* Automatic cleanup based on configured retention
* Support for large-scale error logging

= Error Types Captured =

* `javascript_error` - Runtime JavaScript errors
* `console_error` - Console.error() output
* `console_warning` - Console.warn() output
* `ajax_error` - Failed AJAX requests
* `fetch_error` - Fetch API failures
* `resource_error` - Failed resource loads
* `unhandled_rejection` - Promise rejections
* `login_timeout` - Login timeout detection

= Hooks and Filters =

The plugin provides several hooks for developers:

* `cel_critical_error_logged` - Fired when a critical error is logged
* `cel_before_error_insert` - Filter error data before database insertion
* `cel_after_error_insert` - Action after successful error logging

= Requirements =

* WordPress 5.0 or higher
* PHP 7.4 or higher
* MySQL 5.6+ / MariaDB 10.1+
* JavaScript enabled in browser

== Support ==

For support, feature requests, or bug reports, please visit the plugin's support forum or GitHub repository.

== Privacy Policy ==

This plugin stores error information including:
* Error messages and stack traces
* Page URLs where errors occurred
* User IP addresses (can be anonymized)
* Browser user agent strings

All data is stored locally in your WordPress database and is not transmitted to any third-party services.