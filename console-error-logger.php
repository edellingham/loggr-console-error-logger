<?php
/**
 * Plugin Name: Loggr
 * Plugin URI: https://cloudnineweb.co
 * Description: Captures and logs browser console errors, JavaScript errors, and AJAX failures to help diagnose client-side issues, especially login problems.
 * Version: 1.2.3
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
define('CEL_VERSION', '1.2.3');
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
            add_action('wp_ajax_cel_create_tables', array($this, 'handle_manual_table_creation'));
            add_action('wp_ajax_cel_test_database', array($this, 'handle_database_test'));
            add_action('admin_notices', array($this, 'show_activation_notices'));
            
            // Check tables on every admin init
            add_action('admin_init', array($this, 'check_and_create_tables'));
        }
    }
    
    /**
     * Check and create tables if they don't exist
     * Runs on admin_init to ensure tables are always present
     */
    public function check_and_create_tables() {
        // Only check once per request
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;
        
        // Get table status
        $table_status = $this->database->get_table_status();
        
        // Check if any tables are missing
        $tables_missing = false;
        foreach ($table_status as $table) {
            if (!$table['exists']) {
                $tables_missing = true;
                break;
            }
        }
        
        // If tables are missing, try to create them
        if ($tables_missing) {
            error_log('CEL: Tables missing on admin_init, attempting to create...');
            $success = $this->database->create_table();
            
            if ($success) {
                error_log('CEL: Tables created successfully via admin_init');
                delete_option('cel_activation_error');
                delete_option('cel_connectivity_issues');
            } else {
                error_log('CEL: Failed to create tables via admin_init');
                
                // Store failure info for display
                $failure_details = $this->database->get_creation_failure_details();
                update_option('cel_table_creation_attempt', array(
                    'timestamp' => time(),
                    'success' => false,
                    'details' => $failure_details
                ));
            }
        }
    }
    
    /**
     * Plugin activation with enhanced error handling
     */
    public function activate() {
        // Force enable logging during activation for debugging
        $debug_enabled = defined('WP_DEBUG') && WP_DEBUG;
        
        // Log comprehensive activation start
        error_log('CEL: ================================');
        error_log('CEL: PLUGIN ACTIVATION STARTING');
        error_log('CEL: ================================');
        
        // Enable browser console debugging during activation
        $this->enable_browser_console_debug = true;
        
        // Test database connectivity before attempting table creation
        $db_tests = $this->database->test_database_connectivity();
        $connectivity_issues = array();
        
        foreach ($db_tests as $test_key => $test_result) {
            if (!$test_result['success']) {
                $connectivity_issues[] = $test_result['name'] . ': ' . $test_result['message'];
            }
        }
        
        // If there are connectivity issues, store them but continue with activation
        if (!empty($connectivity_issues)) {
            update_option('cel_connectivity_issues', $connectivity_issues);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('CEL: Database connectivity issues detected: ' . implode('; ', $connectivity_issues));
            }
        } else {
            delete_option('cel_connectivity_issues');
        }
        
        // Create database tables with comprehensive error checking
        $table_success = $this->database->create_table();
        
        // Get detailed table status for diagnostics
        $table_status = $this->database->get_table_status();
        
        // Log activation results
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('CEL: Table creation result: ' . ($table_success ? 'SUCCESS' : 'FAILED'));
            error_log('CEL: Table status: ' . print_r($table_status, true));
        }
        
        // Set default options
        add_option('cel_version', CEL_VERSION);
        add_option('cel_settings', array(
            'enable_login_monitoring' => true,
            'enable_site_monitoring' => false,
            'login_timeout_seconds' => 10,
            'max_log_entries' => 1000,
            'auto_cleanup_days' => 30
        ));
        
        // Store comprehensive activation status for admin notice
        if (!$table_success) {
            $error_details = $this->database->get_creation_failure_details();
            $error_message = 'Database table creation failed.';
            
            if ($error_details) {
                $error_message .= ' Last failure: ' . date('Y-m-d H:i:s', strtotime($error_details['timestamp']));
                if (!empty($error_details['failures'])) {
                    $failed_tables = array_keys($error_details['failures']);
                    $error_message .= ' (Failed tables: ' . implode(', ', $failed_tables) . ')';
                }
            }
            
            if (!empty($connectivity_issues)) {
                $error_message .= ' Connectivity issues detected.';
            }
            
            $error_message .= ' Please check the Diagnostics tab for detailed information.';
            
            add_option('cel_activation_error', $error_message);
        } else {
            delete_option('cel_activation_error'); // Clear any previous errors
            delete_option('cel_connectivity_issues'); // Clear connectivity issues on success
        }
        
        // Store activation timestamp for tracking
        update_option('cel_last_activation', time());
        
        // Store detailed debugging information for browser console
        $debug_info = array(
            'activation_time' => time(),
            'table_success' => $table_success,
            'table_status' => $table_status,
            'connectivity_issues' => $connectivity_issues,
            'creation_failure_details' => $table_success ? null : $this->database->get_creation_failure_details(),
            'wp_debug_enabled' => defined('WP_DEBUG') && WP_DEBUG,
            'database_version' => $this->database->get_wpdb()->db_version(),
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION
        );
        
        update_option('cel_activation_debug_info', $debug_info);
        
        // Clear rewrite rules
        flush_rewrite_rules();
        
        error_log('CEL: PLUGIN ACTIVATION COMPLETED - ' . ($table_success ? 'SUCCESS' : 'FAILED'));
        error_log('CEL: ================================');
        
        return $table_success;
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
     * Show activation notices
     */
    public function show_activation_notices() {
        $activation_error = get_option('cel_activation_error');
        $connectivity_issues = get_option('cel_connectivity_issues');
        $debug_info = get_option('cel_activation_debug_info');
        
        // ALWAYS output version and debug status to console
        ?>
        <script>
        console.log('%c🔧 Loggr Plugin v<?php echo CEL_VERSION; ?> Debug Status', 'background: #222; color: #bada55; font-size: 14px; padding: 5px;');
        console.log('Plugin Version: <?php echo CEL_VERSION; ?>');
        console.log('Activation Error Exists: <?php echo $activation_error ? 'YES' : 'NO'; ?>');
        console.log('Debug Info Available: <?php echo $debug_info ? 'YES' : 'NO'; ?>');
        </script>
        <?php
        
        // Always output debug information to browser console if available
        if ($debug_info) {
            ?>
            <script>
            console.group('🔧 Loggr Plugin Debug Information');
            console.log('Activation Time:', new Date(<?php echo $debug_info['activation_time'] * 1000; ?>).toLocaleString());
            console.log('Table Creation Success:', <?php echo json_encode($debug_info['table_success']); ?>);
            console.log('WordPress Version:', <?php echo json_encode($debug_info['wordpress_version']); ?>);
            console.log('PHP Version:', <?php echo json_encode($debug_info['php_version']); ?>);
            console.log('Database Version:', <?php echo json_encode($debug_info['database_version']); ?>);
            console.log('WP_DEBUG Enabled:', <?php echo json_encode($debug_info['wp_debug_enabled']); ?>);
            
            <?php if (!empty($debug_info['table_status'])): ?>
            console.group('📊 Table Status');
            <?php foreach ($debug_info['table_status'] as $table_key => $status): ?>
            console.log('<?php echo esc_js($table_key); ?> Table:', {
                exists: <?php echo json_encode($status['exists']); ?>,
                name: <?php echo json_encode($status['name']); ?>,
                rowCount: <?php echo json_encode($status['row_count']); ?>
            });
            <?php endforeach; ?>
            console.groupEnd();
            <?php endif; ?>
            
            <?php if (!empty($debug_info['connectivity_issues'])): ?>
            console.group('⚠️ Connectivity Issues');
            <?php foreach ($debug_info['connectivity_issues'] as $issue): ?>
            console.warn(<?php echo json_encode($issue); ?>);
            <?php endforeach; ?>
            console.groupEnd();
            <?php endif; ?>
            
            <?php if (!empty($debug_info['creation_failure_details'])): ?>
            console.group('❌ Table Creation Failures');
            console.log('Failure Details:', <?php echo json_encode($debug_info['creation_failure_details']); ?>);
            console.groupEnd();
            <?php endif; ?>
            
            console.groupEnd();
            </script>
            <?php
        }
        
        if ($activation_error) {
            $table_status = $this->database->get_table_status();
            
            // Check if tables actually exist despite activation error
            $tables_exist = !empty($table_status['main']['exists']) && 
                           !empty($table_status['mapping']['exists']) && 
                           !empty($table_status['ignore']['exists']);
            ?>
            <div class="notice notice-error is-dismissible" id="cel-activation-notice">
                <h3>Loggr Plugin - Database Setup Issue</h3>
                <p><strong>Error:</strong> <?php echo esc_html($activation_error); ?></p>
                
                <?php if (!empty($connectivity_issues)): ?>
                <div style="margin: 10px 0; padding: 10px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px;">
                    <p><strong>Connectivity Issues Detected:</strong></p>
                    <ul style="margin: 5px 0 0 20px;">
                        <?php foreach ($connectivity_issues as $issue): ?>
                            <li><?php echo esc_html($issue); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                
                <?php if ($tables_exist): ?>
                <div style="margin: 10px 0; padding: 10px; background: #d1ecf1; border: 1px solid #b8daff; border-radius: 4px;">
                    <p><strong>Good News:</strong> Database tables appear to exist despite activation errors. The plugin may still work correctly.</p>
                </div>
                <?php endif; ?>
                
                <?php if ($debug_info): ?>
                <div style="margin: 10px 0; padding: 10px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px;">
                    <details>
                        <summary style="cursor: pointer; font-weight: bold;">🔍 View Detailed Debug Information</summary>
                        <div style="margin-top: 10px; font-family: monospace; font-size: 12px;">
                            <p><strong>Activation Time:</strong> <?php echo esc_html(date('Y-m-d H:i:s', $debug_info['activation_time'])); ?></p>
                            <p><strong>Table Creation Success:</strong> <?php echo $debug_info['table_success'] ? '✅ YES' : '❌ NO'; ?></p>
                            <p><strong>WordPress Version:</strong> <?php echo esc_html($debug_info['wordpress_version']); ?></p>
                            <p><strong>PHP Version:</strong> <?php echo esc_html($debug_info['php_version']); ?></p>
                            <p><strong>Database Version:</strong> <?php echo esc_html($debug_info['database_version']); ?></p>
                            <p><strong>WP_DEBUG Enabled:</strong> <?php echo $debug_info['wp_debug_enabled'] ? '✅ YES' : '❌ NO'; ?></p>
                            
                            <?php if (!empty($debug_info['table_status'])): ?>
                            <h4>Table Status:</h4>
                            <ul style="margin-left: 20px;">
                                <?php foreach ($debug_info['table_status'] as $table_key => $status): ?>
                                <li><strong><?php echo esc_html(ucfirst($table_key)); ?> Table:</strong> 
                                    <?php echo $status['exists'] ? '✅ Exists' : '❌ Missing'; ?> 
                                    (<?php echo esc_html($status['name']); ?>) 
                                    - <?php echo esc_html($status['row_count']); ?> rows
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php endif; ?>
                            
                            <?php if (!empty($debug_info['creation_failure_details'])): ?>
                            <h4>Creation Failure Details:</h4>
                            <pre style="background: #fff; padding: 10px; border: 1px solid #ccc; overflow: auto; max-height: 200px;"><?php echo esc_html(print_r($debug_info['creation_failure_details'], true)); ?></pre>
                            <?php endif; ?>
                            
                            <p><em>💡 This debug information is also available in your browser's JavaScript console.</em></p>
                        </div>
                    </details>
                </div>
                <?php endif; ?>
                
                <div style="margin: 15px 0;">
                    <strong>Troubleshooting Options:</strong>
                    <div style="margin: 10px 0;">
                        <button type="button" class="button" id="cel-test-database">
                            Test Database Connection
                        </button>
                        <button type="button" class="button button-primary" id="cel-create-tables">
                            Create Tables Manually
                        </button>
                        <button type="button" class="button" id="cel-recreate-tables">
                            Force Recreate All Tables
                        </button>
                        <a href="<?php echo esc_url(admin_url('tools.php?page=console-error-logger&tab=diagnostics')); ?>" class="button">
                            View Full Diagnostics
                        </a>
                    </div>
                </div>
                
                <div id="cel-test-results" style="margin-top: 15px; display: none;">
                    <!-- Test results will be populated here -->
                </div>
            </div>
            <script>
            jQuery(document).ready(function($) {
                // Test database connection
                $('#cel-test-database').click(function() {
                    var button = $(this);
                    var resultsDiv = $('#cel-test-results');
                    
                    button.prop('disabled', true).text('Testing...');
                    resultsDiv.hide().html('');
                    
                    $.post(ajaxurl, {
                        action: 'cel_test_database',
                        nonce: '<?php echo wp_create_nonce('cel_admin_nonce'); ?>'
                    }, function(response) {
                        button.prop('disabled', false).text('Test Database Connection');
                        
                        var html = '<div style="padding: 10px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px;">';
                        html += '<h4>Database Test Results:</h4>';
                        
                        if (response.success && response.data.tests) {
                            $.each(response.data.tests, function(key, test) {
                                var icon = test.success ? '✅' : '❌';
                                var color = test.success ? 'green' : 'red';
                                html += '<p style="margin: 5px 0; color: ' + color + ';">';
                                html += icon + ' <strong>' + test.name + ':</strong> ' + test.message;
                                html += '</p>';
                            });
                            
                            if (response.data.table_status) {
                                html += '<h4>Table Status:</h4>';
                                $.each(response.data.table_status, function(key, status) {
                                    var icon = status.exists ? '✅' : '❌';
                                    var color = status.exists ? 'green' : 'red';
                                    html += '<p style="margin: 5px 0; color: ' + color + ';">';
                                    html += icon + ' <strong>' + key + ' table:</strong> ' + (status.exists ? 'Exists (' + status.row_count + ' rows)' : 'Missing');
                                    html += '</p>';
                                });
                            }
                        } else {
                            html += '<p style="color: red;">❌ Test failed: ' + (response.data ? response.data.message : 'Unknown error') + '</p>';
                        }
                        
                        html += '</div>';
                        resultsDiv.html(html).show();
                    }).fail(function() {
                        button.prop('disabled', false).text('Test Database Connection');
                        resultsDiv.html('<div style="padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; color: #721c24;">❌ Network error during test</div>').show();
                    });
                });
                
                // Create tables manually
                $('#cel-create-tables').click(function() {
                    handleTableAction($(this), 'create', 'Creating Tables...', 'Create Tables Manually');
                });
                
                // Force recreate tables
                $('#cel-recreate-tables').click(function() {
                    if (confirm('This will drop and recreate all tables. Any existing data will be lost. Continue?')) {
                        handleTableAction($(this), 'recreate', 'Recreating Tables...', 'Force Recreate All Tables');
                    }
                });
                
                function handleTableAction(button, actionType, loadingText, originalText) {
                    var resultsDiv = $('#cel-test-results');
                    
                    button.prop('disabled', true).text(loadingText);
                    resultsDiv.hide().html('');
                    
                    $.post(ajaxurl, {
                        action: 'cel_create_tables',
                        action_type: actionType,
                        nonce: '<?php echo wp_create_nonce('cel_admin_nonce'); ?>'
                    }, function(response) {
                        if (response.success) {
                            var notice = button.closest('.notice');
                            notice.removeClass('notice-error').addClass('notice-success');
                            notice.find('h3').text('Loggr Plugin - Success!');
                            notice.find('p:first').html('<strong>Success:</strong> ' + response.data.message);
                            
                            // Hide troubleshooting options
                            notice.find('div').not('#cel-test-results').hide();
                            
                            setTimeout(function() {
                                location.reload();
                            }, 3000);
                        } else {
                            button.prop('disabled', false).text(originalText);
                            
                            var html = '<div style="padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; color: #721c24;">';
                            html += '<p><strong>❌ Action failed:</strong> ' + (response.data ? response.data.message : 'Unknown error') + '</p>';
                            
                            if (response.data && response.data.failure_details) {
                                html += '<details style="margin-top: 10px;">';
                                html += '<summary>View Technical Details</summary>';
                                html += '<pre style="background: #fff; padding: 10px; margin: 10px 0; overflow: auto; font-size: 12px;">';
                                html += JSON.stringify(response.data.failure_details, null, 2);
                                html += '</pre>';
                                html += '</details>';
                            }
                            
                            html += '</div>';
                            resultsDiv.html(html).show();
                        }
                    }).fail(function() {
                        button.prop('disabled', false).text(originalText);
                        resultsDiv.html('<div style="padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; color: #721c24;">❌ Network error during operation</div>').show();
                    });
                }
            });
            </script>
            <?php
        }
    }
    
    /**
     * Handle manual table creation via AJAX with enhanced diagnostics
     */
    public function handle_manual_table_creation() {
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
        
        // Get the action type (create, recreate, repair, test)
        $action = isset($_POST['action_type']) ? sanitize_text_field($_POST['action_type']) : 'create';
        
        $response = array();
        
        switch ($action) {
            case 'test':
                // Test database connectivity and permissions
                $tests = $this->database->test_database_connectivity();
                $all_passed = true;
                
                foreach ($tests as $test) {
                    if (!$test['success']) {
                        $all_passed = false;
                        break;
                    }
                }
                
                $response['tests'] = $tests;
                $response['message'] = $all_passed ? 'All database tests passed!' : 'Some database tests failed. See details below.';
                
                if ($all_passed) {
                    wp_send_json_success($response);
                } else {
                    wp_send_json_error($response);
                }
                break;
                
            case 'recreate':
                // Force recreation of tables
                $success = $this->database->force_recreate_tables();
                
                if ($success) {
                    delete_option('cel_activation_error');
                    delete_option('cel_connectivity_issues');
                    $this->database->clear_creation_failure_details();
                    
                    $response['message'] = 'Database tables recreated successfully!';
                    $response['table_status'] = $this->database->get_table_status();
                    wp_send_json_success($response);
                } else {
                    $failure_details = $this->database->get_creation_failure_details();
                    $response['message'] = 'Failed to recreate database tables.';
                    $response['failure_details'] = $failure_details;
                    $response['table_status'] = $this->database->get_table_status();
                    wp_send_json_error($response);
                }
                break;
                
            case 'repair':
                // Repair existing tables
                $repair_results = $this->database->repair_tables();
                $all_repaired = true;
                
                foreach ($repair_results as $result) {
                    if (!$result['repair_success'] || !$result['integrity_ok']) {
                        $all_repaired = false;
                    }
                }
                
                $response['repair_results'] = $repair_results;
                $response['message'] = $all_repaired ? 'All tables repaired successfully!' : 'Some tables could not be repaired.';
                
                if ($all_repaired) {
                    wp_send_json_success($response);
                } else {
                    wp_send_json_error($response);
                }
                break;
                
            case 'create':
            default:
                // Standard table creation
                $success = $this->database->create_table();
                
                if ($success) {
                    delete_option('cel_activation_error');
                    delete_option('cel_connectivity_issues');
                    $this->database->clear_creation_failure_details();
                    
                    $response['message'] = 'Database tables created successfully!';
                    $response['table_status'] = $this->database->get_table_status();
                    wp_send_json_success($response);
                } else {
                    $failure_details = $this->database->get_creation_failure_details();
                    $response['message'] = 'Failed to create database tables.';
                    $response['failure_details'] = $failure_details;
                    $response['table_status'] = $this->database->get_table_status();
                    wp_send_json_error($response);
                }
                break;
        }
    }
    
    /**
     * Handle database connectivity test via AJAX
     */
    public function handle_database_test() {
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
        
        // Run database connectivity tests
        $tests = $this->database->test_database_connectivity();
        $table_status = $this->database->get_table_status();
        
        $all_passed = true;
        foreach ($tests as $test) {
            if (!$test['success']) {
                $all_passed = false;
                break;
            }
        }
        
        $response = array(
            'tests' => $tests,
            'table_status' => $table_status,
            'message' => $all_passed ? 'All database tests passed!' : 'Some tests failed - see details below'
        );
        
        wp_send_json_success($response);
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