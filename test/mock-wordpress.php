<?php
/**
 * Mock WordPress Environment for Testing
 * Simulates WordPress functions needed by the plugin
 */

// Define WordPress constants
define('ABSPATH', dirname(__DIR__) . '/');
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', true);

// Mock WordPress database class
class wpdb {
    public $prefix = 'wp_';
    public $last_error = '';
    private $errors = [];
    private $queries = [];
    private $data_file;
    
    public function __construct() {
        // Use file-based storage to persist between requests
        $this->data_file = __DIR__ . '/test_errors.json';
        $this->load_errors();
    }
    
    private function load_errors() {
        if (file_exists($this->data_file)) {
            $content = file_get_contents($this->data_file);
            $data = json_decode($content, true);
            if ($data && is_array($data)) {
                $this->errors = array_map(function($item) {
                    return (object)$item;
                }, $data);
            }
        } else {
            $this->errors = [];
        }
    }
    
    private function save_errors() {
        file_put_contents($this->data_file, json_encode($this->errors, JSON_PRETTY_PRINT));
    }
    
    public function insert($table, $data, $format = null) {
        // Generate unique ID
        $data['id'] = count($this->errors) + 1;
        $data['timestamp'] = date('Y-m-d H:i:s');
        
        // Store in memory and file
        $this->errors[] = (object)$data;
        $this->save_errors();
        $this->queries[] = "INSERT INTO $table";
        
        // Debug logging
        error_log("WPDB INSERT: " . json_encode($data));
        error_log("WPDB ERRORS COUNT: " . count($this->errors));
        
        return true;
    }
    
    public function get_results($query) {
        $this->queries[] = $query;
        
        // Debug logging
        error_log("WPDB GET_RESULTS: $query");
        error_log("WPDB CURRENT ERRORS: " . json_encode($this->errors));
        
        // Simple query parsing for testing
        if (strpos($query, 'SELECT * FROM wp_console_errors') !== false) {
            // Return all errors, apply basic ordering
            $results = $this->errors;
            if (strpos($query, 'ORDER BY timestamp DESC') !== false) {
                usort($results, function($a, $b) {
                    return strcmp($b->timestamp, $a->timestamp);
                });
            }
            
            // Apply LIMIT if present
            if (preg_match('/LIMIT (\d+)/', $query, $matches)) {
                $limit = (int)$matches[1];
                $results = array_slice($results, 0, $limit);
            }
            
            error_log("WPDB RETURNING: " . json_encode($results));
            return $results;
        }
        
        // Handle GROUP BY queries for statistics
        if (strpos($query, 'GROUP BY error_type') !== false) {
            $type_counts = [];
            foreach ($this->errors as $error) {
                $type = $error->error_type;
                if (!isset($type_counts[$type])) {
                    $type_counts[$type] = 0;
                }
                $type_counts[$type]++;
            }
            
            $results = [];
            foreach ($type_counts as $error_type => $count) {
                $results[] = (object)['error_type' => $error_type, 'count' => $count];
            }
            return $results;
        }
        
        return [];
    }
    
    public function prepare($query, ...$args) {
        // Simple sprintf-like replacement for testing
        return vsprintf(str_replace(['%s', '%d'], '?', $query), $args);
    }
    
    public function get_charset_collate() {
        return '';
    }
    
    public function esc_like($text) {
        return addslashes($text);
    }
    
    public function get_var($query) {
        $this->queries[] = $query;
        
        if (strpos($query, 'SELECT COUNT(*) FROM wp_console_errors') !== false) {
            return count($this->errors);
        }
        
        if (strpos($query, 'SELECT COUNT(DISTINCT session_id)') !== false) {
            $sessions = array_unique(array_map(function($e) {
                return $e->session_id ?? '';
            }, $this->errors));
            return count(array_filter($sessions));
        }
        
        return 0;
    }
    
    public function query($query) {
        $this->queries[] = $query;
        
        if (strpos($query, 'DELETE FROM wp_console_errors') !== false) {
            $this->errors = [];
            $this->save_errors();
            return true;
        }
        
        return true;
    }
    
    public function get_queries() {
        return $this->queries;
    }
}

// Create global wpdb instance
$GLOBALS['wpdb'] = new wpdb();

// Mock WordPress functions
function wp_verify_nonce($nonce, $action) {
    // For testing, always return true
    return true;
}

function wp_create_nonce($action) {
    return 'test_nonce_' . md5($action);
}

function wp_send_json_success($data = null) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'data' => $data
    ]);
    exit;
}

function wp_send_json_error($data = null) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'data' => $data
    ]);
    exit;
}

function sanitize_text_field($str) {
    return htmlspecialchars(strip_tags($str), ENT_QUOTES, 'UTF-8');
}

function wp_kses_post($content) {
    return strip_tags($content, '<a><br><strong><em><p><ul><li><ol>');
}

function esc_url_raw($url) {
    return filter_var($url, FILTER_SANITIZE_URL);
}

function esc_html($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function absint($value) {
    return abs(intval($value));
}

function current_time($type) {
    if ($type === 'mysql') {
        return date('Y-m-d H:i:s');
    }
    return time();
}

function get_current_user_id() {
    return 1; // Mock user ID
}

function wp_json_encode($data, $options = 0, $depth = 512) {
    return json_encode($data, $options, $depth);
}

function wp_parse_args($args, $defaults) {
    if (is_object($args)) {
        $args = get_object_vars($args);
    } elseif (!is_array($args)) {
        $args = [];
    }
    return array_merge($defaults, $args);
}

function wp_unslash($value) {
    return is_array($value) ? array_map('stripslashes', $value) : stripslashes($value);
}

function get_option($option, $default = false) {
    // Mock options
    $options = [
        'cel_cleanup_days' => 30,
        'cel_cleanup_enabled' => true
    ];
    return isset($options[$option]) ? $options[$option] : $default;
}

function update_option($option, $value) {
    return true;
}

function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    // Mock action system
    return true;
}

function apply_filters($hook, $value, ...$args) {
    return $value;
}

function do_action($hook, ...$args) {
    return true;
}

function __($text, $domain = 'default') {
    return $text;
}

function dbDelta($queries) {
    global $wpdb;
    if (is_string($queries)) {
        $queries = [$queries];
    }
    foreach ($queries as $query) {
        $wpdb->query($query);
    }
    return [];
}

// Mock is_login function
function is_login() {
    return isset($_GET['is_login']) && $_GET['is_login'] === '1';
}

// Load plugin classes
require_once dirname(__DIR__) . '/includes/class-database.php';
require_once dirname(__DIR__) . '/includes/class-error-logger.php';
require_once dirname(__DIR__) . '/includes/class-admin.php';