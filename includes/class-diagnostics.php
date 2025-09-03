<?php
/**
 * Diagnostics and Debug Tools for Console Error Logger
 */

if (!defined('ABSPATH')) {
    exit;
}

class CEL_Diagnostics {
    
    private $database;
    
    public function __construct() {
        $this->database = new CEL_Database();
        
        // Add debug menu item
        add_action('admin_menu', array($this, 'add_debug_menu'), 20);
        
        // Add AJAX handlers for diagnostics
        add_action('wp_ajax_cel_run_diagnostic', array($this, 'handle_diagnostic_ajax'));
        add_action('wp_ajax_cel_test_pipeline', array($this, 'handle_test_pipeline'));
        add_action('wp_ajax_cel_get_debug_info', array($this, 'handle_get_debug_info'));
    }
    
    /**
     * Add debug submenu under Tools > Console Errors
     */
    public function add_debug_menu() {
        add_submenu_page(
            'console-error-logger',
            __('Error Logger Diagnostics', 'console-error-logger'),
            __('🔧 Diagnostics', 'console-error-logger'),
            'manage_options',
            'cel-diagnostics',
            array($this, 'render_diagnostics_page')
        );
    }
    
    /**
     * Render diagnostics page
     */
    public function render_diagnostics_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Console Error Logger - Diagnostics', 'console-error-logger'); ?></h1>
            
            <div class="cel-diagnostics-container">
                <!-- System Status -->
                <div class="cel-diagnostic-panel">
                    <h2>🔍 System Status</h2>
                    <div id="cel-system-status">
                        <p>Loading diagnostics...</p>
                    </div>
                </div>
                
                <!-- Database Check -->
                <div class="cel-diagnostic-panel">
                    <h2>💾 Database Status</h2>
                    <div id="cel-database-status">
                        <p>Checking database...</p>
                    </div>
                </div>
                
                <!-- JavaScript Status -->
                <div class="cel-diagnostic-panel">
                    <h2>📜 JavaScript Status</h2>
                    <div id="cel-js-status">
                        <p>Checking JavaScript...</p>
                    </div>
                </div>
                
                <!-- Pipeline Test -->
                <div class="cel-diagnostic-panel">
                    <h2>🔄 Pipeline Test</h2>
                    <button id="cel-test-pipeline" class="button button-primary">Test Error Pipeline</button>
                    <div id="cel-pipeline-results" style="margin-top: 20px;"></div>
                </div>
                
                <!-- Live Debug Log -->
                <div class="cel-diagnostic-panel">
                    <h2>📊 Live Debug Log</h2>
                    <div id="cel-debug-log" style="background: #1a202c; color: #e2e8f0; padding: 15px; border-radius: 5px; height: 400px; overflow-y: auto; font-family: monospace; font-size: 12px;">
                        <p>Waiting for debug events...</p>
                    </div>
                </div>
                
                <!-- Configuration Dump -->
                <div class="cel-diagnostic-panel">
                    <h2>⚙️ Configuration</h2>
                    <pre id="cel-config-dump" style="background: #f3f4f6; padding: 15px; border-radius: 5px; overflow-x: auto;">
                        <?php $this->display_configuration(); ?>
                    </pre>
                </div>
            </div>
        </div>
        
        <style>
            .cel-diagnostics-container {
                display: grid;
                gap: 20px;
                margin-top: 20px;
            }
            
            .cel-diagnostic-panel {
                background: white;
                padding: 20px;
                border: 1px solid #ddd;
                border-radius: 5px;
            }
            
