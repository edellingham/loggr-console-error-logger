<?php
/**
 * Plugin Name: Loggr
 * Plugin URI: https://cloudnineweb.co
 * Description: Captures and logs browser console errors, JavaScript errors, and AJAX failures to help diagnose client-side issues, especially login problems.
 * Version: 1.2.0
 * Author: Cloud Nine Web
 * Author URI: https://cloudnineweb.co
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: console-error-logger
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CEL_VERSION', '1.2.0');
define('CEL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CEL_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CEL_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Include required files
require_once CEL_PLUGIN_DIR . 'includes/class-database.php';
require_once CEL_PLUGIN_DIR . 'includes/class-error-logger.php';
require_once CEL_PLUGIN_DIR . 'includes/class-admin.php';
require_once CEL_PLUGIN_DIR . 'includes/class-diagnostics.php';

/**
 * Main plugin class
 */
class Console_Error_Logger {
    
    /**
     * Instance of this class
     */
    private static $instance = null;
    
    /**
     * Database handler
     */
    private $database;
    
    /**
     * Error logger handler
     */
    private $error_logger;
    
    /**
     * Admin handler
     */
    private $admin;
    
    /**
     * Diagnostics handler
     */
    private $diagnostics;
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        // Initialize components
        $this->database = new CEL_Database();
        $this->error_logger = new CEL_Error_Logger();
        $this->admin = new CEL_Admin();
        $this->diagnostics = new CEL_Diagnostics();
        
        // Set up hooks
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Uninstall hook - points to uninstall.php file
        // This will be called when the plugin is deleted via WordPress admin
        
        // Initialize plugin
        add_action('init', array($this, 'init'));
        
        // Enqueue scripts
        add_action('login_enqueue_scripts', array($this, 'enqueue_login_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_cel_log_error', array($this->error_logger, 'handle_ajax_log_error'));
        add_action('wp_ajax_nopriv_cel_log_error', array($this->error_logger, 'handle_ajax_log_error'));
        add_action('wp_ajax_cel_add_ignore_pattern', array($this, 'handle_add_ignore_pattern'));
        add_action('wp_ajax_cel_toggle_ignore_pattern', array($this, 'handle_toggle_ignore_pattern'));
        
        // User tracking hooks
        add_action('wp_login', array($this, 'track_user_login'), 10, 2);
        add_action('wp_login_failed', array($this, 'track_failed_login'), 10, 1);
        
        // Admin hooks
        if (is_admin()) {
            add_action('admin_menu', array($this->admin, 'add_admin_menu'));
            add_action('admin_enqueue_scripts', array($this->admin, 'enqueue_admin_scripts'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_error_logger'));
            add_action('wp_ajax_cel_clear_logs', array($this->admin, 'handle_clear_logs'));
        }
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create database table
        $this->database->create_table();
        
        // Set default options
        add_option('cel_version', CEL_VERSION);
        add_option('cel_settings', array(
            'enable_login_monitoring' => true,
            'enable_site_monitoring' => false,
            'login_timeout_seconds' => 10,
            'max_log_entries' => 1000,
            'auto_cleanup_days' => 30
        ));
        
        // Clear rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clean up scheduled events if any
        wp_clear_scheduled_hook('cel_cleanup_logs');
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Load textdomain for translations
        load_plugin_textdomain('console-error-logger', false, dirname(CEL_PLUGIN_BASENAME) . '/languages');
        
        // Schedule cleanup if enabled
        if (!wp_next_scheduled('cel_cleanup_logs')) {
            wp_schedule_event(time(), 'daily', 'cel_cleanup_logs');
        }
        add_action('cel_cleanup_logs', array($this->database, 'cleanup_old_logs'));
    }
    
    /**
     * Enqueue scripts for login page
     */
    public function enqueue_login_scripts() {
        $this->enqueue_error_logger_script(true);
    }
    
    /**
     * Enqueue scripts for frontend (if enabled)
     */
    public function enqueue_frontend_scripts() {
        $settings = get_option('cel_settings', array());
        
        // Load on frontend if site monitoring is enabled
        if (!empty($settings['enable_site_monitoring']) && !is_admin()) {
            $this->enqueue_error_logger_script(false);
        }
    }
    
    /**
     * Enqueue error logger on admin pages
     */
    public function enqueue_admin_error_logger($hook) {
        // Only load on our plugin's admin page
        if ($hook === 'tools_page_console-error-logger') {
            $this->enqueue_error_logger_script(false);
        }
    }
    
    /**
     * Enqueue the error logger JavaScript
     */
    private function enqueue_error_logger_script($is_login_page = false) {
        wp_enqueue_script(
            'cel-error-logger',
            CEL_PLUGIN_URL . 'assets/js/console-error-logger.js',
            array('jquery'),
            CEL_VERSION,
            true
        );
        
        // Localize script with necessary data
        $settings = get_option('cel_settings', array());
        wp_localize_script('cel-error-logger', 'cel_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cel_admin_nonce'),
            'is_login_page' => $is_login_page,
            'login_timeout' => isset($settings['login_timeout_seconds']) ? absint($settings['login_timeout_seconds']) : 10,
            'page_url' => isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '',
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : ''
        ));
    }
    
