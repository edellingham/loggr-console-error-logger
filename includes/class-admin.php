<?php
/**
 * Admin interface class for Console Error Logger
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class CEL_Admin {
    
    /**
     * Database handler
     */
    private $database;
    
    /**
     * Error logger handler
     */
    private $error_logger;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->database = new CEL_Database();
        $this->error_logger = new CEL_Error_Logger();
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'tools.php',
            __('Console Error Logger', 'console-error-logger'),
            __('Console Errors', 'console-error-logger'),
            'manage_options',
            'console-error-logger',
            array($this, 'render_admin_page')
        );
        
        // Add dashboard widget
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));
    }
    
    /**
     * Add dashboard widget
     */
    public function add_dashboard_widget() {
        wp_add_dashboard_widget(
            'cel_dashboard_widget',
            __('Console Errors Summary', 'console-error-logger'),
            array($this, 'render_dashboard_widget')
        );
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on our admin page
        if ($hook !== 'tools_page_console-error-logger' && $hook !== 'index.php') {
            return;
        }
        
        // Enqueue admin styles
        wp_enqueue_style(
            'cel-admin-styles',
            CEL_PLUGIN_URL . 'assets/css/admin-styles.css',
            array(),
            CEL_VERSION
        );
        
        // Enqueue admin JavaScript
        wp_enqueue_script(
            'cel-admin-script',
            CEL_PLUGIN_URL . 'assets/js/admin-script.js',
            array('jquery'),
            CEL_VERSION,
            true
        );
        
        // Localize script
        wp_localize_script('cel-admin-script', 'cel_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cel_admin_nonce'),
            'confirm_clear' => __('Are you sure you want to clear all error logs? This action cannot be undone.', 'console-error-logger'),
            'confirm_delete' => __('Are you sure you want to delete this error log?', 'console-error-logger')
        ));
    }
    
    /**
     * Render admin page
     */
    public function render_admin_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Get current tab
        $current_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'logs';
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php $this->render_admin_notices(); ?>
            
            <nav class="nav-tab-wrapper">
                <a href="?page=console-error-logger&tab=logs" 
                   class="nav-tab <?php echo $current_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Error Logs', 'console-error-logger'); ?>
                </a>
                <a href="?page=console-error-logger&tab=stats" 
                   class="nav-tab <?php echo $current_tab === 'stats' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Statistics', 'console-error-logger'); ?>
                </a>
                <a href="?page=console-error-logger&tab=ignore" 
                   class="nav-tab <?php echo $current_tab === 'ignore' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Ignore Patterns', 'console-error-logger'); ?>
                </a>
                <a href="?page=console-error-logger&tab=logins" 
                   class="nav-tab <?php echo $current_tab === 'logins' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Login Analytics', 'console-error-logger'); ?>
                </a>
                <a href="?page=console-error-logger&tab=settings" 
                   class="nav-tab <?php echo $current_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Settings', 'console-error-logger'); ?>
                </a>
                <a href="?page=console-error-logger&tab=diagnostics" 
                   class="nav-tab <?php echo $current_tab === 'diagnostics' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('🔧 Diagnostics', 'console-error-logger'); ?>
                </a>
            </nav>
            
            <div class="tab-content">
                <?php
                switch ($current_tab) {
                    case 'stats':
                        $this->render_stats_tab();
                        break;
                    case 'ignore':
                        $this->render_ignore_tab();
                        break;
                    case 'logins':
                        $this->render_logins_tab();
                        break;
                    case 'settings':
                        $this->render_settings_tab();
                        break;
                    case 'diagnostics':
                        $this->render_diagnostics_tab();
                        break;
                    default:
                        $this->render_logs_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render error logs tab
     */
    private function render_logs_tab() {
        // Get pagination parameters
        $per_page = 50;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Get filter parameters
        $filter_type = isset($_GET['error_type']) ? sanitize_text_field(wp_unslash($_GET['error_type'])) : '';
        $filter_login = isset($_GET['login_only']) ? true : false;
        $search = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';
        
        // Build query args
        $args = array(
            'limit' => $per_page,
            'offset' => $offset,
            'error_type' => $filter_type,
            'search' => $search
        );
        
        if ($filter_login) {
            $args['is_login_page'] = 1;
        }
        
        // Get errors
        $errors = $this->database->get_errors($args);
        $total_errors = $this->database->get_error_count($args);
        $total_pages = ceil($total_errors / $per_page);
        
        // Get error types for filter dropdown
        $error_types = $this->get_error_types();
        
        ?>
        <div class="cel-logs-wrapper">
            <div class="tablenav top">
                <div class="alignleft actions">
                    <form method="get" action="">
                        <input type="hidden" name="page" value="console-error-logger">
                        <input type="hidden" name="tab" value="logs">
                        
                        <select name="error_type">
                            <option value=""><?php _e('All Error Types', 'console-error-logger'); ?></option>
                            <?php foreach ($error_types as $type => $label): ?>
                                <option value="<?php echo esc_attr($type); ?>" 
                                        <?php selected($filter_type, $type); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <label>
                            <input type="checkbox" name="login_only" value="1" 
                                   <?php checked($filter_login); ?>>
                            <?php _e('Login Page Only', 'console-error-logger'); ?>
                        </label>
                        
                        <input type="search" name="search" 
                               value="<?php echo esc_attr($search); ?>" 
                               placeholder="<?php esc_attr_e('Search errors...', 'console-error-logger'); ?>">
                        
                        <input type="submit" class="button" 
                               value="<?php esc_attr_e('Filter', 'console-error-logger'); ?>">
                    </form>
                </div>
                
                <div class="alignright actions">
                    <button type="button" class="button button-primary" id="cel-clear-logs">
                        <?php _e('Clear All Logs', 'console-error-logger'); ?>
                    </button>
                </div>
                
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php printf(
                            _n('%s error', '%s errors', $total_errors, 'console-error-logger'),
                            number_format_i18n($total_errors)
                        ); ?>
                    </span>
                    
                    <?php if ($total_pages > 1): ?>
                        <?php
                        $pagination_args = array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'current' => $current_page,
                            'total' => $total_pages,
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;'
                        );
                        echo paginate_links($pagination_args);
                        ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if (empty($errors)): ?>
                <div class="notice notice-info">
                    <p><?php _e('No errors logged yet.', 'console-error-logger'); ?></p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col" class="column-timestamp">
                                <?php _e('Timestamp', 'console-error-logger'); ?>
                            </th>
                            <th scope="col" class="column-type">
                                <?php _e('Type', 'console-error-logger'); ?>
                            </th>
                            <th scope="col" class="column-message">
                                <?php _e('Error Message', 'console-error-logger'); ?>
                            </th>
                            <th scope="col" class="column-source">
                                <?php _e('Source', 'console-error-logger'); ?>
                            </th>
                            <th scope="col" class="column-page">
                                <?php _e('Page', 'console-error-logger'); ?>
                            </th>
                            <th scope="col" class="column-ip">
                                <?php _e('IP', 'console-error-logger'); ?>
                            </th>
                            <th scope="col" class="column-actions">
                                <?php _e('Actions', 'console-error-logger'); ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($errors as $error): ?>
                            <tr>
                                <td class="column-timestamp">
                                    <?php echo esc_html(
                                        wp_date(
                                            get_option('date_format') . ' ' . get_option('time_format'),
                                            strtotime($error->timestamp)
                                        )
                                    ); ?>
                                    <?php if ($error->is_login_page): ?>
                                        <span class="cel-badge cel-badge-login">Login</span>
                                    <?php endif; ?>
                                </td>
                                <td class="column-type">
                                    <span class="cel-error-type cel-error-type-<?php echo esc_attr($error->error_type); ?>">
                                        <?php echo esc_html($this->get_error_type_label($error->error_type)); ?>
                                    </span>
                                </td>
                                <td class="column-message">
                                    <div class="cel-error-message">
                                        <?php 
                                        $message = wp_kses_post($error->error_message);
                                        echo strlen($message) > 200 ? 
                                             substr($message, 0, 200) . '...' : 
                                             $message;
                                        ?>
                                    </div>
                                    <?php if ($error->error_line): ?>
                                        <span class="cel-error-location">
                                            Line <?php echo esc_html($error->error_line); ?>
                                            <?php if ($error->error_column): ?>
                                                : Column <?php echo esc_html($error->error_column); ?>
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="column-source">
                                    <?php if ($error->error_source): ?>
                                        <a href="<?php echo esc_url($error->error_source); ?>" 
                                           target="_blank" rel="noopener">
                                            <?php echo esc_html(basename($error->error_source)); ?>
                                        </a>
                                    <?php else: ?>
                                        <em><?php _e('Unknown', 'console-error-logger'); ?></em>
                                    <?php endif; ?>
                                </td>
                                <td class="column-page">
                                    <?php if ($error->page_url): ?>
                                        <a href="<?php echo esc_url($error->page_url); ?>" 
                                           target="_blank" rel="noopener">
                                            <?php 
                                            $path = parse_url($error->page_url, PHP_URL_PATH);
                                            echo esc_html($path ?: '/');
                                            ?>
                                        </a>
                                    <?php else: ?>
                                        <em><?php _e('Unknown', 'console-error-logger'); ?></em>
                                    <?php endif; ?>
                                </td>
                                <td class="column-ip">
                                    <?php echo esc_html($error->user_ip); ?>
                                </td>
                                <td class="column-actions">
                                    <button type="button" 
                                            class="button button-small cel-view-details" 
                                            data-error-id="<?php echo esc_attr($error->id); ?>">
                                        <?php _e('Details', 'console-error-logger'); ?>
                                    </button>
                                    <button type="button" 
                                            class="button button-small cel-ignore-error" 
                                            data-error-id="<?php echo esc_attr($error->id); ?>"
                                            data-error-message="<?php echo esc_attr($error->error_message); ?>"
                                            data-error-source="<?php echo esc_attr($error->error_source); ?>">
                                        <?php _e('Ignore', 'console-error-logger'); ?>
                                    </button>
                                </td>
                            </tr>
                            
                            <!-- Hidden details row -->
                            <tr class="cel-error-details" id="cel-error-<?php echo esc_attr($error->id); ?>" style="display: none;">
                                <td colspan="7">
                                    <div class="cel-details-content">
                                        <h4><?php _e('Full Error Details', 'console-error-logger'); ?></h4>
                                        
                                        <div class="cel-detail-item">
                                            <strong><?php _e('Full Message:', 'console-error-logger'); ?></strong>
                                            <pre><?php echo esc_html($error->error_message); ?></pre>
                                        </div>
                                        
                                        <?php if ($error->stack_trace): ?>
                                            <div class="cel-detail-item">
                                                <strong><?php _e('Stack Trace:', 'console-error-logger'); ?></strong>
                                                <pre><?php echo esc_html($error->stack_trace); ?></pre>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($error->user_agent): ?>
                                            <div class="cel-detail-item">
                                                <strong><?php _e('User Agent:', 'console-error-logger'); ?></strong>
                                                <code><?php echo esc_html($error->user_agent); ?></code>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($error->additional_data): ?>
                                            <div class="cel-detail-item">
                                                <strong><?php _e('Additional Data:', 'console-error-logger'); ?></strong>
                                                <pre><?php 
                                                    $additional = json_decode($error->additional_data, true);
                                                    echo esc_html(json_encode($additional, JSON_PRETTY_PRINT));
                                                ?></pre>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render statistics tab
     */
    private function render_stats_tab() {
        $stats = $this->database->get_error_stats();
        
        ?>
        <div class="cel-stats-wrapper">
            <div class="cel-stats-grid">
                <div class="cel-stat-box">
                    <h3><?php _e('Total Errors', 'console-error-logger'); ?></h3>
                    <div class="cel-stat-number">
                        <?php echo number_format_i18n($stats['total']); ?>
                    </div>
                </div>
                
                <div class="cel-stat-box">
                    <h3><?php _e('Last 24 Hours', 'console-error-logger'); ?></h3>
                    <div class="cel-stat-number">
                        <?php echo number_format_i18n($stats['recent_24h']); ?>
                    </div>
                </div>
                
                <div class="cel-stat-box">
                    <h3><?php _e('Login Page Errors', 'console-error-logger'); ?></h3>
                    <div class="cel-stat-number">
                        <?php echo number_format_i18n($stats['login_errors']); ?>
                    </div>
                </div>
            </div>
            
            <div class="cel-chart-section">
                <h3><?php _e('Errors by Type', 'console-error-logger'); ?></h3>
                <?php if (!empty($stats['by_type'])): ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('Error Type', 'console-error-logger'); ?></th>
                                <th><?php _e('Count', 'console-error-logger'); ?></th>
                                <th><?php _e('Percentage', 'console-error-logger'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['by_type'] as $type_stat): ?>
                                <tr>
                                    <td>
                                        <span class="cel-error-type cel-error-type-<?php echo esc_attr($type_stat->error_type); ?>">
                                            <?php echo esc_html($this->get_error_type_label($type_stat->error_type)); ?>
                                        </span>
                                    </td>
                                    <td><?php echo number_format_i18n($type_stat->count); ?></td>
                                    <td>
                                        <?php 
                                        $percentage = $stats['total'] > 0 ? 
                                                     ($type_stat->count / $stats['total']) * 100 : 0;
                                        echo number_format_i18n($percentage, 1) . '%';
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p><?php _e('No error statistics available yet.', 'console-error-logger'); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render ignore patterns tab
     */
    private function render_ignore_tab() {
        $patterns = $this->database->get_ignore_patterns();
        
        ?>
        <div class="cel-ignore-wrapper">
            <h2><?php _e('Ignore Patterns', 'console-error-logger'); ?></h2>
            <p><?php _e('Manage patterns to ignore specific errors automatically.', 'console-error-logger'); ?></p>
            
            <?php if (!empty($patterns)): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Type', 'console-error-logger'); ?></th>
                            <th><?php _e('Pattern', 'console-error-logger'); ?></th>
                            <th><?php _e('Created', 'console-error-logger'); ?></th>
                            <th><?php _e('Ignored Count', 'console-error-logger'); ?></th>
                            <th><?php _e('Status', 'console-error-logger'); ?></th>
                            <th><?php _e('Actions', 'console-error-logger'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($patterns as $pattern): ?>
                            <tr>
                                <td><?php echo esc_html($pattern->pattern_type); ?></td>
                                <td>
                                    <code style="font-size: 12px; word-break: break-all;">
                                        <?php 
                                        $value = $pattern->pattern_value;
                                        echo esc_html(strlen($value) > 100 ? substr($value, 0, 100) . '...' : $value);
                                        ?>
                                    </code>
                                    <?php if ($pattern->notes): ?>
                                        <div style="font-size: 11px; color: #666; margin-top: 5px;">
                                            <?php echo esc_html($pattern->notes); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo esc_html(human_time_diff(strtotime($pattern->created_at))); ?> ago
                                    <br><small>by <?php echo esc_html($pattern->created_by_name ?: 'Unknown'); ?></small>
                                </td>
                                <td>
                                    <strong><?php echo number_format_i18n($pattern->ignore_count); ?></strong>
                                    <?php if ($pattern->last_ignored): ?>
                                        <br><small>Last: <?php echo esc_html(human_time_diff(strtotime($pattern->last_ignored))); ?> ago</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="cel-status <?php echo $pattern->is_active ? 'active' : 'inactive'; ?>">
                                        <?php echo $pattern->is_active ? __('Active', 'console-error-logger') : __('Inactive', 'console-error-logger'); ?>
                                    </span>
                                </td>
                                <td>
                                    <button type="button" 
                                            class="button button-small cel-toggle-pattern" 
                                            data-pattern-id="<?php echo esc_attr($pattern->id); ?>">
                                        <?php echo $pattern->is_active ? __('Disable', 'console-error-logger') : __('Enable', 'console-error-logger'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="notice notice-info">
                    <p><?php _e('No ignore patterns configured yet. Use the "Ignore" button on error logs to create patterns.', 'console-error-logger'); ?></p>
                </div>
            <?php endif; ?>
        </div>
        
        <style>
            .cel-status.active {
                color: #10b981;
                font-weight: bold;
            }
            .cel-status.inactive {
                color: #ef4444;
                font-weight: bold;
            }
        </style>
        <?php
    }
    
    /**
     * Render login analytics tab
     */
    private function render_logins_tab() {
        // Get pagination parameters
        $per_page = 50;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Get filter parameters
        $filter_user = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0;
        $filter_ip = isset($_GET['ip_address']) ? sanitize_text_field(wp_unslash($_GET['ip_address'])) : '';
        
        // Build query args
        $args = array(
            'limit' => $per_page,
            'offset' => $offset
        );
        
        if ($filter_user) {
            $args['user_id'] = $filter_user;
        }
        
        if ($filter_ip) {
            $args['ip_address'] = $filter_ip;
        }
        
        // Get login data
        $logins = $this->database->get_login_history($args);
        
        ?>
        <div class="cel-logins-wrapper">
            <h2><?php _e('Login Analytics', 'console-error-logger'); ?></h2>
            <p><?php _e('Track all user logins and associated error patterns.', 'console-error-logger'); ?></p>
            
            <div class="tablenav top">
                <div class="alignleft actions">
                    <form method="get" action="">
                        <input type="hidden" name="page" value="console-error-logger">
                        <input type="hidden" name="tab" value="logins">
                        
                        <input type="number" name="user_id" 
                               value="<?php echo esc_attr($filter_user); ?>" 
                               placeholder="User ID"
                               min="1">
                        
                        <input type="text" name="ip_address" 
                               value="<?php echo esc_attr($filter_ip); ?>" 
                               placeholder="IP Address">
                        
                        <input type="submit" class="button" 
                               value="<?php esc_attr_e('Filter', 'console-error-logger'); ?>">
                    </form>
                </div>
            </div>
            
            <?php if (empty($logins)): ?>
                <div class="notice notice-info">
                    <p><?php _e('No login records yet.', 'console-error-logger'); ?></p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Login Time', 'console-error-logger'); ?></th>
                            <th><?php _e('User', 'console-error-logger'); ?></th>
                            <th><?php _e('IP Address', 'console-error-logger'); ?></th>
                            <th><?php _e('Errors Before', 'console-error-logger'); ?></th>
                            <th><?php _e('Page URL', 'console-error-logger'); ?></th>
                            <th><?php _e('User Agent', 'console-error-logger'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logins as $login): ?>
                            <tr>
                                <td>
                                    <?php echo esc_html(
                                        wp_date(
                                            get_option('date_format') . ' ' . get_option('time_format'),
                                            strtotime($login->login_time)
                                        )
                                    ); ?>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($login->user_login); ?></strong>
                                    <br><small><?php echo esc_html($login->user_email); ?></small>
                                    <?php if ($login->user_roles): ?>
                                        <br><em style="font-size: 11px; color: #666;">
                                            <?php 
                                            $roles = json_decode($login->user_roles, true);
                                            echo esc_html(implode(', ', $roles ?: array()));
                                            ?>
                                        </em>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code><?php echo esc_html($login->ip_address); ?></code>
                                </td>
                                <td>
                                    <?php if ($login->login_errors_before > 0): ?>
                                        <span style="color: #ef4444; font-weight: bold;">
                                            <?php echo esc_html($login->login_errors_before); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #10b981;">0</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($login->page_url): ?>
                                        <a href="<?php echo esc_url($login->page_url); ?>" target="_blank" rel="noopener">
                                            <?php 
                                            $path = parse_url($login->page_url, PHP_URL_PATH);
                                            echo esc_html($path ?: '/');
                                            ?>
                                        </a>
                                    <?php else: ?>
                                        <em><?php _e('Unknown', 'console-error-logger'); ?></em>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code style="font-size: 11px; word-break: break-all;">
                                        <?php 
                                        $ua = $login->user_agent;
                                        echo esc_html(strlen($ua) > 60 ? substr($ua, 0, 60) . '...' : $ua);
                                        ?>
                                    </code>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render settings tab
     */
    private function render_settings_tab() {
        // Handle form submission
        if (isset($_POST['cel_save_settings']) && isset($_POST['cel_settings_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cel_settings_nonce'])), 'cel_save_settings')) {
            $this->save_settings();
        }
        
        $settings = get_option('cel_settings', array());
        
        ?>
        <div class="cel-settings-wrapper">
            <form method="post" action="">
                <?php wp_nonce_field('cel_save_settings', 'cel_settings_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="enable_login_monitoring">
                                <?php _e('Enable Login Page Monitoring', 'console-error-logger'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="checkbox" id="enable_login_monitoring" 
                                   name="cel_settings[enable_login_monitoring]" value="1"
                                   <?php checked(!empty($settings['enable_login_monitoring'])); ?>>
                            <p class="description">
                                <?php _e('Monitor JavaScript errors on the WordPress login page.', 'console-error-logger'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="enable_site_monitoring">
                                <?php _e('Enable Site-wide Monitoring', 'console-error-logger'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="checkbox" id="enable_site_monitoring" 
                                   name="cel_settings[enable_site_monitoring]" value="1"
                                   <?php checked(!empty($settings['enable_site_monitoring'])); ?>>
                            <p class="description">
                                <?php _e('Monitor JavaScript errors on all frontend pages (may impact performance).', 'console-error-logger'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="login_timeout_seconds">
                                <?php _e('Login Timeout Detection (seconds)', 'console-error-logger'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="number" id="login_timeout_seconds" 
                                   name="cel_settings[login_timeout_seconds]" 
                                   value="<?php echo esc_attr($settings['login_timeout_seconds'] ?? 10); ?>"
                                   min="5" max="60">
                            <p class="description">
                                <?php _e('Time to wait before considering a login attempt as "stuck".', 'console-error-logger'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="max_log_entries">
                                <?php _e('Maximum Log Entries', 'console-error-logger'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="number" id="max_log_entries" 
                                   name="cel_settings[max_log_entries]" 
                                   value="<?php echo esc_attr($settings['max_log_entries'] ?? 1000); ?>"
                                   min="100" max="10000">
                            <p class="description">
                                <?php _e('Maximum number of error logs to keep in the database.', 'console-error-logger'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="auto_cleanup_days">
                                <?php _e('Auto-cleanup After (days)', 'console-error-logger'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="number" id="auto_cleanup_days" 
                                   name="cel_settings[auto_cleanup_days]" 
                                   value="<?php echo esc_attr($settings['auto_cleanup_days'] ?? 30); ?>"
                                   min="0" max="365">
                            <p class="description">
                                <?php _e('Automatically delete logs older than this many days. Set to 0 to disable.', 'console-error-logger'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="cel_save_settings" 
                           class="button button-primary" 
                           value="<?php esc_attr_e('Save Settings', 'console-error-logger'); ?>">
                </p>
            </form>
        </div>
        <?php
    }
    
    /**
     * Save settings
     */
    private function save_settings() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $post_settings = isset($_POST['cel_settings']) ? wp_unslash($_POST['cel_settings']) : array();
        
        $settings = array(
            'enable_login_monitoring' => !empty($post_settings['enable_login_monitoring']),
            'enable_site_monitoring' => !empty($post_settings['enable_site_monitoring']),
            'login_timeout_seconds' => isset($post_settings['login_timeout_seconds']) ? absint($post_settings['login_timeout_seconds']) : 10,
            'max_log_entries' => isset($post_settings['max_log_entries']) ? absint($post_settings['max_log_entries']) : 1000,
            'auto_cleanup_days' => isset($post_settings['auto_cleanup_days']) ? absint($post_settings['auto_cleanup_days']) : 30
        );
        
        // Validate values
        $settings['login_timeout_seconds'] = max(5, min(60, $settings['login_timeout_seconds']));
        $settings['max_log_entries'] = max(100, min(10000, $settings['max_log_entries']));
        $settings['auto_cleanup_days'] = max(0, min(365, $settings['auto_cleanup_days']));
        
        update_option('cel_settings', $settings);
        
        add_settings_error(
            'cel_settings',
            'cel_settings_saved',
            __('Settings saved successfully.', 'console-error-logger'),
            'success'
        );
    }
    
    /**
     * Render dashboard widget
     */
    public function render_dashboard_widget() {
        $stats = $this->error_logger->get_dashboard_stats();
        $recent_errors = $this->error_logger->get_recent_errors(5);
        
        ?>
        <div class="cel-dashboard-widget">
            <div class="cel-widget-stats">
                <span class="cel-stat">
                    <strong><?php echo number_format_i18n($stats['total']); ?></strong> 
                    <?php _e('Total Errors', 'console-error-logger'); ?>
                </span>
                <span class="cel-stat">
                    <strong><?php echo number_format_i18n($stats['recent_24h']); ?></strong> 
                    <?php _e('Last 24h', 'console-error-logger'); ?>
                </span>
                <span class="cel-stat">
                    <strong><?php echo number_format_i18n($stats['login_errors']); ?></strong> 
                    <?php _e('Login Errors', 'console-error-logger'); ?>
                </span>
            </div>
            
            <?php if (!empty($recent_errors)): ?>
                <h4><?php _e('Recent Errors', 'console-error-logger'); ?></h4>
                <ul class="cel-recent-errors">
                    <?php foreach ($recent_errors as $error): ?>
                        <li>
                            <span class="cel-error-type cel-error-type-<?php echo esc_attr($error->error_type); ?>">
                                <?php echo esc_html($this->get_error_type_label($error->error_type)); ?>
                            </span>
                            <span class="cel-error-time">
                                <?php echo human_time_diff(strtotime($error->timestamp), current_time('timestamp')); ?> ago
                            </span>
                            <div class="cel-error-preview">
                                <?php 
                                $message = wp_kses_post($error->error_message);
                                echo strlen($message) > 100 ? substr($message, 0, 100) . '...' : $message;
                                ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p><?php _e('No errors logged yet.', 'console-error-logger'); ?></p>
            <?php endif; ?>
            
            <p class="cel-widget-footer">
                <a href="<?php echo admin_url('tools.php?page=console-error-logger'); ?>" class="button">
                    <?php _e('View All Errors', 'console-error-logger'); ?>
                </a>
            </p>
        </div>
        <?php
    }
    
    /**
     * Handle clear logs AJAX request
     */
    public function handle_clear_logs() {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'cel_admin_nonce')) {
            wp_send_json_error(array('message' => __('Invalid security token', 'console-error-logger')));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'console-error-logger')));
            return;
        }
        
        // Clear logs
        $result = $this->database->clear_all_logs();
        
        if ($result !== false) {
            wp_send_json_success(array('message' => __('All error logs have been cleared.', 'console-error-logger')));
        } else {
            wp_send_json_error(array('message' => __('Failed to clear error logs.', 'console-error-logger')));
        }
    }
    
    /**
     * Get error type labels
     */
    private function get_error_types() {
        return array(
            'javascript_error' => __('JavaScript Error', 'console-error-logger'),
            'console_error' => __('Console Error', 'console-error-logger'),
            'console_warning' => __('Console Warning', 'console-error-logger'),
            'ajax_error' => __('AJAX Error', 'console-error-logger'),
            'fetch_error' => __('Fetch Error', 'console-error-logger'),
            'resource_error' => __('Resource Error', 'console-error-logger'),
            'unhandled_rejection' => __('Unhandled Promise', 'console-error-logger'),
            'login_timeout' => __('Login Timeout', 'console-error-logger')
        );
    }
    
    /**
     * Get error type label
     */
    private function get_error_type_label($type) {
        $types = $this->get_error_types();
        return isset($types[$type]) ? $types[$type] : ucfirst(str_replace('_', ' ', $type));
    }
    
    /**
     * Render admin notices
     */
    private function render_admin_notices() {
        settings_errors('cel_settings');
    }
    
    /**
     * Render diagnostics tab
     */
    private function render_diagnostics_tab() {
        // Get diagnostics instance
        $diagnostics = new CEL_Diagnostics();
        $diagnostics->render_diagnostics_content();
    }
}