<?php
/**
 * Fired during plugin uninstall
 * 
 * This file is called when the plugin is deleted via the WordPress admin.
 * It handles complete cleanup of all plugin data, tables, options, and scheduled events.
 *
 * @package Console_Error_Logger
 * @since 1.2.0
 */

// If uninstall not called from WordPress, then exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Complete cleanup of Console Error Logger plugin
 * 
 * This function removes:
 * - Custom database tables
 * - Plugin options and settings
 * - Scheduled events
 * - Transients
 * - User meta data related to plugin
 */
function cel_complete_uninstall() {
    global $wpdb;
    
    // Security check - ensure we have proper permissions
    if (!current_user_can('activate_plugins')) {
        return false;
    }
    
    // 1. Remove custom database tables
    cel_remove_database_tables();
    
    // 2. Remove plugin options
    cel_remove_plugin_options();
    
    // 3. Clear scheduled events
    cel_clear_scheduled_events();
    
    // 4. Remove transients
    cel_remove_transients();
    
    // 5. Clean up user meta (if any)
    cel_remove_user_meta();
    
    // 6. Clear any cached data
    cel_clear_caches();
    
    return true;
}

/**
 * Remove all custom database tables created by the plugin
 */
function cel_remove_database_tables() {
    global $wpdb;
    
    // List of tables to remove
    $tables = array(
        $wpdb->prefix . 'console_errors',
        $wpdb->prefix . 'console_errors_ip_mapping',
        $wpdb->prefix . 'console_errors_ignore_patterns'
    );
    
    // Drop each table if it exists
    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
    }
}

/**
 * Remove all plugin options and settings
 */
function cel_remove_plugin_options() {
    // List of options to remove
    $options = array(
        'cel_version',
        'cel_db_version', 
        'cel_settings'
    );
    
    // Remove each option
    foreach ($options as $option) {
        delete_option($option);
    }
    
    // Remove any options that might have been added during development/testing
    // Pattern-based cleanup for any options that start with 'cel_'
    global $wpdb;
    $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'cel_%'"
    );
}

/**
 * Clear all scheduled events related to the plugin
 */
function cel_clear_scheduled_events() {
    // Clear the main cleanup event
    wp_clear_scheduled_hook('cel_cleanup_logs');
    
    // Clear any other potential scheduled events
    $scheduled_events = array(
        'cel_cleanup_logs',
        'cel_maintenance_task',
        'cel_daily_report'
    );
    
    foreach ($scheduled_events as $hook) {
        while (wp_next_scheduled($hook)) {
            wp_unschedule_event(wp_next_scheduled($hook), $hook);
        }
        wp_clear_scheduled_hook($hook);
    }
}

/**
 * Remove plugin-related transients
 */
function cel_remove_transients() {
    global $wpdb;
    
    // Remove transients that start with 'cel_'
    $wpdb->query(
        "DELETE FROM {$wpdb->options} 
         WHERE option_name LIKE '_transient_cel_%' 
         OR option_name LIKE '_transient_timeout_cel_%'"
    );
    
    // If using multisite, also clean up site transients
    if (is_multisite()) {
        $wpdb->query(
            "DELETE FROM {$wpdb->sitemeta} 
             WHERE meta_key LIKE '_site_transient_cel_%' 
             OR meta_key LIKE '_site_transient_timeout_cel_%'"
        );
    }
}

/**
 * Remove any user meta data related to the plugin
 */
function cel_remove_user_meta() {
    global $wpdb;
    
    // Remove user meta that starts with 'cel_'
    $wpdb->query(
        "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'cel_%'"
    );
}

/**
 * Clear any WordPress caches related to the plugin
 */
function cel_clear_caches() {
    // Clear object cache if available
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
    
    // Clear any plugin-specific cache groups
    if (function_exists('wp_cache_flush_group')) {
        wp_cache_flush_group('cel_errors');
        wp_cache_flush_group('cel_settings');
    }
    
    // Clear rewrite rules to clean up any custom rules
    flush_rewrite_rules();
}

/**
 * Additional cleanup for multisite installations
 */
function cel_multisite_uninstall() {
    if (!is_multisite()) {
        return;
    }
    
    global $wpdb;
    
    // Get all blog IDs
    $blog_ids = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");
    
    foreach ($blog_ids as $blog_id) {
        switch_to_blog($blog_id);
        
        // Run the standard cleanup for each site
        cel_complete_uninstall();
        
        restore_current_blog();
    }
    
    // Clean up any network-wide options
    delete_site_option('cel_network_settings');
    delete_site_option('cel_network_version');
}

/**
 * Log the uninstall event for debugging purposes
 */
function cel_log_uninstall() {
    // Only log if WP_DEBUG is enabled
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Console Error Logger: Plugin uninstalled successfully at ' . current_time('mysql'));
    }
}

// Execute the uninstall process
try {
    // Handle multisite installations
    if (is_multisite() && isset($_GET['networkwide']) && $_GET['networkwide'] == '1') {
        cel_multisite_uninstall();
    } else {
        cel_complete_uninstall();
    }
    
    // Log the successful uninstall
    cel_log_uninstall();
    
} catch (Exception $e) {
    // Log any errors during uninstall
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Console Error Logger: Uninstall error - ' . $e->getMessage());
    }
}