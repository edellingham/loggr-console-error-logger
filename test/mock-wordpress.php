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
    private $pdo;
    private $queries = [];
    
    public function __construct() {
        try {
            // Use SQLite for easy testing
            $this->pdo = new PDO('sqlite:' . dirname(__DIR__) . '/test/test_database.db');
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->create_test_table();
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    
    private function create_test_table() {
        $sql = "CREATE TABLE IF NOT EXISTS wp_console_errors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            error_type VARCHAR(50) NOT NULL,
            error_message TEXT NOT NULL,
            error_source VARCHAR(255),
            error_line INTEGER,
            error_column INTEGER,
            stack_trace TEXT,
            user_agent TEXT,
            page_url VARCHAR(255),
            user_ip VARCHAR(45),
            user_id INTEGER DEFAULT NULL,
            session_id VARCHAR(255),
            is_login_page INTEGER DEFAULT 0,
            additional_data TEXT
        )";
        
        $this->pdo->exec($sql);
    }
    
    public function insert($table, $data, $format = null) {
        $columns = array_keys($data);
        $values = array_values($data);
        $placeholders = array_fill(0, count($columns), '?');
        
        $sql = "INSERT INTO $table (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute($values);
            $this->queries[] = $sql;
            return $result;
        } catch (PDOException $e) {
            $this->last_error = $e->getMessage();
            return false;
        }
    }
    
    public function get_results($query) {
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute();
            $this->queries[] = $query;
            return $stmt->fetchAll(PDO::FETCH_OBJ);
        } catch (PDOException $e) {
            $this->last_error = $e->getMessage();
            return false;
        }
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
        try {
            $stmt = $this->pdo->query($query);
            $result = $stmt->fetch(PDO::FETCH_NUM);
            return $result ? $result[0] : null;
        } catch (PDOException $e) {
            $this->last_error = $e->getMessage();
            return null;
        }
    }
    
    public function query($query) {
        try {
            $this->queries[] = $query;
            return $this->pdo->exec($query);
        } catch (PDOException $e) {
            $this->last_error = $e->getMessage();
            return false;
        }
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
    return filter_var($str, FILTER_SANITIZE_STRING);
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