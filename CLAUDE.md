# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Loggr (Console Error Logger) is a WordPress plugin for capturing and logging client-side JavaScript errors, with special focus on diagnosing login issues. The plugin follows WordPress development standards and best practices.

**Key Technologies:**
- PHP 7.4+ (WordPress plugin architecture)
- JavaScript/jQuery (frontend error capture)
- MySQL/MariaDB (WordPress database with custom table)
- WordPress 5.0+ compatibility

## Common Development Commands

### Testing
```bash
# Open test interface in browser
open test-errors.html

# Use browser console for testing specific error types
CEL_Test.testJavaScriptError()
CEL_Test.testConsoleError()
CEL_Test.testAjaxError()
CEL_Test.testFetchError()
CEL_Test.testResourceError()
CEL_Test.testPromiseRejection()
CEL_Test.testLoginTimeout()
```

### WordPress Development
```bash
# Install plugin (from WordPress root)
cp -r console-error-logger/ wp-content/plugins/

# Activate via WP-CLI
wp plugin activate console-error-logger

# Deactivate and clean database
wp plugin deactivate console-error-logger --uninstall
```

## Architecture Overview

### Core Classes Structure

The plugin uses a singleton pattern with clear separation of concerns:

1. **`console-error-logger.php`** - Main plugin entry point, registers WordPress hooks, manages AJAX endpoints
2. **`includes/class-error-logger.php`** - Core error processing, categorization, and enrichment
3. **`includes/class-database.php`** - Database operations, table management, cleanup mechanisms
4. **`includes/class-admin.php`** - Admin interface, settings management, dashboard widgets

### Frontend Error Capture Flow

1. **`assets/js/console-error-logger.js`** intercepts various error types:
   - Window error events → `handleJavaScriptError()`
   - Console methods → wrapped with `captureConsoleErrors()`
   - AJAX/Fetch failures → jQuery hooks and fetch wrapper
   - Login form timeouts → special monitoring

2. Errors are queued and batched for performance, then sent via AJAX to `cel_log_error` endpoint

3. Backend processes through `CEL_Error_Logger::log_error()` with sanitization and enrichment

4. Stored in custom `wp_console_errors` table with comprehensive metadata

### Database Schema

Custom table `wp_console_errors` includes:
- Error details (type, message, source, line, column, stack_trace)
- User context (ip_address, user_agent, page_url, user_id, session_id)
- Metadata (created_at, error_hash, additional_data)
- Indexed on: created_at, error_type, user_id, session_id

### Security Considerations

All error handling includes:
- WordPress nonce verification (`cel_nonce`)
- Input sanitization using `sanitize_text_field()`, `esc_html()`, etc.
- Rate limiting (max 50 errors per session)
- XSS prevention in stored/displayed data
- Sensitive data redaction in stack traces

## Development Patterns

### Adding New Error Types

1. Update JavaScript capture in `console-error-logger.js`:
   - Add capture method similar to existing patterns
   - Include in error object with appropriate type
   - Add to `CEL_Test` namespace for testing

2. Update backend processing in `class-error-logger.php`:
   - Add case in `categorize_error()` method
   - Update `is_critical_error()` if needed

3. Update admin display in `class-admin.php` if special handling needed

### Modifying Database Schema

1. Update version constant in main plugin file
2. Modify `create_table()` in `class-database.php`
3. Add upgrade logic to handle existing installations
4. Test upgrade path thoroughly

### Working with Admin Interface

Admin interface uses tabbed navigation:
- **Error Logs Tab**: Main error display with filtering
- **Statistics Tab**: Error analytics and patterns
- **Settings Tab**: Configuration options

JavaScript for admin is in `assets/js/admin-script.js`, styles in `assets/css/admin-styles.css`

## Testing Approach

1. Use `test-errors.html` for manual testing of error capture
2. Browser console has `CEL_Test` object with test functions
3. Check WordPress debug log for backend issues (`wp-content/debug.log`)
4. Monitor `wp_console_errors` table for captured errors
5. Test with different user roles and permissions

## Performance Considerations

- Client-side rate limiting prevents error spam
- Errors are batched before sending to server
- Database cleanup removes old errors (30+ days by default)
- Proper indexing ensures fast queries even with large datasets
- Session tracking groups related errors

## WordPress Hooks

Key hooks for extension:
- `cel_before_log_error` - Modify error data before logging
- `cel_after_log_error` - Perform actions after error logged
- `cel_critical_error_detected` - Respond to critical errors
- `cel_before_cleanup` - Before database cleanup runs