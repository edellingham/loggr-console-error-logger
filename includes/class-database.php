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
     * Create database table
     */
    public function create_table() {
        $charset_collate = $this->wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$this->table_name} (
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
        ) $charset_collate;";
        
        // Create IP-to-user mapping table
        $mapping_table = $this->wpdb->prefix . 'console_errors_ip_mapping';
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
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        dbDelta($mapping_sql);
        
        // Create ignore patterns table
        $ignore_table = $this->wpdb->prefix . 'console_errors_ignore_patterns';
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
        ) $charset_collate;";
        
        dbDelta($ignore_sql);
        
        // Update database version
        update_option('cel_db_version', '1.2.0');
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
            'error_message' => wp_kses_post($data['error_message']),
            'error_source' => isset($data['error_source']) ? esc_url_raw($data['error_source']) : null,
            'error_line' => isset($data['error_line']) ? absint($data['error_line']) : null,
            'error_column' => isset($data['error_column']) ? absint($data['error_column']) : null,
            'stack_trace' => isset($data['stack_trace']) ? wp_kses_post($data['stack_trace']) : null,
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
        $query = "SELECT * FROM {$this->table_name} WHERE {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
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
        $query = "SELECT COUNT(*) FROM {$this->table_name} WHERE {$where_clause}";
        
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
        $stats = array();
        
        // Total errors
        $stats['total'] = $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        
        // Errors by type
        $stats['by_type'] = $this->wpdb->get_results(
            "SELECT error_type, COUNT(*) as count 
             FROM {$this->table_name} 
             GROUP BY error_type 
             ORDER BY count DESC"
        );
        
        // Recent errors (last 24 hours)
        $stats['recent_24h'] = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} 
                 WHERE timestamp > %s",
                date('Y-m-d H:i:s', strtotime('-24 hours'))
            )
        );
        
        // Login page errors
        $stats['login_errors'] = $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE is_login_page = 1"
        );
        
        return $stats;
    }
    
    /**
     * Get last error timestamp
     */
    public function get_last_error_time() {
        return $this->wpdb->get_var(
            "SELECT timestamp FROM {$this->table_name} ORDER BY timestamp DESC LIMIT 1"
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
                  FROM {$this->table_name} e
                  LEFT JOIN {$this->wpdb->users} u1 ON e.user_id = u1.ID
                  LEFT JOIN {$this->wpdb->users} u2 ON e.associated_user_id = u2.ID
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
        return $this->wpdb->query("TRUNCATE TABLE {$this->table_name}");
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
                    "DELETE FROM {$this->table_name} WHERE timestamp < %s",
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
        
        $count = $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        
        if ($count > $max_entries) {
            // Delete oldest entries to maintain max limit
            $to_delete = $count - $max_entries;
            
            $this->wpdb->query(
                $this->wpdb->prepare(
                    "DELETE FROM {$this->table_name} 
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
        $count = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} 
                 WHERE user_ip = %s 
                 AND timestamp > %s",
                $ip,
                date('Y-m-d H:i:s', strtotime('-1 minute'))
            )
        );
        
        return $count > 10;
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
                    if (isset($error_data['error_message']) && 
                        @preg_match($pattern_value, $error_data['error_message'])) {
                        return true;
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
                  FROM {$this->table_name} e
                  LEFT JOIN {$this->wpdb->users} u ON e.user_id = u.ID
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
        
        // Total successful logins
        $query = "SELECT COUNT(*) FROM {$this->table_name} WHERE error_type = 'login_success'{$where_date}";
        if (!empty($prepare_values)) {
            $stats['successful_logins'] = $this->wpdb->get_var($this->wpdb->prepare($query, $prepare_values));
        } else {
            $stats['successful_logins'] = $this->wpdb->get_var($query);
        }
        
        // Total failed logins
        $query = "SELECT COUNT(*) FROM {$this->table_name} WHERE error_type IN ('login_failed_valid_user', 'login_failed_invalid_user', 'login_failed_empty'){$where_date}";
        if (!empty($prepare_values)) {
            $stats['failed_logins'] = $this->wpdb->get_var($this->wpdb->prepare($query, $prepare_values));
        } else {
            $stats['failed_logins'] = $this->wpdb->get_var($query);
        }
        
        // Failed logins by type
        $query = "SELECT error_type, COUNT(*) as count FROM {$this->table_name} 
                  WHERE error_type IN ('login_failed_valid_user', 'login_failed_invalid_user', 'login_failed_empty'){$where_date}
                  GROUP BY error_type";
        if (!empty($prepare_values)) {
            $stats['failed_by_type'] = $this->wpdb->get_results($this->wpdb->prepare($query, $prepare_values));
        } else {
            $stats['failed_by_type'] = $this->wpdb->get_results($query);
        }
        
        // Top IP addresses with failed attempts
        $query = "SELECT user_ip, COUNT(*) as attempts FROM {$this->table_name} 
                  WHERE error_type IN ('login_failed_valid_user', 'login_failed_invalid_user', 'login_failed_empty'){$where_date}
                  GROUP BY user_ip ORDER BY attempts DESC LIMIT 10";
        if (!empty($prepare_values)) {
            $stats['top_failed_ips'] = $this->wpdb->get_results($this->wpdb->prepare($query, $prepare_values));
        } else {
            $stats['top_failed_ips'] = $this->wpdb->get_results($query);
        }
        
        // Most targeted users (valid usernames with failed attempts)
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
}