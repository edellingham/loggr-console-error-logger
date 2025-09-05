<?php
/**
 * Database handler class for Console Error Logger
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class CEL_Database {
    
    /**
     * Database table name
     */
    private $table_name;
    
    /**
     * WordPress database object
     */
    private $wpdb;
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'console_errors';
    }
    
    /**
     * Create database table with enhanced error handling and logging
     */
    public function create_table() {
        $this->log_system_info();
        
        // Get charset and collation with fallback
        $charset_collate = $this->get_safe_charset_collate();
        
        // Define table structures
        $tables = $this->get_table_definitions($charset_collate);
        
        // Load WordPress upgrade functions
        $this->ensure_upgrade_functions();
        
        // Track creation attempts
        $creation_log = array();
        $creation_success = true;
        
        // Create each table with comprehensive error handling
        foreach ($tables as $table_key => $table_info) {
            $table_result = $this->create_single_table(
                $table_info['name'],
                $table_info['sql'],
                $table_key
            );
            
            $creation_log[$table_key] = $table_result;
            if (!$table_result['success']) {
                $creation_success = false;
            }
        }
        
        // Log comprehensive creation results
        $this->log_creation_results($creation_log, $creation_success);
        
        // Store detailed failure information for admin review
        if (!$creation_success) {
            $this->store_creation_failure_details($creation_log);
        } else {
            // Clear any previous failure details
            delete_option('cel_table_creation_failures');
        }
        
        // Add performance indexes if main table exists
        if ($creation_log['main']['success']) {
            $this->add_performance_indexes();
        }
        
        // Update database version only if all tables created successfully
        if ($creation_success) {
            update_option('cel_db_version', '1.3.1');
            update_option('cel_tables_created', time());
        }
        
        return $creation_success;
    }
    
    /**
     * Log comprehensive system information for debugging
     */
    private function log_system_info() {
        if (!$this->should_log_debug()) {
            return;
        }
        
        global $wp_version;
        
        error_log('CEL: =================================');
        error_log('CEL: STARTING TABLE CREATION PROCESS');
        error_log('CEL: =================================');
        error_log('CEL: WordPress Version: ' . $wp_version);
        error_log('CEL: PHP Version: ' . PHP_VERSION);
        error_log('CEL: MySQL Version: ' . $this->wpdb->db_version());
        error_log('CEL: Table Prefix: ' . $this->wpdb->prefix);
        error_log('CEL: Database Name: ' . DB_NAME);
        error_log('CEL: Database Host: ' . DB_HOST);
        error_log('CEL: Database Charset: ' . DB_CHARSET);
        error_log('CEL: Database Collate: ' . DB_COLLATE);
        error_log('CEL: Current User Can: ' . (current_user_can('activate_plugins') ? 'activate_plugins' : 'limited'));
        error_log('CEL: WordPress Memory Limit: ' . WP_MEMORY_LIMIT);
        error_log('CEL: PHP Memory Limit: ' . ini_get('memory_limit'));
        error_log('CEL: Max Execution Time: ' . ini_get('max_execution_time'));
        
        // Test basic database connectivity
        $test_query = $this->wpdb->get_var("SELECT 1");
        error_log('CEL: Database Connectivity Test: ' . ($test_query === '1' ? 'PASSED' : 'FAILED'));
        
        // Check if we can see existing WordPress tables
        $wp_tables = $this->wpdb->get_var("SHOW TABLES LIKE '{$this->wpdb->prefix}options'");
        error_log('CEL: WordPress Tables Visible: ' . ($wp_tables ? 'YES' : 'NO'));
        
        error_log('CEL: =================================');
    }
    
    /**
     * Get safe charset and collation with fallbacks
     */
    private function get_safe_charset_collate() {
        $charset_collate = $this->wpdb->get_charset_collate();
        
        // If get_charset_collate() returns empty, build manually
        if (empty($charset_collate)) {
            $charset = !empty(DB_CHARSET) ? DB_CHARSET : 'utf8';
            $collate = !empty(DB_COLLATE) ? DB_COLLATE : 'utf8_general_ci';
            $charset_collate = "DEFAULT CHARACTER SET {$charset} COLLATE {$collate}";
        }
        
        if ($this->should_log_debug()) {
            error_log('CEL: Charset Collate: ' . $charset_collate);
        }
        
        return $charset_collate;
    }
    
    /**
     * Get table definitions
     */
    private function get_table_definitions($charset_collate) {
        $tables = array();
        
        // Main errors table
        $tables['main'] = array(
            'name' => $this->table_name,
            'sql' => "CREATE TABLE {$this->table_name} (
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
            ) {$charset_collate};"
        );
        
        // IP mapping table
        $mapping_table = $this->wpdb->prefix . 'console_errors_ip_mapping';
        $tables['mapping'] = array(
            'name' => $mapping_table,
            'sql' => "CREATE TABLE {$mapping_table} (
                id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
                ip_address VARCHAR(45) NOT NULL,
                user_id BIGINT(20) UNSIGNED NOT NULL,
                first_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
                login_count INT DEFAULT 1,
                PRIMARY KEY (id),
                UNIQUE KEY ip_user (ip_address, user_id),
                KEY ip_address (ip_address),
                KEY user_id (user_id),
                KEY last_seen (last_seen)
            ) {$charset_collate};"
        );
        
        // Ignore patterns table
        $ignore_table = $this->wpdb->prefix . 'console_errors_ignore_patterns';
        $tables['ignore'] = array(
            'name' => $ignore_table,
            'sql' => "CREATE TABLE {$ignore_table} (
                id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
                pattern_type VARCHAR(50) NOT NULL,
                pattern_value TEXT NOT NULL,
                is_active TINYINT(1) DEFAULT 1,
                notes TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY pattern_type (pattern_type),
                KEY is_active (is_active)
            ) {$charset_collate};"
        );
        
        return $tables;
    }
    
    /**
     * Ensure WordPress upgrade functions are available
     */
    private function ensure_upgrade_functions() {
        if (!function_exists('dbDelta')) {
            $upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';
            
            if ($this->should_log_debug()) {
                error_log('CEL: Checking upgrade.php at: ' . $upgrade_file);
                error_log('CEL: File exists: ' . (file_exists($upgrade_file) ? 'YES' : 'NO'));
                if (file_exists($upgrade_file)) {
                    error_log('CEL: File readable: ' . (is_readable($upgrade_file) ? 'YES' : 'NO'));
                }
            }
            
            require_once($upgrade_file);
            
            if ($this->should_log_debug()) {
                error_log('CEL: dbDelta function available: ' . (function_exists('dbDelta') ? 'YES' : 'NO'));
            }
        }
    }
    
    /**
     * Create a single table with comprehensive error handling
     */
    private function create_single_table($table_name, $table_sql, $table_key) {
        $result = array(
            'name' => $table_name,
            'success' => false,
            'method_used' => '',
            'attempts' => array(),
            'final_error' => ''
        );
        
        if ($this->should_log_debug()) {
            error_log("CEL: Creating {$table_key} table: {$table_name}");
        }
        
        // Method 1: Try dbDelta first
        $result['attempts']['dbdelta'] = $this->try_dbdelta_creation($table_sql, $table_name, $table_key);
        if ($result['attempts']['dbdelta']['success']) {
            $result['success'] = true;
            $result['method_used'] = 'dbDelta';
            return $result;
        }
        
        // Method 2: Try direct SQL query
        $result['attempts']['direct'] = $this->try_direct_sql_creation($table_sql, $table_name, $table_key);
        if ($result['attempts']['direct']['success']) {
            $result['success'] = true;
            $result['method_used'] = 'direct_sql';
            return $result;
        }
        
        // Method 3: Try simplified table structure
        $result['attempts']['simplified'] = $this->try_simplified_creation($table_name, $table_key);
        if ($result['attempts']['simplified']['success']) {
            $result['success'] = true;
            $result['method_used'] = 'simplified';
            return $result;
        }
        
        // All methods failed
        $result['final_error'] = $this->wpdb->last_error ?: 'Unknown database error';
        
        if ($this->should_log_debug()) {
            error_log("CEL: All creation methods failed for {$table_key} table");
            error_log("CEL: Final error: {$result['final_error']}");
        }
        
        return $result;
    }
    
    /**
     * Try creating table with dbDelta
     */
    private function try_dbdelta_creation($table_sql, $table_name, $table_key) {
        $attempt = array('success' => false, 'error' => '', 'method' => 'dbDelta');
        
        try {
            // Clear any previous errors
            $this->wpdb->last_error = '';
            
            $dbdelta_result = dbDelta($table_sql);
            
            if ($this->should_log_debug()) {
                error_log("CEL: dbDelta result for {$table_key}: " . print_r($dbdelta_result, true));
            }
            
            // Check if table was created
            $table_exists = $this->table_exists($table_name);
            
            if ($table_exists) {
                $attempt['success'] = true;
                if ($this->should_log_debug()) {
                    error_log("CEL: dbDelta successfully created {$table_key} table");
                }
            } else {
                $attempt['error'] = $this->wpdb->last_error ?: 'dbDelta completed but table not found';
                if ($this->should_log_debug()) {
                    error_log("CEL: dbDelta failed for {$table_key}: {$attempt['error']}");
                }
            }
        } catch (Exception $e) {
            $attempt['error'] = 'dbDelta exception: ' . $e->getMessage();
            if ($this->should_log_debug()) {
                error_log("CEL: dbDelta exception for {$table_key}: {$attempt['error']}");
            }
        }
        
        return $attempt;
    }
    
    /**
     * Try creating table with direct SQL
     */
    private function try_direct_sql_creation($table_sql, $table_name, $table_key) {
        $attempt = array('success' => false, 'error' => '', 'method' => 'direct_sql');
        
        try {
            // Clear any previous errors
            $this->wpdb->last_error = '';
            
            $query_result = $this->wpdb->query($table_sql);
            
            if ($this->should_log_debug()) {
                error_log("CEL: Direct SQL result for {$table_key}: " . ($query_result !== false ? 'SUCCESS' : 'FAILED'));
            }
            
            if ($query_result !== false) {
                $table_exists = $this->table_exists($table_name);
                
                if ($table_exists) {
                    $attempt['success'] = true;
                    if ($this->should_log_debug()) {
                        error_log("CEL: Direct SQL successfully created {$table_key} table");
                    }
                } else {
                    $attempt['error'] = 'Direct SQL succeeded but table not found';
                }
            } else {
                $attempt['error'] = $this->wpdb->last_error ?: 'Direct SQL query failed';
            }
        } catch (Exception $e) {
            $attempt['error'] = 'Direct SQL exception: ' . $e->getMessage();
            if ($this->should_log_debug()) {
                error_log("CEL: Direct SQL exception for {$table_key}: {$attempt['error']}");
            }
        }
        
        return $attempt;
    }
    
    /**
     * Try creating table with simplified structure
     */
    private function try_simplified_creation($table_name, $table_key) {
        $attempt = array('success' => false, 'error' => '', 'method' => 'simplified');
        
        // Only try simplified creation for main table
        if ($table_key !== 'main') {
            $attempt['error'] = 'Simplified creation only available for main table';
            return $attempt;
        }
        
        try {
            // Create minimal table structure without complex indexes
            $simplified_sql = "CREATE TABLE {$table_name} (
                id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                error_type VARCHAR(50) NOT NULL DEFAULT '',
                error_message TEXT NOT NULL,
                error_source VARCHAR(255) DEFAULT NULL,
                error_line INT DEFAULT NULL,
                error_column INT DEFAULT NULL,
                stack_trace TEXT DEFAULT NULL,
                user_agent TEXT DEFAULT NULL,
                page_url VARCHAR(255) DEFAULT NULL,
                user_ip VARCHAR(45) DEFAULT NULL,
                user_id BIGINT(20) UNSIGNED DEFAULT NULL,
                associated_user_id BIGINT(20) UNSIGNED DEFAULT NULL,
                session_id VARCHAR(255) DEFAULT NULL,
                is_login_page TINYINT(1) DEFAULT 0,
                additional_data TEXT DEFAULT NULL,
                PRIMARY KEY (id)
            ) DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;";
            
            $this->wpdb->last_error = '';
            $query_result = $this->wpdb->query($simplified_sql);
            
            if ($query_result !== false) {
                $table_exists = $this->table_exists($table_name);
                
                if ($table_exists) {
                    $attempt['success'] = true;
                    if ($this->should_log_debug()) {
                        error_log("CEL: Simplified creation successfully created {$table_key} table");
                        error_log("CEL: WARNING: Table created with basic structure only - some features may be limited");
                    }
                } else {
                    $attempt['error'] = 'Simplified creation succeeded but table not found';
                }
            } else {
                $attempt['error'] = $this->wpdb->last_error ?: 'Simplified creation failed';
            }
        } catch (Exception $e) {
            $attempt['error'] = 'Simplified creation exception: ' . $e->getMessage();
            if ($this->should_log_debug()) {
                error_log("CEL: Simplified creation exception for {$table_key}: {$attempt['error']}");
            }
        }
        
        return $attempt;
    }
    
    /**
     * Check if table exists
     */
    private function table_exists($table_name) {
        // Use SHOW TABLES for reliable existence check
        $exists = $this->wpdb->get_var($this->wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
        return ($exists === $table_name);
    }
    
    /**
     * Log comprehensive creation results
     */
    private function log_creation_results($creation_log, $overall_success) {
        if (!$this->should_log_debug()) {
            return;
        }
        
        error_log('CEL: =================================');
        error_log('CEL: TABLE CREATION RESULTS');
        error_log('CEL: =================================');
        error_log('CEL: Overall Success: ' . ($overall_success ? 'YES' : 'NO'));
        
        foreach ($creation_log as $table_key => $result) {
            error_log("CEL: {$table_key} table ({$result['name']}): " . ($result['success'] ? 'SUCCESS' : 'FAILED'));
            
            if ($result['success']) {
                error_log("CEL:   - Method used: {$result['method_used']}");
            } else {
                error_log("CEL:   - Final error: {$result['final_error']}");
                error_log("CEL:   - Attempts made:");
                
                foreach ($result['attempts'] as $method => $attempt) {
                    $status = $attempt['success'] ? 'SUCCESS' : 'FAILED';
                    error_log("CEL:     * {$method}: {$status}");
                    if (!$attempt['success'] && !empty($attempt['error'])) {
                        error_log("CEL:       Error: {$attempt['error']}");
                    }
                }
            }
        }
        
        error_log('CEL: =================================');
    }
    
    /**
     * Store detailed failure information for admin review
     */
    private function store_creation_failure_details($creation_log) {
        $failure_details = array(
            'timestamp' => current_time('mysql'),
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'mysql_version' => $this->wpdb->db_version(),
            'table_prefix' => $this->wpdb->prefix,
            'failures' => array()
        );
        
        foreach ($creation_log as $table_key => $result) {
            if (!$result['success']) {
                $failure_details['failures'][$table_key] = array(
                    'table_name' => $result['name'],
                    'final_error' => $result['final_error'],
                    'attempts' => $result['attempts']
                );
            }
        }
        
        update_option('cel_table_creation_failures', $failure_details);
    }
    
    /**
     * Check if debug logging should be enabled
     */
    private function should_log_debug() {
        return (defined('WP_DEBUG') && WP_DEBUG) || (defined('CEL_DEBUG') && CEL_DEBUG);
    }
    
    /**
     * Get detailed table creation status for diagnostics
     */
    public function get_table_status() {
        $status = array();
        
        // Main table
        $status['main'] = array(
            'name' => $this->table_name,
            'exists' => $this->table_exists($this->table_name),
            'row_count' => 0,
            'structure_valid' => false
        );
        
        if ($status['main']['exists']) {
            $status['main']['row_count'] = $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
            $status['main']['structure_valid'] = $this->validate_table_structure($this->table_name, 'main');
        }
        
        // Mapping table
        $mapping_table = $this->wpdb->prefix . 'console_errors_ip_mapping';
        $status['mapping'] = array(
            'name' => $mapping_table,
            'exists' => $this->table_exists($mapping_table),
            'row_count' => 0,
            'structure_valid' => false
        );
        
        if ($status['mapping']['exists']) {
            $status['mapping']['row_count'] = $this->wpdb->get_var("SELECT COUNT(*) FROM {$mapping_table}");
            $status['mapping']['structure_valid'] = $this->validate_table_structure($mapping_table, 'mapping');
        }
        
        // Ignore patterns table
        $ignore_table = $this->wpdb->prefix . 'console_errors_ignore_patterns';
        $status['ignore'] = array(
            'name' => $ignore_table,
            'exists' => $this->table_exists($ignore_table),
            'row_count' => 0,
            'structure_valid' => false
        );
        
        if ($status['ignore']['exists']) {
            $status['ignore']['row_count'] = $this->wpdb->get_var("SELECT COUNT(*) FROM {$ignore_table}");
            $status['ignore']['structure_valid'] = $this->validate_table_structure($ignore_table, 'ignore');
        }
        
        return $status;
    }
    
    /**
     * Validate table structure
     */
    private function validate_table_structure($table_name, $table_type) {
        $columns = $this->wpdb->get_results("SHOW COLUMNS FROM {$table_name}");
        
        if (empty($columns)) {
            return false;
        }
        
        $required_columns = array();
        
        switch ($table_type) {
            case 'main':
                $required_columns = array('id', 'timestamp', 'error_type', 'error_message');
                break;
            case 'mapping':
                $required_columns = array('id', 'ip_address', 'user_id');
                break;
            case 'ignore':
                $required_columns = array('id', 'pattern_type', 'pattern_value');
                break;
        }
        
        $existing_columns = array_column($columns, 'Field');
        
        foreach ($required_columns as $required) {
            if (!in_array($required, $existing_columns)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Add performance indexes to existing table
     */
    private function add_performance_indexes() {
        // Performance indexes for optimized queries
        $indexes = array(
            'idx_rate_limiting' => "ALTER TABLE {$this->table_name} ADD INDEX idx_rate_limiting (user_ip, timestamp)",
            'idx_type_timestamp' => "ALTER TABLE {$this->table_name} ADD INDEX idx_type_timestamp (error_type, timestamp)",
            'idx_login_timestamp' => "ALTER TABLE {$this->table_name} ADD INDEX idx_login_timestamp (is_login_page, timestamp)",
            'idx_user_timestamp' => "ALTER TABLE {$this->table_name} ADD INDEX idx_user_timestamp (user_id, timestamp)",
            'idx_stats_composite' => "ALTER TABLE {$this->table_name} ADD INDEX idx_stats_composite (error_type, is_login_page, timestamp)",
            'idx_analytics' => "ALTER TABLE {$this->table_name} ADD INDEX idx_analytics (timestamp, error_type, user_ip)"
        );
        
        foreach ($indexes as $index_name => $sql) {
            // Check if index already exists
            $index_exists = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS 
                 WHERE table_schema = DATABASE() 
                 AND table_name = %s 
                 AND index_name = %s",
                $this->table_name,
                $index_name
            ));
            
            if (!$index_exists) {
                $result = $this->wpdb->query($sql);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    if ($result === false) {
                        error_log("CEL: Failed to create index {$index_name}: " . $this->wpdb->last_error);
                    } else {
                        error_log("CEL: Successfully created index {$index_name}");
                    }
                }
            }
        }
    }
    
    /**
     * Insert error log entry
     */
    public function insert_error($data) {
        // Validate required fields
        if (empty($data['error_type']) || empty($data['error_message'])) {
            return false;
        }
        
        // Prepare data for insertion
        $insert_data = array(
            'timestamp' => current_time('mysql'),
            'error_type' => sanitize_text_field($data['error_type']),
            'error_message' => sanitize_textarea_field($data['error_message']),
            'error_source' => isset($data['error_source']) ? esc_url_raw($data['error_source']) : null,
            'error_line' => isset($data['error_line']) ? absint($data['error_line']) : null,
            'error_column' => isset($data['error_column']) ? absint($data['error_column']) : null,
            'stack_trace' => isset($data['stack_trace']) ? sanitize_textarea_field($data['stack_trace']) : null,
            'user_agent' => isset($data['user_agent']) ? sanitize_text_field($data['user_agent']) : null,
            'page_url' => isset($data['page_url']) ? esc_url_raw($data['page_url']) : null,
            'user_ip' => $this->get_client_ip(),
            'user_id' => get_current_user_id() ?: null,
            'session_id' => isset($data['session_id']) ? sanitize_text_field($data['session_id']) : null,
            'is_login_page' => isset($data['is_login_page']) ? (bool)$data['is_login_page'] : false,
            'additional_data' => isset($data['additional_data']) ? wp_json_encode($data['additional_data']) : null
        );
        
        // Check for rate limiting (prevent spam)
        if ($this->is_rate_limited($insert_data['user_ip'])) {
            return false;
        }
        
        // Insert the error
        $result = $this->wpdb->insert(
            $this->table_name,
            $insert_data,
            array(
                '%s', // timestamp
                '%s', // error_type
                '%s', // error_message
                '%s', // error_source
                '%d', // error_line
                '%d', // error_column
                '%s', // stack_trace
                '%s', // user_agent
                '%s', // page_url
                '%s', // user_ip
                '%d', // user_id
                '%s', // session_id
                '%d', // is_login_page
                '%s'  // additional_data
            )
        );
        
        // Cleanup old logs if table is getting too large
        $this->check_and_cleanup();
        
        return $result !== false;
    }
    
    /**
     * Get error logs with pagination
     */
    public function get_errors($args = array()) {
        $defaults = array(
            'limit' => 50,
            'offset' => 0,
            'orderby' => 'timestamp',
            'order' => 'DESC',
            'error_type' => '',
            'date_from' => '',
            'date_to' => '',
            'search' => '',
            'is_login_page' => null
        );
        
        $args = wp_parse_args($args, $defaults);
        
        // Enforce memory safety limits
        $max_limit = 1000; // Maximum 1000 records per query
        $args['limit'] = min((int)$args['limit'], $max_limit);
        
        // Build WHERE clause
        $where = array('1=1');
        $prepare_values = array();
        
        if (!empty($args['error_type'])) {
            $where[] = 'error_type = %s';
            $prepare_values[] = $args['error_type'];
        }
        
        if (!empty($args['date_from'])) {
            $where[] = 'timestamp >= %s';
            $prepare_values[] = $args['date_from'];
        }
        
        if (!empty($args['date_to'])) {
            $where[] = 'timestamp <= %s';
            $prepare_values[] = $args['date_to'];
        }
        
        if (!empty($args['search'])) {
            $where[] = '(error_message LIKE %s OR error_source LIKE %s)';
            $search_term = '%' . $this->wpdb->esc_like($args['search']) . '%';
            $prepare_values[] = $search_term;
            $prepare_values[] = $search_term;
        }
        
        if ($args['is_login_page'] !== null) {
            $where[] = 'is_login_page = %d';
            $prepare_values[] = (int)$args['is_login_page'];
        }
        
        $where_clause = implode(' AND ', $where);
        
        // Validate orderby column
        $allowed_orderby = array('id', 'timestamp', 'error_type', 'error_source', 'user_ip');
        $orderby = in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'timestamp';
        
        // Validate order direction
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        
        // Build query
        $query = "SELECT * FROM `{$this->table_name}` WHERE {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $prepare_values[] = $args['limit'];
        $prepare_values[] = $args['offset'];
        
        // Execute query
        if (!empty($prepare_values)) {
            $results = $this->wpdb->get_results(
                $this->wpdb->prepare($query, $prepare_values)
            );
        } else {
            $results = $this->wpdb->get_results($query);
        }
        
        return $results;
    }
    
    /**
     * Get total count of errors
     */
    public function get_error_count($args = array()) {
        $where = array('1=1');
        $prepare_values = array();
        
        if (!empty($args['error_type'])) {
            $where[] = 'error_type = %s';
            $prepare_values[] = $args['error_type'];
        }
        
        if (!empty($args['is_login_page'])) {
            $where[] = 'is_login_page = %d';
            $prepare_values[] = (int)$args['is_login_page'];
        }
        
        $where_clause = implode(' AND ', $where);
        $query = "SELECT COUNT(*) FROM `{$this->table_name}` WHERE {$where_clause}";
        
        if (!empty($prepare_values)) {
            return $this->wpdb->get_var(
                $this->wpdb->prepare($query, $prepare_values)
            );
        } else {
            return $this->wpdb->get_var($query);
        }
    }
    
    /**
     * Get error statistics
     */
    public function get_error_stats() {
        // Check cache first
        $cache_key = 'cel_error_stats';
        $cached_stats = get_transient($cache_key);
        
        if ($cached_stats !== false) {
            return $cached_stats;
        }
        
        $stats = array();
        
        // Use optimized queries with proper indexes
        // Total errors (with limit to prevent memory issues)
        $stats['total'] = $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        
        // Errors by type (limit to top 20 types to prevent memory issues)
        $stats['by_type'] = $this->wpdb->get_results(
            "SELECT error_type, COUNT(*) as count 
             FROM {$this->table_name} 
             GROUP BY error_type 
             ORDER BY count DESC
             LIMIT 20"
        );
        
        // Recent errors (last 24 hours) - uses idx_analytics index
        $stats['recent_24h'] = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} 
                 WHERE timestamp > %s",
                date('Y-m-d H:i:s', strtotime('-24 hours'))
            )
        );
        
        // Login page errors - uses idx_login_timestamp index
        $stats['login_errors'] = $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE is_login_page = 1"
        );
        
        // Cache for 5 minutes
        set_transient($cache_key, $stats, 5 * MINUTE_IN_SECONDS);
        
        return $stats;
    }
    
    /**
     * Get last error timestamp
     */
    public function get_last_error_time() {
        return $this->wpdb->get_var(
            "SELECT timestamp FROM `{$this->table_name}` ORDER BY timestamp DESC LIMIT 1"
        );
    }
    
    /**
     * Get table name
     */
    public function get_table_name() {
        return $this->table_name;
    }
    
    /**
     * Track user login
     */
    public function track_login($user) {
        if (!$user instanceof WP_User) {
            return false;
        }
        
        // Log the login event as a special error type
        $this->insert_error(array(
            'error_type' => 'login_success',
            'error_message' => sprintf('User login: %s (ID: %d)', $user->user_login, $user->ID),
            'page_url' => wp_login_url(),
            'user_id' => $user->ID,
            'is_login_page' => 1,
            'additional_data' => array(
                'event' => 'wp_login',
                'username' => $user->user_login,
                'user_email' => $user->user_email,
                'user_role' => implode(', ', $user->roles),
                'timestamp' => current_time('mysql')
            )
        ));
        
        return true;
    }
    
    /**
     * Track failed login attempts
     */
    public function track_failed_login($username, $ip_address, $user_id = null, $user_exists = false) {
        // Prepare the error message
        if ($user_exists) {
            $error_message = sprintf('Failed login attempt for existing user: %s', $username);
            $error_type = 'login_failed_valid_user';
        } elseif (!empty($username)) {
            $error_message = sprintf('Failed login attempt for non-existent user: %s', $username);
            $error_type = 'login_failed_invalid_user';
        } else {
            $error_message = 'Failed login attempt with empty username';
            $error_type = 'login_failed_empty';
        }
        
        // Log the failed login event
        $this->insert_error(array(
            'error_type' => $error_type,
            'error_message' => $error_message,
            'page_url' => wp_login_url(),
            'user_id' => $user_id,
            'user_ip' => $ip_address,
            'is_login_page' => 1,
            'additional_data' => array(
                'event' => 'wp_login_failed',
                'attempted_username' => $username,
                'user_exists' => $user_exists,
                'timestamp' => current_time('mysql'),
                'authentication_failure' => true
            )
        ));
        
        // Track IP if we have a valid user
        if ($user_id && $ip_address) {
            $this->track_user_ip($user_id, $ip_address);
        }
        
        return true;
    }
    
    /**
     * Track user-IP association
     */
    public function track_user_ip($user_id, $ip_address) {
        if (empty($user_id) || empty($ip_address)) {
            return false;
        }
        
        $mapping_table = $this->wpdb->prefix . 'console_errors_ip_mapping';
        
        // Check if mapping already exists
        $existing = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT id, login_count FROM {$mapping_table} WHERE ip_address = %s AND user_id = %d",
            $ip_address, $user_id
        ));
        
        if ($existing) {
            // Update existing mapping
            return $this->wpdb->update(
                $mapping_table,
                array(
                    'last_seen' => current_time('mysql'),
                    'login_count' => $existing->login_count + 1
                ),
                array('id' => $existing->id),
                array('%s', '%d'),
                array('%d')
            );
        } else {
            // Insert new mapping
            return $this->wpdb->insert(
                $mapping_table,
                array(
                    'ip_address' => $ip_address,
                    'user_id' => $user_id,
                    'first_seen' => current_time('mysql'),
                    'last_seen' => current_time('mysql')
                ),
                array('%s', '%d', '%s', '%s')
            );
        }
    }
    
    /**
     * Get associated user ID for an IP address
     */
    public function get_associated_user_by_ip($ip_address) {
        if (empty($ip_address)) {
            return null;
        }
        
        $mapping_table = $this->wpdb->prefix . 'console_errors_ip_mapping';
        
        // Get most recent user for this IP
        return $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT user_id FROM {$mapping_table} 
             WHERE ip_address = %s 
             ORDER BY last_seen DESC 
             LIMIT 1",
            $ip_address
        ));
    }
    
    /**
     * Get all users associated with an IP
     */
    public function get_users_by_ip($ip_address) {
        if (empty($ip_address)) {
            return array();
        }
        
        $mapping_table = $this->wpdb->prefix . 'console_errors_ip_mapping';
        
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT m.*, u.display_name, u.user_email, u.user_login
             FROM {$mapping_table} m
             LEFT JOIN {$this->wpdb->users} u ON m.user_id = u.ID
             WHERE m.ip_address = %s 
             ORDER BY m.last_seen DESC",
            $ip_address
        ));
    }
    
    /**
     * Get IP addresses for a user
     */
    public function get_ips_by_user($user_id) {
        if (empty($user_id)) {
            return array();
        }
        
        $mapping_table = $this->wpdb->prefix . 'console_errors_ip_mapping';
        
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$mapping_table} 
             WHERE user_id = %d 
             ORDER BY last_seen DESC",
            $user_id
        ));
    }
    
    /**
     * Update error with associated user
     */
    public function update_error_associated_user($error_id, $user_id) {
        return $this->wpdb->update(
            $this->table_name,
            array('associated_user_id' => $user_id),
            array('id' => $error_id),
            array('%d'),
            array('%d')
        );
    }
    
    /**
     * Get errors with user information
     */
    public function get_errors_with_users($args = array()) {
        $defaults = array(
            'limit' => 50,
            'offset' => 0,
            'orderby' => 'timestamp',
            'order' => 'DESC',
            'error_type' => '',
            'date_from' => '',
            'date_to' => '',
            'search' => '',
            'is_login_page' => null,
            'user_id' => null,
            'associated_user_id' => null
        );
        
        $args = wp_parse_args($args, $defaults);
        
        // Enforce memory safety limits
        $max_limit = 1000; // Maximum 1000 records per query
        $args['limit'] = min((int)$args['limit'], $max_limit);
        
        // Build WHERE clause
        $where = array('1=1');
        $prepare_values = array();
        
        if (!empty($args['error_type'])) {
            $where[] = 'e.error_type = %s';
            $prepare_values[] = $args['error_type'];
        }
        
        if (!empty($args['date_from'])) {
            $where[] = 'e.timestamp >= %s';
            $prepare_values[] = $args['date_from'];
        }
        
        if (!empty($args['date_to'])) {
            $where[] = 'e.timestamp <= %s';
            $prepare_values[] = $args['date_to'];
        }
        
        if (!empty($args['search'])) {
            $where[] = '(e.error_message LIKE %s OR e.error_source LIKE %s)';
            $search_term = '%' . $this->wpdb->esc_like($args['search']) . '%';
            $prepare_values[] = $search_term;
            $prepare_values[] = $search_term;
        }
        
        if ($args['is_login_page'] !== null) {
            $where[] = 'e.is_login_page = %d';
            $prepare_values[] = (int)$args['is_login_page'];
        }
        
        if (!empty($args['user_id'])) {
            $where[] = 'e.user_id = %d';
            $prepare_values[] = $args['user_id'];
        }
        
        if (!empty($args['associated_user_id'])) {
            $where[] = 'e.associated_user_id = %d';
            $prepare_values[] = $args['associated_user_id'];
        }
        
        $where_clause = implode(' AND ', $where);
        
        // Validate orderby column
        $allowed_orderby = array('id', 'timestamp', 'error_type', 'error_source', 'user_ip');
        $orderby = in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'timestamp';
        
        // Validate order direction
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        
        // Build query with user joins
        $query = "SELECT e.*, 
                         u1.display_name as logged_user_name, u1.user_login as logged_user_login,
                         u2.display_name as associated_user_name, u2.user_login as associated_user_login
                  FROM `{$this->table_name}` e
                  LEFT JOIN `{$this->wpdb->users}` u1 ON e.user_id = u1.ID
                  LEFT JOIN `{$this->wpdb->users}` u2 ON e.associated_user_id = u2.ID
                  WHERE {$where_clause} 
                  ORDER BY e.{$orderby} {$order} 
                  LIMIT %d OFFSET %d";
        
        $prepare_values[] = $args['limit'];
        $prepare_values[] = $args['offset'];
        
        // Execute query
        if (!empty($prepare_values)) {
            return $this->wpdb->get_results(
                $this->wpdb->prepare($query, $prepare_values)
            );
        } else {
            return $this->wpdb->get_results($query);
        }
    }
    
    /**
     * Clear all error logs
     */
    public function clear_all_logs() {
        return $this->wpdb->query("TRUNCATE TABLE `{$this->table_name}`");
    }
    
    /**
     * Delete specific error log
     */
    public function delete_error($id) {
        return $this->wpdb->delete(
            $this->table_name,
            array('id' => $id),
            array('%d')
        );
    }
    
    /**
     * Cleanup old logs based on settings
     */
    public function cleanup_old_logs() {
        $settings = get_option('cel_settings', array());
        $days = isset($settings['auto_cleanup_days']) ? (int)$settings['auto_cleanup_days'] : 30;
        
        if ($days > 0) {
            $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
            
            return $this->wpdb->query(
                $this->wpdb->prepare(
                    "DELETE FROM `{$this->table_name}` WHERE timestamp < %s",
                    $cutoff_date
                )
            );
        }
        
        return false;
    }
    
    /**
     * Check if table needs cleanup based on max entries
     */
    private function check_and_cleanup() {
        $settings = get_option('cel_settings', array());
        $max_entries = isset($settings['max_log_entries']) ? (int)$settings['max_log_entries'] : 1000;
        
        $count = $this->wpdb->get_var("SELECT COUNT(*) FROM `{$this->table_name}`");
        
        if ($count > $max_entries) {
            // Delete oldest entries to maintain max limit
            $to_delete = $count - $max_entries;
            
            $this->wpdb->query(
                $this->wpdb->prepare(
                    "DELETE FROM `{$this->table_name}` 
                     ORDER BY timestamp ASC 
                     LIMIT %d",
                    $to_delete
                )
            );
        }
    }
    
    /**
     * Check if IP is rate limited
     */
    private function is_rate_limited($ip) {
        // Check if IP has logged too many errors recently (more than 10 in last minute)
        // Use optimized query with LIMIT to stop counting after threshold is reached
        $threshold = 10;
        $result = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM (
                    SELECT 1 FROM `{$this->table_name}` 
                    WHERE user_ip = %s 
                    AND timestamp > %s 
                    ORDER BY timestamp DESC 
                    LIMIT %d
                ) AS recent_errors",
                $ip,
                date('Y-m-d H:i:s', strtotime('-1 minute')),
                $threshold + 1
            )
        );
        
        return $result > $threshold;
    }
    
    /**
     * Get ignore patterns
     */
    public function get_ignore_patterns($active_only = false) {
        $ignore_table = $this->wpdb->prefix . 'console_errors_ignore_patterns';
        
        if ($active_only) {
            return $this->wpdb->get_results(
                "SELECT * FROM {$ignore_table} WHERE is_active = 1 ORDER BY pattern_type, id DESC"
            );
        }
        
        return $this->wpdb->get_results(
            "SELECT * FROM {$ignore_table} ORDER BY pattern_type, id DESC"
        );
    }
    
    /**
     * Add ignore pattern
     */
    public function add_ignore_pattern($pattern_type, $pattern_value, $notes = '') {
        $ignore_table = $this->wpdb->prefix . 'console_errors_ignore_patterns';
        
        return $this->wpdb->insert(
            $ignore_table,
            array(
                'pattern_type' => sanitize_text_field($pattern_type),
                'pattern_value' => sanitize_textarea_field($pattern_value),
                'notes' => sanitize_textarea_field($notes),
                'is_active' => 1,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%d', '%s', '%s')
        );
    }
    
    /**
     * Toggle ignore pattern status
     */
    public function toggle_ignore_pattern($pattern_id) {
        $ignore_table = $this->wpdb->prefix . 'console_errors_ignore_patterns';
        
        // Get current status
        $current = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT is_active FROM {$ignore_table} WHERE id = %d",
            $pattern_id
        ));
        
        if ($current === null) {
            return false;
        }
        
        // Toggle the status
        return $this->wpdb->update(
            $ignore_table,
            array(
                'is_active' => !$current,
                'updated_at' => current_time('mysql')
            ),
            array('id' => $pattern_id),
            array('%d', '%s'),
            array('%d')
        );
    }
    
    /**
     * Delete ignore pattern
     */
    public function delete_ignore_pattern($pattern_id) {
        $ignore_table = $this->wpdb->prefix . 'console_errors_ignore_patterns';
        
        return $this->wpdb->delete(
            $ignore_table,
            array('id' => $pattern_id),
            array('%d')
        );
    }
    
    /**
     * Check if error should be ignored
     */
    public function should_ignore_error($error_data) {
        $patterns = $this->get_ignore_patterns(true); // Get only active patterns
        
        foreach ($patterns as $pattern) {
            $pattern_value = $pattern->pattern_value;
            
            switch ($pattern->pattern_type) {
                case 'message':
                    if (isset($error_data['error_message']) && 
                        strpos($error_data['error_message'], $pattern_value) !== false) {
                        return true;
                    }
                    break;
                    
                case 'source':
                    if (isset($error_data['error_source']) && 
                        strpos($error_data['error_source'], $pattern_value) !== false) {
                        return true;
                    }
                    break;
                    
                case 'type':
                    if (isset($error_data['error_type']) && 
                        $error_data['error_type'] === $pattern_value) {
                        return true;
                    }
                    break;
                    
                case 'regex':
                    if (isset($error_data['error_message'])) {
                        // Validate regex pattern before using it
                        if ($this->is_safe_regex($pattern_value)) {
                            if (@preg_match($pattern_value, $error_data['error_message'])) {
                                return true;
                            }
                        }
                    }
                    break;
            }
        }
        
        return false;
    }
    
    /**
     * Get login history for analytics
     */
    public function get_login_history($args = array()) {
        $defaults = array(
            'limit' => 50,
            'offset' => 0,
            'date_from' => '',
            'date_to' => '',
            'success_only' => false,
            'failed_only' => false,
            'user_id' => null,
            'ip_address' => ''
        );
        
        $args = wp_parse_args($args, $defaults);
        
        // Enforce memory safety limits
        $max_limit = 500; // Maximum 500 records per query for login history
        $args['limit'] = min((int)$args['limit'], $max_limit);
        
        // Build WHERE clause for login events
        $where = array("error_type IN ('login_success', 'login_failed_valid_user', 'login_failed_invalid_user', 'login_failed_empty')");
        $prepare_values = array();
        
        if (!empty($args['date_from'])) {
            $where[] = 'timestamp >= %s';
            $prepare_values[] = $args['date_from'];
        }
        
        if (!empty($args['date_to'])) {
            $where[] = 'timestamp <= %s';
            $prepare_values[] = $args['date_to'];
        }
        
        if ($args['success_only']) {
            $where[] = "error_type = 'login_success'";
        } elseif ($args['failed_only']) {
            $where[] = "error_type IN ('login_failed_valid_user', 'login_failed_invalid_user', 'login_failed_empty')";
        }
        
        if (!empty($args['user_id'])) {
            $where[] = 'user_id = %d';
            $prepare_values[] = $args['user_id'];
        }
        
        if (!empty($args['ip_address'])) {
            $where[] = 'user_ip = %s';
            $prepare_values[] = $args['ip_address'];
        }
        
        $where_clause = implode(' AND ', $where);
        
        // Build query with user information
        $query = "SELECT e.*, 
                         u.display_name, u.user_login, u.user_email
                  FROM `{$this->table_name}` e
                  LEFT JOIN `{$this->wpdb->users}` u ON e.user_id = u.ID
                  WHERE {$where_clause}
                  ORDER BY e.timestamp DESC
                  LIMIT %d OFFSET %d";
        
        $prepare_values[] = $args['limit'];
        $prepare_values[] = $args['offset'];
        
        return $this->wpdb->get_results(
            $this->wpdb->prepare($query, $prepare_values)
        );
    }
    
    /**
     * Get login statistics for analytics
     */
    public function get_login_stats($date_from = '', $date_to = '') {
        // Create cache key based on date parameters
        $cache_key = 'cel_login_stats_' . md5($date_from . '_' . $date_to);
        $cached_stats = get_transient($cache_key);
        
        if ($cached_stats !== false) {
            return $cached_stats;
        }
        
        $where_date = '';
        $prepare_values = array();
        
        if (!empty($date_from)) {
            $where_date .= " AND timestamp >= %s";
            $prepare_values[] = $date_from;
        }
        
        if (!empty($date_to)) {
            $where_date .= " AND timestamp <= %s";
            $prepare_values[] = $date_to;
        }
        
        $stats = array();
        
        // Use single optimized query to get all login counts at once
        $login_types_query = "SELECT 
                                error_type,
                                COUNT(*) as count
                              FROM {$this->table_name} 
                              WHERE error_type IN ('login_success', 'login_failed_valid_user', 'login_failed_invalid_user', 'login_failed_empty'){$where_date}
                              GROUP BY error_type";
        
        if (!empty($prepare_values)) {
            $login_counts = $this->wpdb->get_results($this->wpdb->prepare($login_types_query, $prepare_values), ARRAY_A);
        } else {
            $login_counts = $this->wpdb->get_results($login_types_query, ARRAY_A);
        }
        
        // Process results
        $stats['successful_logins'] = 0;
        $stats['failed_logins'] = 0;
        $stats['failed_by_type'] = array();
        
        foreach ($login_counts as $count_data) {
            if ($count_data['error_type'] === 'login_success') {
                $stats['successful_logins'] = (int)$count_data['count'];
            } else {
                $stats['failed_logins'] += (int)$count_data['count'];
                $stats['failed_by_type'][] = (object)$count_data;
            }
        }
        
        // Top IP addresses with failed attempts (limit to prevent memory issues)
        $query = "SELECT user_ip, COUNT(*) as attempts FROM {$this->table_name} 
                  WHERE error_type IN ('login_failed_valid_user', 'login_failed_invalid_user', 'login_failed_empty'){$where_date}
                  GROUP BY user_ip ORDER BY attempts DESC LIMIT 10";
        if (!empty($prepare_values)) {
            $stats['top_failed_ips'] = $this->wpdb->get_results($this->wpdb->prepare($query, $prepare_values));
        } else {
            $stats['top_failed_ips'] = $this->wpdb->get_results($query);
        }
        
        // Most targeted users (valid usernames with failed attempts) - limit to prevent memory issues
        $query = "SELECT e.user_id, u.user_login, COUNT(*) as attempts 
                  FROM {$this->table_name} e
                  LEFT JOIN {$this->wpdb->users} u ON e.user_id = u.ID
                  WHERE e.error_type = 'login_failed_valid_user'{$where_date}
                  GROUP BY e.user_id ORDER BY attempts DESC LIMIT 10";
        if (!empty($prepare_values)) {
            $stats['most_targeted_users'] = $this->wpdb->get_results($this->wpdb->prepare($query, $prepare_values));
        } else {
            $stats['most_targeted_users'] = $this->wpdb->get_results($query);
        }
        
        // Cache for 10 minutes (longer for expensive login stats)
        set_transient($cache_key, $stats, 10 * MINUTE_IN_SECONDS);
        
        return $stats;
    }
    
    /**
     * Get client IP address
     */
    private function get_client_ip() {
        $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                $ip = sanitize_text_field(wp_unslash($_SERVER[$key]));
                
                // Handle comma-separated IPs (from proxies)
                if (strpos($ip, ',') !== false) {
                    $ip = explode(',', $ip)[0];
                }
                
                $ip = trim($ip);
                
                if (filter_var($ip, FILTER_VALIDATE_IP, 
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
        
        // Return localhost if no valid IP found
        return '127.0.0.1';
    }
    
    /**
     * Validate regex pattern to prevent ReDoS attacks
     */
    private function is_safe_regex($pattern) {
        // Check for basic validity
        if (@preg_match($pattern, '') === false) {
            return false;
        }
        
        // Check for dangerous patterns that could cause ReDoS
        $dangerous_patterns = array(
            '/\(\?:\*/',           // (?:*)+ patterns
            '/\*\+/',              // *+ quantifiers
            '/\+\*/',              // +* quantifiers
            '/\{\d+,\}\+/',        // {n,}+ patterns
            '/\(\?![^)]*\)\*/',    // Negative lookahead with *
            '/\([^)]*\)\{[^}]*,\}\*/', // Nested quantifiers
        );
        
        foreach ($dangerous_patterns as $dangerous) {
            if (preg_match($dangerous, $pattern)) {
                return false;
            }
        }
        
        // Limit complexity - no more than 10 quantifiers
        if (preg_match_all('/[*+?{]/', $pattern) > 10) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Clear performance caches
     */
    public function clear_performance_cache() {
        // Clear error statistics cache
        delete_transient('cel_error_stats');
        
        // Clear login statistics caches (clear all login stat variations)
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_cel_login_stats_%' 
             OR option_name LIKE '_transient_timeout_cel_login_stats_%'"
        );
        
        return true;
    }
    
    /**
     * Force recreate tables (for manual admin intervention)
     */
    public function force_recreate_tables() {
        if ($this->should_log_debug()) {
            error_log('CEL: FORCE RECREATION: Starting manual table recreation process');
        }
        
        // First, try to drop existing tables if they exist
        $this->safely_drop_tables();
        
        // Wait a moment for the database to process the drops
        usleep(500000); // 0.5 seconds
        
        // Now recreate the tables
        $result = $this->create_table();
        
        if ($this->should_log_debug()) {
            error_log('CEL: FORCE RECREATION: Process completed - ' . ($result ? 'SUCCESS' : 'FAILED'));
        }
        
        return $result;
    }
    
    /**
     * Safely drop existing tables
     */
    private function safely_drop_tables() {
        $tables_to_drop = array(
            $this->table_name,
            $this->wpdb->prefix . 'console_errors_ip_mapping',
            $this->wpdb->prefix . 'console_errors_ignore_patterns'
        );
        
        foreach ($tables_to_drop as $table_name) {
            if ($this->table_exists($table_name)) {
                $drop_sql = "DROP TABLE IF EXISTS {$table_name}";
                $result = $this->wpdb->query($drop_sql);
                
                if ($this->should_log_debug()) {
                    error_log("CEL: FORCE RECREATION: Dropped table {$table_name} - " . ($result !== false ? 'SUCCESS' : 'FAILED'));
                    if ($result === false && $this->wpdb->last_error) {
                        error_log("CEL: FORCE RECREATION: Drop error: " . $this->wpdb->last_error);
                    }
                }
            }
        }
    }
    
    /**
     * Repair tables (attempt to fix corrupted tables)
     */
    public function repair_tables() {
        $repair_results = array();
        $tables_to_repair = array(
            'main' => $this->table_name,
            'mapping' => $this->wpdb->prefix . 'console_errors_ip_mapping',
            'ignore' => $this->wpdb->prefix . 'console_errors_ignore_patterns'
        );
        
        foreach ($tables_to_repair as $table_key => $table_name) {
            if ($this->table_exists($table_name)) {
                // Try REPAIR TABLE
                $repair_sql = "REPAIR TABLE {$table_name}";
                $repair_result = $this->wpdb->query($repair_sql);
                
                // Try CHECK TABLE to verify integrity
                $check_sql = "CHECK TABLE {$table_name}";
                $check_result = $this->wpdb->get_results($check_sql);
                
                $repair_results[$table_key] = array(
                    'table_name' => $table_name,
                    'repair_result' => $repair_result,
                    'check_result' => $check_result,
                    'repair_success' => ($repair_result !== false),
                    'integrity_ok' => !empty($check_result) && 
                        (isset($check_result[0]->Msg_text) && 
                         strpos(strtolower($check_result[0]->Msg_text), 'ok') !== false)
                );
                
                if ($this->should_log_debug()) {
                    error_log("CEL: REPAIR: Table {$table_name} repair result: " . ($repair_result !== false ? 'SUCCESS' : 'FAILED'));
                    if (!empty($check_result)) {
                        error_log("CEL: REPAIR: Table {$table_name} integrity: " . print_r($check_result, true));
                    }
                }
            } else {
                $repair_results[$table_key] = array(
                    'table_name' => $table_name,
                    'repair_result' => false,
                    'check_result' => array(),
                    'repair_success' => false,
                    'integrity_ok' => false,
                    'error' => 'Table does not exist'
                );
            }
        }
        
        return $repair_results;
    }
    
    /**
     * Get creation failure details for admin display
     */
    public function get_creation_failure_details() {
        return get_option('cel_table_creation_failures', null);
    }
    
    /**
     * Clear creation failure details
     */
    public function clear_creation_failure_details() {
        return delete_option('cel_table_creation_failures');
    }
    
    /**
     * Test database connectivity and permissions
     */
    public function test_database_connectivity() {
        $tests = array();
        
        // Test 1: Basic connectivity
        $tests['connectivity'] = array(
            'name' => 'Database Connectivity',
            'success' => false,
            'message' => ''
        );
        
        try {
            $result = $this->wpdb->get_var("SELECT 1");
            if ($result === '1') {
                $tests['connectivity']['success'] = true;
                $tests['connectivity']['message'] = 'Database connection successful';
            } else {
                $tests['connectivity']['message'] = 'Database connection failed - no response';
            }
        } catch (Exception $e) {
            $tests['connectivity']['message'] = 'Database connection exception: ' . $e->getMessage();
        }
        
        // Test 2: CREATE privilege
        $tests['create_privilege'] = array(
            'name' => 'CREATE Table Privilege',
            'success' => false,
            'message' => ''
        );
        
        $test_table = $this->wpdb->prefix . 'cel_test_' . time();
        $create_sql = "CREATE TABLE {$test_table} (id INT NOT NULL AUTO_INCREMENT, PRIMARY KEY (id))";
        
        $create_result = $this->wpdb->query($create_sql);
        if ($create_result !== false) {
            $tests['create_privilege']['success'] = true;
            $tests['create_privilege']['message'] = 'CREATE privilege confirmed';
            
            // Clean up test table
            $this->wpdb->query("DROP TABLE IF EXISTS {$test_table}");
        } else {
            $tests['create_privilege']['message'] = 'CREATE privilege denied: ' . ($this->wpdb->last_error ?: 'Unknown error');
        }
        
        // Test 3: WordPress tables access
        $tests['wp_tables_access'] = array(
            'name' => 'WordPress Tables Access',
            'success' => false,
            'message' => ''
        );
        
        try {
            $options_count = $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->options} LIMIT 1");
            if ($options_count !== null) {
                $tests['wp_tables_access']['success'] = true;
                $tests['wp_tables_access']['message'] = 'WordPress tables accessible';
            } else {
                $tests['wp_tables_access']['message'] = 'Cannot access WordPress options table';
            }
        } catch (Exception $e) {
            $tests['wp_tables_access']['message'] = 'WordPress tables access exception: ' . $e->getMessage();
        }
        
        // Test 4: Character set support
        $tests['charset_support'] = array(
            'name' => 'Character Set Support',
            'success' => false,
            'message' => ''
        );
        
        try {
            $charset_result = $this->wpdb->get_var("SHOW VARIABLES LIKE 'character_set_database'");
            if ($charset_result) {
                $tests['charset_support']['success'] = true;
                $tests['charset_support']['message'] = 'Character set support confirmed';
            } else {
                $tests['charset_support']['message'] = 'Cannot determine character set support';
            }
        } catch (Exception $e) {
            $tests['charset_support']['message'] = 'Character set test exception: ' . $e->getMessage();
        }
        
        return $tests;
    }
    
    /**
     * Get performance cache status
     */
    public function get_cache_status() {
        $status = array();
        
        // Check error stats cache
        $status['error_stats_cached'] = (get_transient('cel_error_stats') !== false);
        
        // Count login stats caches
        global $wpdb;
        $login_cache_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_cel_login_stats_%'"
        );
        $status['login_stats_cache_count'] = (int)$login_cache_count;
        
        return $status;
    }
    
    /**
     * Debug helper - output comprehensive system information
     */
    public function debug_system_info() {
        global $wp_version;
        
        $info = array(
            'wordpress_version' => $wp_version,
            'php_version' => PHP_VERSION,
            'mysql_version' => $this->wpdb->db_version(),
            'table_prefix' => $this->wpdb->prefix,
            'database_name' => DB_NAME,
            'database_host' => DB_HOST,
            'database_charset' => DB_CHARSET,
            'database_collate' => DB_COLLATE,
            'wp_debug_enabled' => defined('WP_DEBUG') && WP_DEBUG,
            'wp_debug_log_enabled' => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'user_permissions' => array(
                'activate_plugins' => current_user_can('activate_plugins'),
                'manage_options' => current_user_can('manage_options'),
                'is_admin' => is_admin()
            )
        );
        
        // Test basic database operations
        $db_tests = $this->test_database_connectivity();
        $info['database_tests'] = $db_tests;
        
        // Get table status
        $info['table_status'] = $this->get_table_status();
        
        // Get any stored failure details
        $info['creation_failures'] = get_option('cel_table_creation_failures');
        
        return $info;
    }
    
    /**
     * Get database connection for other classes
     */
    public function get_wpdb() {
        return $this->wpdb;
    }
}