            .cel-diagnostic-panel h2 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 2px solid #e5e7eb;
            }
            
            .status-good {
                color: #10b981;
                font-weight: bold;
            }
            
            .status-bad {
                color: #ef4444;
                font-weight: bold;
            }
            
            .status-warning {
                color: #f59e0b;
                font-weight: bold;
            }
            
            .debug-entry {
                margin-bottom: 5px;
                padding: 5px;
                border-left: 3px solid #667eea;
            }
            
            .debug-entry.error {
                border-left-color: #ef4444;
                background: rgba(239, 68, 68, 0.1);
            }
            
            .debug-entry.success {
                border-left-color: #10b981;
                background: rgba(16, 185, 129, 0.1);
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            let debugLog = [];
            
            // Run initial diagnostics
            runDiagnostics();
            
            // Test pipeline button
            $('#cel-test-pipeline').on('click', function() {
                testPipeline();
            });
            
            function runDiagnostics() {
                // Get system status
                $.post(ajaxurl, {
                    action: 'cel_run_diagnostic',
                    nonce: '<?php echo wp_create_nonce('cel_diagnostic_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        updateDiagnosticDisplay(response.data);
                    }
                });
                
                // Check JavaScript status
                checkJavaScriptStatus();
            }
            
            function updateDiagnosticDisplay(data) {
                // System status
                let systemHtml = '<ul>';
                systemHtml += '<li>Plugin Version: <span class="status-good">' + data.plugin_version + '</span></li>';
                systemHtml += '<li>PHP Version: <span class="' + (data.php_ok ? 'status-good' : 'status-bad') + '">' + data.php_version + '</span></li>';
                systemHtml += '<li>WordPress Version: <span class="status-good">' + data.wp_version + '</span></li>';
                systemHtml += '<li>AJAX URL: <span class="status-good">' + data.ajax_url + '</span></li>';
                systemHtml += '<li>Nonce Valid: <span class="' + (data.nonce_valid ? 'status-good' : 'status-bad') + '">' + (data.nonce_valid ? 'Yes' : 'No') + '</span></li>';
                systemHtml += '</ul>';
                $('#cel-system-status').html(systemHtml);
                
                // Database status
                let dbHtml = '<ul>';
                dbHtml += '<li>Table Exists: <span class="' + (data.table_exists ? 'status-good' : 'status-bad') + '">' + (data.table_exists ? 'Yes' : 'No') + '</span></li>';
                dbHtml += '<li>Table Name: <code>' + data.table_name + '</code></li>';
                dbHtml += '<li>Error Count: <span class="status-good">' + data.error_count + '</span></li>';
                dbHtml += '<li>Last Error: ' + (data.last_error || 'None') + '</li>';
                dbHtml += '<li>Can Write: <span class="' + (data.can_write ? 'status-good' : 'status-bad') + '">' + (data.can_write ? 'Yes' : 'No') + '</span></li>';
                dbHtml += '</ul>';
                $('#cel-database-status').html(dbHtml);
            }
            
            function checkJavaScriptStatus() {
                let jsHtml = '<ul>';
                
                // Check if CEL is loaded
                if (typeof window.CEL !== 'undefined') {
                    jsHtml += '<li>CEL Object: <span class="status-good">Loaded</span></li>';
                } else {
                    jsHtml += '<li>CEL Object: <span class="status-bad">Not Found</span></li>';
                }
                
                // Check if cel_ajax is configured
                if (typeof window.cel_ajax !== 'undefined') {
                    jsHtml += '<li>AJAX Config: <span class="status-good">Found</span></li>';
                    jsHtml += '<li>AJAX URL: <code>' + window.cel_ajax.ajax_url + '</code></li>';
                    jsHtml += '<li>Nonce: <code>' + window.cel_ajax.nonce.substring(0, 10) + '...</code></li>';
                } else {
                    jsHtml += '<li>AJAX Config: <span class="status-bad">Missing</span></li>';
                }
                
                // Check jQuery
                if (typeof jQuery !== 'undefined') {
                    jsHtml += '<li>jQuery: <span class="status-good">v' + jQuery.fn.jquery + '</span></li>';
                } else {
                    jsHtml += '<li>jQuery: <span class="status-bad">Not Loaded</span></li>';
                }
                
                jsHtml += '</ul>';
                $('#cel-js-status').html(jsHtml);
            }
            
            function testPipeline() {
                $('#cel-pipeline-results').html('<p>Testing error pipeline...</p>');
                
                // Create test error
                const testError = {
                    error_type: 'diagnostic_test',
                    error_message: 'Pipeline test at ' + new Date().toISOString(),
                    error_source: 'diagnostics.php',
                    error_line: 123,
                    test_id: 'test_' + Date.now()
                };
                
                addDebugEntry('Sending test error: ' + testError.error_message, 'info');
                
                // Send test error through pipeline
                $.post(ajaxurl, {
                    action: 'cel_test_pipeline',
                    nonce: '<?php echo wp_create_nonce('cel_diagnostic_nonce'); ?>',
                    test_data: JSON.stringify(testError)
                }, function(response) {
                    let html = '<div class="' + (response.success ? 'status-good' : 'status-bad') + '">';
                    
                    if (response.success) {
                        html += '<h3>✅ Pipeline Test Successful</h3>';
                        html += '<ul>';
                        html += '<li>Test ID: ' + response.data.test_id + '</li>';
                        html += '<li>Received: ' + (response.data.received ? 'Yes' : 'No') + '</li>';
                        html += '<li>Validated: ' + (response.data.validated ? 'Yes' : 'No') + '</li>';
                        html += '<li>Processed: ' + (response.data.processed ? 'Yes' : 'No') + '</li>';
                        html += '<li>Stored: ' + (response.data.stored ? 'Yes' : 'No') + '</li>';
                        html += '<li>Database ID: ' + (response.data.db_id || 'None') + '</li>';
                        html += '<li>Verification: ' + (response.data.verified ? 'Confirmed in database' : 'Not found in database') + '</li>';
                        html += '</ul>';
                        
                        addDebugEntry('Pipeline test successful: ' + response.data.test_id, 'success');
                    } else {
                        html += '<h3>❌ Pipeline Test Failed</h3>';
                        html += '<p>' + response.data.message + '</p>';
                        if (response.data.errors) {
                            html += '<ul>';
                            response.data.errors.forEach(function(error) {
                                html += '<li>' + error + '</li>';
                            });
                            html += '</ul>';
                        }
                        
                        addDebugEntry('Pipeline test failed: ' + response.data.message, 'error');
                    }
                    
                    html += '</div>';
                    $('#cel-pipeline-results').html(html);
                });
            }
            
            function addDebugEntry(message, type = 'info') {
                const timestamp = new Date().toLocaleTimeString();
                const entry = {
                    time: timestamp,
                    message: message,
                    type: type
                };
                
                debugLog.unshift(entry);
                if (debugLog.length > 100) {
                    debugLog.pop();
                }
                
                updateDebugDisplay();
            }
            
            function updateDebugDisplay() {
                let html = '';
                debugLog.forEach(function(entry) {
                    html += '<div class="debug-entry ' + entry.type + '">';
                    html += '<span style="color: #9ca3af;">' + entry.time + '</span> ';
                    html += entry.message;
                    html += '</div>';
                });
                
                $('#cel-debug-log').html(html || '<p>No debug entries yet...</p>');
            }
            
            // Auto-refresh debug info every 5 seconds
            setInterval(function() {
                $.post(ajaxurl, {
                    action: 'cel_get_debug_info',
                    nonce: '<?php echo wp_create_nonce('cel_diagnostic_nonce'); ?>'
                }, function(response) {
                    if (response.success && response.data.new_entries) {
                        response.data.new_entries.forEach(function(entry) {
                            addDebugEntry(entry.message, entry.type);
                        });
                    }
                });
            }, 5000);
        });
        </script>
        <?php
    }
    
    /**
     * Display configuration
     */
    private function display_configuration() {
        $settings = get_option('cel_settings', array());
        $config = array(
            'Plugin Settings' => $settings,
            'Constants' => array(
                'CEL_VERSION' => CEL_VERSION,
                'CEL_PLUGIN_URL' => CEL_PLUGIN_URL,
                'CEL_PLUGIN_DIR' => CEL_PLUGIN_DIR,
                'ABSPATH' => ABSPATH,
                'WP_DEBUG' => WP_DEBUG,
                'WP_DEBUG_LOG' => WP_DEBUG_LOG,
                'WP_DEBUG_DISPLAY' => WP_DEBUG_DISPLAY
            ),
            'Database' => array(
                'Table Name' => $this->database->get_table_name(),
                'DB Version' => get_option('cel_db_version', 'Not set'),
                'Charset' => DB_CHARSET,
                'Collate' => DB_COLLATE
            ),
            'Server' => array(
                'PHP Version' => PHP_VERSION,
                'MySQL Version' => $GLOBALS['wpdb']->db_version(),
                'Server Software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                'Memory Limit' => ini_get('memory_limit'),
                'Max Execution Time' => ini_get('max_execution_time'),
                'Upload Max Size' => ini_get('upload_max_filesize'),
                'Post Max Size' => ini_get('post_max_size')
            )
        );
        
        echo esc_html(json_encode($config, JSON_PRETTY_PRINT));
    }
    
    /**
     * Handle diagnostic AJAX request
     */
    public function handle_diagnostic_ajax() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'cel_diagnostic_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
            return;
        }
        
        global $wpdb;
        
        // Run diagnostics
        $table_name = $this->database->get_table_name();
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        $diagnostics = array(
            'plugin_version' => CEL_VERSION,
            'php_version' => PHP_VERSION,
            'php_ok' => version_compare(PHP_VERSION, '7.4', '>='),
            'wp_version' => get_bloginfo('version'),
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce_valid' => true,
            'table_exists' => $table_exists,
            'table_name' => $table_name,
            'error_count' => $table_exists ? $this->database->get_error_count() : 0,
            'last_error' => $table_exists ? $this->database->get_last_error_time() : null,
            'can_write' => $this->test_database_write()
        );
        
        wp_send_json_success($diagnostics);
    }
    
    /**
     * Test database write capability
     */
    private function test_database_write() {
        $test_data = array(
            'error_type' => 'diagnostic_test',
            'error_message' => 'Write test at ' . current_time('mysql')
        );
        
        $result = $this->database->insert_error($test_data);
        
        if ($result) {
            // Clean up test entry
            global $wpdb;
            $wpdb->delete(
                $this->database->get_table_name(),
                array('error_type' => 'diagnostic_test')
            );
        }
        
        return $result;
    }
    
    /**
     * Handle pipeline test
     */
    public function handle_test_pipeline() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'cel_diagnostic_nonce')) {
            wp_send_json_error(array('message' => 'Nonce verification failed'));
            return;
        }
        
        $test_data = json_decode(stripslashes($_POST['test_data']), true);
        
        $results = array(
            'test_id' => $test_data['test_id'] ?? 'unknown',
            'received' => true,
            'validated' => false,
            'processed' => false,
            'stored' => false,
            'db_id' => null,
            'verified' => false
        );
        
        // Validate
        if (!empty($test_data['error_type']) && !empty($test_data['error_message'])) {
            $results['validated'] = true;
            
            // Process
            $error_logger = new CEL_Error_Logger();
            $processed_data = $error_logger->process_error_data($test_data);
            
            if ($processed_data) {
                $results['processed'] = true;
                
                // Store
                $db_result = $this->database->insert_error($processed_data);
                
                if ($db_result) {
                    $results['stored'] = true;
                    
                    // Verify in database
                    global $wpdb;
                    $table_name = $this->database->get_table_name();
                    $found = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM $table_name WHERE error_message = %s ORDER BY id DESC LIMIT 1",
                        $test_data['error_message']
                    ));
                    
                    if ($found) {
                        $results['db_id'] = $found;
                        $results['verified'] = true;
                    }
                }
            }
        }
        
        if ($results['verified']) {
            wp_send_json_success($results);
        } else {
            $errors = array();
            if (!$results['validated']) $errors[] = 'Validation failed';
            if (!$results['processed']) $errors[] = 'Processing failed';
            if (!$results['stored']) $errors[] = 'Storage failed';
            if (!$results['verified']) $errors[] = 'Verification failed';
            
            wp_send_json_error(array(
                'message' => 'Pipeline test failed',
                'errors' => $errors,
                'results' => $results
            ));
        }
    }
    
    /**
     * Get debug info for live updates
     */
    public function handle_get_debug_info() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'cel_diagnostic_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
            return;
        }
        
        // Return any new debug entries (this could be enhanced to track actual events)
        wp_send_json_success(array(
            'new_entries' => array()
        ));
    }
    
    /**
     * Make process_error_data method accessible for testing
     */
    public function process_error_data($data) {
        $error_logger = new CEL_Error_Logger();
        return $this->call_private_method($error_logger, 'process_error_data', array($data));
    }
    
    /**
     * Call private method via reflection for testing
     */
    private function call_private_method($object, $method_name, $parameters = array()) {
        $reflection = new ReflectionClass(get_class($object));
        $method = $reflection->getMethod($method_name);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $parameters);
    }
}