<?php
/**
 * Error Logger handler class for Console Error Logger
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class CEL_Error_Logger {
    
    /**
     * Database handler
     */
    private $database;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->database = new CEL_Database();
        
        // Add debug logging if WP_DEBUG is enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            add_action('cel_debug_log', array($this, 'debug_log'), 10, 2);
        }
    }
    
    /**
     * Debug logging
     */
    public function debug_log($message, $data = null) {
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('[CEL Debug] ' . $message . ($data ? ' - Data: ' . print_r($data, true) : ''));
        }
    }
    
    /**
     * Handle AJAX error logging request
     */
    public function handle_ajax_log_error() {
        $this->debug_log('AJAX request received', $_POST);
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'cel_admin_nonce')) {
            $this->debug_log('Nonce verification failed', array('nonce' => sanitize_text_field(wp_unslash($_POST['nonce'] ?? 'not set'))));
            wp_send_json_error(array('message' => __('Invalid security token', 'console-error-logger')));
            return;
        }
        
        // Check if we have error data
        if (!isset($_POST['error_data']) || empty($_POST['error_data'])) {
            $this->debug_log('No error data provided');
            wp_send_json_error(array('message' => __('No error data provided', 'console-error-logger')));
            return;
        }
        
        // Validate payload size (max 50KB)
        $error_data_raw = wp_unslash($_POST['error_data']);
        if (strlen($error_data_raw) > 51200) {
            $this->debug_log('Error data payload too large');
            wp_send_json_error(array('message' => __('Error data payload too large', 'console-error-logger')));
            return;
        }
        
        // Parse error data
        $error_data = json_decode($error_data_raw, true);
        
        if (!$error_data) {
            $this->debug_log('Invalid JSON format', sanitize_text_field($error_data_raw));
            wp_send_json_error(array('message' => __('Invalid error data format', 'console-error-logger')));
            return;
        }
        
        $this->debug_log('Parsed error data', $error_data);
        
        // Validate required fields
        if (!isset($error_data['error_type']) || !isset($error_data['error_message'])) {
            $this->debug_log('Missing required fields', $error_data);
            wp_send_json_error(array('message' => __('Missing required error fields', 'console-error-logger')));
            return;
        }
        
        // Process and enrich error data
        $processed_data = $this->process_error_data($error_data);
        
        // Check if error should be ignored
        if ($this->database->should_ignore_error($processed_data)) {
            $this->debug_log('Error ignored due to ignore pattern', $processed_data);
            wp_send_json_success(array(
                'message' => __('Error ignored due to active ignore pattern', 'console-error-logger'),
                'ignored' => true
            ));
            return;
        }
        
        // Try to associate with a user based on IP
        $client_ip = $this->get_client_ip();
        $associated_user_id = $this->database->get_associated_user_by_ip($client_ip);
        if ($associated_user_id) {
            $processed_data['associated_user_id'] = $associated_user_id;
        }
        
        // Log the error to database
        $result = $this->database->insert_error($processed_data);
        
        if ($result) {
            // Check if this is a critical error that needs immediate attention
            $this->check_critical_error($processed_data);
            
            wp_send_json_success(array(
                'message' => __('Error logged successfully', 'console-error-logger'),
                'error_id' => $this->database->get_table_name()
            ));
        } else {
            // Get more detailed error information
            global $wpdb;
            $db_error = $wpdb->last_error;
            $error_message = __('Failed to log error', 'console-error-logger');
            
            if (!empty($db_error)) {
                $error_message .= ': ' . $db_error;
                error_log('Console Error Logger: Database error - ' . $db_error);
            }
            
            wp_send_json_error(array('message' => $error_message));
        }
    }
    
    /**
     * Process and enrich error data before storing
     */
    private function process_error_data($data) {
        // Map JavaScript error types to our categories
        $error_type_map = array(
            'error' => 'javascript_error',
            'unhandledrejection' => 'unhandled_rejection',
            'console.error' => 'console_error',
            'console.warn' => 'console_warning',
            'ajax_error' => 'ajax_error',
            'fetch_error' => 'fetch_error',
            'resource_error' => 'resource_error',
            'login_timeout' => 'login_timeout',
            'syntax_error' => 'javascript_error',
            'type_error' => 'javascript_error',
            'reference_error' => 'javascript_error'
        );
        
        // Normalize error type
        $error_type = isset($data['error_type']) ? strtolower($data['error_type']) : 'unknown';
        if (isset($error_type_map[$error_type])) {
            $data['error_type'] = $error_type_map[$error_type];
        }
        
        // Add server-side data
        $data['user_agent'] = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        $data['page_url'] = isset($data['page_url']) ? $data['page_url'] : 
                           (isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '');
        
        // Check if this is from login page
        if (!isset($data['is_login_page'])) {
            $data['is_login_page'] = $this->is_login_page($data['page_url']);
        }
        
        // Generate session ID if not provided
        if (!isset($data['session_id'])) {
            $data['session_id'] = $this->get_or_create_session_id();
        }
        
        // Parse stack trace if provided
        if (isset($data['stack_trace']) && is_string($data['stack_trace'])) {
            $data['stack_trace'] = $this->parse_stack_trace($data['stack_trace']);
        }
        
        // Extract line and column from error if not provided
        if (!isset($data['error_line']) && isset($data['error_details'])) {
            if (preg_match('/line (\d+)/i', $data['error_details'], $matches)) {
                $data['error_line'] = (int)$matches[1];
            }
        }
        
        if (!isset($data['error_column']) && isset($data['error_details'])) {
            if (preg_match('/column (\d+)/i', $data['error_details'], $matches)) {
                $data['error_column'] = (int)$matches[1];
            }
        }
        
        // Handle AJAX/Fetch specific data
        if (in_array($data['error_type'], array('ajax_error', 'fetch_error'))) {
            $additional_data = array();
            
            if (isset($data['request_url'])) {
                $additional_data['request_url'] = $data['request_url'];
            }
            if (isset($data['request_method'])) {
                $additional_data['request_method'] = $data['request_method'];
            }
            if (isset($data['response_status'])) {
                $additional_data['response_status'] = $data['response_status'];
            }
            if (isset($data['response_text'])) {
                $additional_data['response_text'] = substr($data['response_text'], 0, 500); // Limit size
            }
            
            $data['additional_data'] = $additional_data;
        }
        
        // Handle login timeout specific data
        if ($data['error_type'] === 'login_timeout') {
            $additional_data = isset($data['additional_data']) ? $data['additional_data'] : array();
            $additional_data['timeout_duration'] = isset($data['timeout_duration']) ? $data['timeout_duration'] : 10;
            $additional_data['username_attempted'] = isset($data['username']) ? sanitize_user($data['username']) : '';
            $data['additional_data'] = $additional_data;
        }
        
        return $data;
    }
    
    /**
     * Check if the error is critical and needs immediate attention
     */
    private function check_critical_error($error_data) {
        $critical_conditions = array(
            // Login timeout is always critical
            'login_timeout' => true,
            
            // Multiple AJAX errors in login context
            'ajax_error' => function($data) {
                return isset($data['is_login_page']) && $data['is_login_page'];
            },
            
            // Authentication related errors
            'javascript_error' => function($data) {
                $auth_keywords = array('auth', 'login', 'password', 'credential', 'token');
                $message = strtolower($data['error_message']);
                foreach ($auth_keywords as $keyword) {
                    if (strpos($message, $keyword) !== false) {
                        return true;
                    }
                }
                return false;
            }
        );
        
        $error_type = $error_data['error_type'];
        
        if (isset($critical_conditions[$error_type])) {
            $is_critical = false;
            
            if (is_bool($critical_conditions[$error_type])) {
                $is_critical = $critical_conditions[$error_type];
            } elseif (is_callable($critical_conditions[$error_type])) {
                $is_critical = call_user_func($critical_conditions[$error_type], $error_data);
            }
            
            if ($is_critical) {
                // Trigger action for critical errors (can be used by other plugins)
                do_action('cel_critical_error_logged', $error_data);
                
                // Log to WordPress error log as well
                error_log(sprintf(
                    'Console Error Logger - Critical Error: %s - %s on %s',
                    $error_type,
                    $error_data['error_message'],
                    $error_data['page_url']
                ));
            }
        }
    }
    
    /**
     * Check if URL is login page
     */
    private function is_login_page($url) {
        if (empty($url)) {
            return false;
        }
        
        $login_patterns = array(
            '/wp-login\.php/',
            '/wp-admin/',
            '/login/',
            '/signin/',
            '/admin/'
        );
        
        foreach ($login_patterns as $pattern) {
            if (preg_match($pattern, $url)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get or create session ID for tracking related errors
     */
    private function get_or_create_session_id() {
        // Use WordPress transients instead of sessions for better compatibility
        $session_key = 'cel_session_' . $this->get_client_fingerprint();
        $session_id = get_transient($session_key);
        
        if (false === $session_id) {
            $session_id = 'cel_' . wp_generate_password(32, false);
            set_transient($session_key, $session_id, HOUR_IN_SECONDS);
        }
        
        return $session_id;
    }
    
    /**
     * Get client fingerprint for session tracking
     */
    private function get_client_fingerprint() {
        $fingerprint = '';
        
        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $fingerprint .= sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']));
        }
        
        if (isset($_SERVER['REMOTE_ADDR'])) {
            $fingerprint .= sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }
        
        return md5($fingerprint);
    }
    
    /**
     * Parse and clean stack trace
     */
    private function parse_stack_trace($stack_trace) {
        if (empty($stack_trace)) {
            return '';
        }
        
        // Remove sensitive information from stack trace
        $patterns = array(
            '/\/home\/[^\/]+/', // Unix home paths
            '/C:\\\\Users\\\\[^\\\\]+/', // Windows user paths
            '/password["\']?\s*[:=]\s*["\'][^"\']+["\']/', // Passwords
            '/token["\']?\s*[:=]\s*["\'][^"\']+["\']/', // Tokens
            '/api[_-]?key["\']?\s*[:=]\s*["\'][^"\']+["\']/', // API keys
        );
        
        $replacements = array(
            '/home/USER',
            'C:\\Users\\USER',
            'password: [REDACTED]',
            'token: [REDACTED]',
            'api_key: [REDACTED]',
        );
        
        $stack_trace = preg_replace($patterns, $replacements, $stack_trace);
        
        // Limit stack trace length
        if (strlen($stack_trace) > 5000) {
            $stack_trace = substr($stack_trace, 0, 5000) . "\n... (truncated)";
        }
        
        return $stack_trace;
    }
    
    /**
     * Get error statistics for dashboard widget
     */
    public function get_dashboard_stats() {
        return $this->database->get_error_stats();
    }
    
    /**
     * Get recent errors for dashboard widget
     */
    public function get_recent_errors($limit = 5) {
        return $this->database->get_errors(array(
            'limit' => $limit,
            'orderby' => 'timestamp',
            'order' => 'DESC'
        ));
    }
    
    /**
     * Get client IP address
     */
    private function get_client_ip() {
        // Check for various IP address headers
        $ip_headers = array(
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // Load balancers/proxies
            'HTTP_X_FORWARDED',          // Proxies
            'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster
            'HTTP_CLIENT_IP',            // Proxy
            'HTTP_FORWARDED_FOR',        // Proxies
            'HTTP_FORWARDED',            // Proxies
            'REMOTE_ADDR'                // Standard
        );

        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                
                // Validate IP address
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        // Return localhost if no valid IP found
        return '127.0.0.1';
    }
}