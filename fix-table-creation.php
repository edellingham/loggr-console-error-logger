<?php
/**
 * Fix table creation for Loggr Plugin
 * 
 * This script can be run to fix table creation issues
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    // For standalone execution during debugging
    define('ABSPATH', '/var/www/rvabridge.cloudnineweb.dev/');
}

/**
 * Enhanced table creation with debugging
 */
function cel_create_tables_with_debugging() {
    global $wpdb;
    
    echo "<h2>Loggr Plugin - Table Creation Debug</h2>\n";
    
    $charset_collate = $wpdb->get_charset_collate();
    $table_prefix = $wpdb->prefix;
    
    echo "<p><strong>Database Info:</strong></p>\n";
    echo "<ul>\n";
    echo "<li>WordPress Table Prefix: {$table_prefix}</li>\n";
    echo "<li>Charset Collate: {$charset_collate}</li>\n";
    echo "<li>Database Name: " . DB_NAME . "</li>\n";
    echo "<li>MySQL Version: " . $wpdb->get_var("SELECT VERSION()") . "</li>\n";
    echo "</ul>\n";
    
    // Define table names
    $main_table = $table_prefix . 'console_errors';
    $mapping_table = $table_prefix . 'console_errors_ip_mapping';
    $ignore_table = $table_prefix . 'console_errors_ignore_patterns';
    
    echo "<p><strong>Table Names:</strong></p>\n";
    echo "<ul>\n";
    echo "<li>Main Table: {$main_table}</li>\n";
    echo "<li>Mapping Table: {$mapping_table}</li>\n";
    echo "<li>Ignore Table: {$ignore_table}</li>\n";
    echo "</ul>\n";
    
    // Check current table status
    echo "<p><strong>Current Table Status:</strong></p>\n";
    echo "<ul>\n";
    $main_exists = $wpdb->get_var("SHOW TABLES LIKE '{$main_table}'") === $main_table;
    $mapping_exists = $wpdb->get_var("SHOW TABLES LIKE '{$mapping_table}'") === $mapping_table;
    $ignore_exists = $wpdb->get_var("SHOW TABLES LIKE '{$ignore_table}'") === $ignore_table;
    
    echo "<li>Main Table: " . ($main_exists ? 'EXISTS' : 'MISSING') . "</li>\n";
    echo "<li>Mapping Table: " . ($mapping_exists ? 'EXISTS' : 'MISSING') . "</li>\n";
    echo "<li>Ignore Table: " . ($ignore_exists ? 'EXISTS' : 'MISSING') . "</li>\n";
    echo "</ul>\n";
    
    // Main table SQL
    $main_sql = "CREATE TABLE {$main_table} (
        id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
        error_type VARCHAR(50) NOT NULL,
        error_message TEXT NOT NULL,
        error_source VARCHAR(255),
        error_line INT,
        error_column INT,
        stack_trace TEXT,
        user_agent TEXT,
        page_url VARCHAR(255),
        user_ip VARCHAR(45),
        user_id BIGINT(20) UNSIGNED DEFAULT NULL,
        associated_user_id BIGINT(20) UNSIGNED DEFAULT NULL,
        session_id VARCHAR(255),
        is_login_page TINYINT(1) DEFAULT 0,
        additional_data TEXT,
        PRIMARY KEY (id),
        KEY timestamp (timestamp),
        KEY error_type (error_type),
        KEY user_ip (user_ip),
        KEY user_id (user_id),
        KEY associated_user_id (associated_user_id),
        KEY is_login_page (is_login_page)
    ) {$charset_collate};";
    
    // Mapping table SQL  
    $mapping_sql = "CREATE TABLE {$mapping_table} (
        id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
        ip_address VARCHAR(45) NOT NULL,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        first_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_seen DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        login_count INT DEFAULT 1,
        PRIMARY KEY (id),
        UNIQUE KEY ip_user (ip_address, user_id),
        KEY ip_address (ip_address),
        KEY user_id (user_id),
        KEY last_seen (last_seen)
    ) {$charset_collate};";
    
    // Ignore patterns table SQL
    $ignore_sql = "CREATE TABLE {$ignore_table} (
        id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
        pattern_type VARCHAR(50) NOT NULL,
        pattern_value TEXT NOT NULL,
        is_active TINYINT(1) DEFAULT 1,
        notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY pattern_type (pattern_type),
        KEY is_active (is_active)
    ) {$charset_collate};";
    
    // Load upgrade functions
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    
    echo "<p><strong>Creating Tables:</strong></p>\n";
    
    // Create main table
    echo "<h3>Creating Main Table:</h3>\n";
    echo "<pre>" . htmlspecialchars($main_sql) . "</pre>\n";
    $result1 = dbDelta($main_sql);
    echo "<p>dbDelta Result:</p><pre>" . print_r($result1, true) . "</pre>\n";
    
    if ($wpdb->last_error) {
        echo "<p style='color: red;'><strong>Error:</strong> " . $wpdb->last_error . "</p>\n";
    }
    
    // Create mapping table
    echo "<h3>Creating Mapping Table:</h3>\n";
    echo "<pre>" . htmlspecialchars($mapping_sql) . "</pre>\n";
    $result2 = dbDelta($mapping_sql);
    echo "<p>dbDelta Result:</p><pre>" . print_r($result2, true) . "</pre>\n";
    
    if ($wpdb->last_error) {
        echo "<p style='color: red;'><strong>Error:</strong> " . $wpdb->last_error . "</p>\n";
    }
    
    // Create ignore table
    echo "<h3>Creating Ignore Table:</h3>\n";
    echo "<pre>" . htmlspecialchars($ignore_sql) . "</pre>\n";
    $result3 = dbDelta($ignore_sql);
    echo "<p>dbDelta Result:</p><pre>" . print_r($result3, true) . "</pre>\n";
    
    if ($wpdb->last_error) {
        echo "<p style='color: red;'><strong>Error:</strong> " . $wpdb->last_error . "</p>\n";
    }
    
    // Final verification
    echo "<p><strong>Final Table Status:</strong></p>\n";
    echo "<ul>\n";
    $main_exists_final = $wpdb->get_var("SHOW TABLES LIKE '{$main_table}'") === $main_table;
    $mapping_exists_final = $wpdb->get_var("SHOW TABLES LIKE '{$mapping_table}'") === $mapping_table;
    $ignore_exists_final = $wpdb->get_var("SHOW TABLES LIKE '{$ignore_table}'") === $ignore_table;
    
    echo "<li>Main Table: " . ($main_exists_final ? '<span style="color: green;">EXISTS</span>' : '<span style="color: red;">MISSING</span>') . "</li>\n";
    echo "<li>Mapping Table: " . ($mapping_exists_final ? '<span style="color: green;">EXISTS</span>' : '<span style="color: red;">MISSING</span>') . "</li>\n";
    echo "<li>Ignore Table: " . ($ignore_exists_final ? '<span style="color: green;">EXISTS</span>' : '<span style="color: red;">MISSING</span>') . "</li>\n";
    echo "</ul>\n";
    
    // Try direct SQL if dbDelta failed
    if (!$main_exists_final) {
        echo "<p><strong>Attempting direct SQL creation for main table:</strong></p>\n";
        $direct_result = $wpdb->query($main_sql);
        if ($direct_result === false) {
            echo "<p style='color: red;'>Direct SQL failed: " . $wpdb->last_error . "</p>\n";
        } else {
            echo "<p style='color: green;'>Direct SQL succeeded!</p>\n";
        }
    }
    
    if (!$mapping_exists_final) {
        echo "<p><strong>Attempting direct SQL creation for mapping table:</strong></p>\n";
        $direct_result = $wpdb->query($mapping_sql);
        if ($direct_result === false) {
            echo "<p style='color: red;'>Direct SQL failed: " . $wpdb->last_error . "</p>\n";
        } else {
            echo "<p style='color: green;'>Direct SQL succeeded!</p>\n";
        }
    }
    
    if (!$ignore_exists_final) {
        echo "<p><strong>Attempting direct SQL creation for ignore table:</strong></p>\n";
        $direct_result = $wpdb->query($ignore_sql);
        if ($direct_result === false) {
            echo "<p style='color: red;'>Direct SQL failed: " . $wpdb->last_error . "</p>\n";
        } else {
            echo "<p style='color: green;'>Direct SQL succeeded!</p>\n";
        }
    }
    
    // Update database version
    update_option('cel_db_version', '1.3.0');
    echo "<p><strong>Updated database version to 1.3.0</strong></p>\n";
    
    return array(
        'main_table' => $main_exists_final,
        'mapping_table' => $mapping_exists_final,
        'ignore_table' => $ignore_exists_final
    );
}

// If running standalone
if (!function_exists('is_admin') || !is_admin()) {
    // This is a standalone execution - run the debug
    cel_create_tables_with_debugging();
}