    /**
     * Track user login for IP association and comprehensive logging
     */
    public function track_user_login($user_login, $user) {
        $user_ip = $this->get_client_ip();
        
        // Track the IP-to-user mapping
        $this->database->track_user_ip($user->ID, $user_ip);
        
        // Track comprehensive login details
        $this->database->track_login($user);
        
        // Update any recent errors from this IP to associate with this user
        $this->associate_recent_errors_with_user($user->ID, $user_ip);
    }
    
    /**
     * Track failed login attempts
     */
    public function track_failed_login($username) {
        $user_ip = $this->get_client_ip();
        
        // Try to get user ID if the username exists
        $user_id = null;
        $user_exists = false;
        if (!empty($username)) {
            $user = get_user_by('login', $username);
            if (!$user) {
                $user = get_user_by('email', $username);
            }
            if ($user) {
                $user_id = $user->ID;
                $user_exists = true;
            }
        }
        
        // Log the failed login attempt
        $this->database->track_failed_login($username, $user_ip, $user_id, $user_exists);
    }
    
    /**
     * Associate recent errors with logged-in user
     */
    private function associate_recent_errors_with_user($user_id, $ip_address) {
        global $wpdb;
        
        // Update errors from the last 30 minutes from this IP that don't have an associated user
        $table_name = $this->database->get_table_name();
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table_name} 
             SET associated_user_id = %d 
             WHERE user_ip = %s 
             AND associated_user_id IS NULL 
             AND timestamp > DATE_SUB(NOW(), INTERVAL 30 MINUTE)",
            $user_id, $ip_address
        ));
        
        return $result;
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
    
    /**
     * Validate regex pattern to prevent ReDoS attacks
     */
    private function validate_regex_pattern($pattern) {
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
     * Handle AJAX request to add ignore pattern
     */
    public function handle_add_ignore_pattern() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'cel_admin_nonce')) {
            wp_send_json_error(array('message' => 'Invalid security token'));
            return;
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        // Validate input size
        if (strlen(wp_unslash($_POST['pattern_value'] ?? '')) > 5000) {
            wp_send_json_error(array('message' => 'Pattern value too large'));
            return;
        }
        
        $pattern_type = sanitize_text_field(wp_unslash($_POST['pattern_type'] ?? ''));
        $pattern_value = sanitize_textarea_field(wp_unslash($_POST['pattern_value'] ?? ''));
        $notes = sanitize_textarea_field(wp_unslash($_POST['notes'] ?? ''));
        
        // Validate regex patterns to prevent ReDoS attacks
        if ($pattern_type === 'regex') {
            if (!$this->validate_regex_pattern($pattern_value)) {
                wp_send_json_error(array('message' => 'Invalid or potentially dangerous regex pattern'));
                return;
            }
        }
        
        $result = $this->database->add_ignore_pattern($pattern_type, $pattern_value, $notes);
        
        if ($result !== false) {
            wp_send_json_success(array('message' => 'Ignore pattern added successfully'));
        } else {
            wp_send_json_error(array('message' => 'Failed to add ignore pattern'));
        }
    }
    
    /**
     * Handle AJAX request to toggle ignore pattern
     */
    public function handle_toggle_ignore_pattern() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'cel_admin_nonce')) {
            wp_send_json_error(array('message' => 'Invalid security token'));
            return;
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        $pattern_id = absint(wp_unslash($_POST['pattern_id'] ?? 0));
        
        $result = $this->database->toggle_ignore_pattern($pattern_id);
        
        if ($result !== false) {
            wp_send_json_success(array('message' => 'Ignore pattern toggled successfully'));
        } else {
            wp_send_json_error(array('message' => 'Failed to toggle ignore pattern'));
        }
    }
}

// Initialize the plugin
add_action('plugins_loaded', array('Console_Error_Logger', 'get_instance'));