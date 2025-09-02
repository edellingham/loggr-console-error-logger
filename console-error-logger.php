<?php
/**
 * Plugin Name: Loggr
 * Plugin URI: https://cloudnineweb.co
 * Description: Captures and logs browser console errors, JavaScript errors, and AJAX failures to help diagnose client-side issues, especially login problems.
 * Version: 1.0.0
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
define('CEL_VERSION', '1.0.0');
define('CEL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CEL_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CEL_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Include required files
require_once CEL_PLUGIN_DIR . 'includes/class-database.php';
require_once CEL_PLUGIN_DIR . 'includes/class-error-logger.php';
require_once CEL_PLUGIN_DIR . 'includes/class-admin.php';

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
        
        // Initialize plugin
        add_action('init', array($this, 'init'));
        
        // Enqueue scripts
        add_action('login_enqueue_scripts', array($this, 'enqueue_login_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_cel_log_error', array($this->error_logger, 'handle_ajax_log_error'));
        add_action('wp_ajax_nopriv_cel_log_error', array($this->error_logger, 'handle_ajax_log_error'));
        
        // Admin hooks
        if (is_admin()) {
            add_action('admin_menu', array($this->admin, 'add_admin_menu'));
            add_action('admin_enqueue_scripts', array($this->admin, 'enqueue_admin_scripts'));
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
        
        // Only load on frontend if site monitoring is enabled
        if (!empty($settings['enable_site_monitoring']) && !is_admin()) {
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
            'nonce' => wp_create_nonce('cel_log_error_nonce'),
            'is_login_page' => $is_login_page,
            'login_timeout' => isset($settings['login_timeout_seconds']) ? absint($settings['login_timeout_seconds']) : 10,
            'page_url' => isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '',
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : ''
        ));
    }
}

// Initialize the plugin
add_action('plugins_loaded', array('Console_Error_Logger', 'get_instance'));