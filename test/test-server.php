<?php
/**
 * Test Server for Console Error Logger
 * Run with: php -S localhost:8080 test-server.php
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load mock WordPress environment
require_once __DIR__ . '/mock-wordpress.php';

// Handle different request types
$request_uri = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];

// Set CORS headers for testing
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($method === 'OPTIONS') {
    exit(0);
}

// Route requests
if ($request_uri === '/' || $request_uri === '/test') {
    // Serve the enhanced test interface
    include __DIR__ . '/test-interface.html';
    exit;
}

if ($request_uri === '/wp-admin/admin-ajax.php' || $request_uri === '/ajax') {
    // Handle AJAX requests
    if (isset($_POST['action']) && $_POST['action'] === 'cel_log_error') {
        $error_logger = new CEL_Error_Logger();
        $error_logger->handle_ajax_log_error();
    } else {
        wp_send_json_error(['message' => 'Invalid action']);
    }
    exit;
}

if ($request_uri === '/api/errors' && $method === 'GET') {
    // Get all logged errors
    global $wpdb;
    $errors = $wpdb->get_results("SELECT * FROM wp_console_errors ORDER BY timestamp DESC LIMIT 100");
    header('Content-Type: application/json');
    echo json_encode($errors);
    exit;
}

if ($request_uri === '/api/clear' && $method === 'POST') {
    // Clear all errors
    global $wpdb;
    $wpdb->query("DELETE FROM wp_console_errors");
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'All errors cleared']);
    exit;
}

if ($request_uri === '/api/stats' && $method === 'GET') {
    // Get error statistics
    global $wpdb;
    $stats = [
        'total' => $wpdb->get_var("SELECT COUNT(*) FROM wp_console_errors"),
        'by_type' => $wpdb->get_results("SELECT error_type, COUNT(*) as count FROM wp_console_errors GROUP BY error_type"),
        'recent' => $wpdb->get_var("SELECT COUNT(*) FROM wp_console_errors WHERE timestamp > datetime('now', '-1 hour')"),
        'unique_sessions' => $wpdb->get_var("SELECT COUNT(DISTINCT session_id) FROM wp_console_errors")
    ];
    header('Content-Type: application/json');
    echo json_encode($stats);
    exit;
}

if (strpos($request_uri, '/assets/') === 0) {
    // Serve plugin assets
    $file_path = dirname(__DIR__) . $request_uri;
    if (file_exists($file_path)) {
        $mime_types = [
            'js' => 'application/javascript',
            'css' => 'text/css',
            'html' => 'text/html'
        ];
        $ext = pathinfo($file_path, PATHINFO_EXTENSION);
        $mime_type = isset($mime_types[$ext]) ? $mime_types[$ext] : 'application/octet-stream';
        
        header('Content-Type: ' . $mime_type);
        readfile($file_path);
    } else {
        http_response_code(404);
        echo "File not found: $request_uri";
    }
    exit;
}

// Default 404
http_response_code(404);
echo "Not found: $request_uri";