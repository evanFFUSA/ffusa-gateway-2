<?php
/**
 * Plugin Name: NMI Payment Gateway
 * Plugin URI: https://yoursite.com
 * Description: A basic NMI payment gateway integration for WordPress
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('NMI_PAYMENT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('NMI_PAYMENT_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('NMI_PAYMENT_VERSION', '1.0.0');

class NMI_Payment_Gateway {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_shortcode('nmi_payment_form', array($this, 'payment_form_shortcode'));
        add_action('wp_ajax_process_nmi_payment', array($this, 'process_payment'));
        add_action('wp_ajax_nopriv_process_nmi_payment', array($this, 'process_payment'));
        add_action('wp_ajax_get_transaction_details', array($this, 'get_transaction_details'));
        add_action('admin_menu', array($this, 'admin_menu'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function init() {
        // Create database table for transactions
        $this->create_transactions_table();
    }
    
    public function enqueue_scripts() {
        wp_enqueue_script('jquery');
        wp_enqueue_script('nmi-payment-js', NMI_PAYMENT_PLUGIN_URL . 'assets/js/nmi-payment.js', array('jquery'), NMI_PAYMENT_VERSION, true);
        wp_enqueue_style('nmi-payment-css', NMI_PAYMENT_PLUGIN_URL . 'assets/css/nmi-payment.css', array(), NMI_PAYMENT_VERSION);
        
        // Localize script for AJAX
        wp_localize_script('nmi-payment-js', 'nmi_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('nmi_payment_nonce')
        ));
    }
    
    public function payment_form_shortcode($atts) {
        // Get admin settings for defaults
        $settings = get_option('nmi_payment_settings', array());
        $default_button_text = isset($settings['default_button_text']) ? $settings['default_button_text'] : 'Pay Now';
        $default_description = isset($settings['default_description']) ? $settings['default_description'] : 'Payment';
        
        $atts = shortcode_atts(array(
            'amount' => '',
            'description' => $default_description,
            'button_text' => $default_button_text
        ), $atts);
        
        ob_start();
        ?>
        <div class="nmi-payment-form-container">
            <!-- Step 1: Amount and Description Selection -->
            <div id="step-1" class="payment-step active">
                <h3>Select Your Contribution</h3>
                
                <div class="form-group">
                    <label>Select Amount</label>
                    <div class="amount-buttons">
                        <button type="button" class="amount-btn" data-amount="10">$10</button>
                        <button type="button" class="amount-btn" data-amount="25">$25</button>
                        <button type="button" class="amount-btn" data-amount="50">$50</button>
                        <button type="button" class="amount-btn" data-amount="100">$100</button>
                    </div>
                    <div class="other-amount-container">
                        <button type="button" id="other-amount-btn" class="other-amount-toggle">Other Amount</button>
                        <div id="custom-amount-input" class="custom-amount-input" style="display: none;">
                            <label for="step1_amount">Enter Amount ($)</label>
                            <input type="number" id="step1_amount" step="0.01" min="0.01" 
                                   value="<?php echo esc_attr($atts['amount']); ?>" 
                                   <?php echo !empty($atts['amount']) ? 'readonly' : ''; ?>>
                        </div>
                    </div>
                    <input type="hidden" id="selected_amount" name="selected_amount">
                </div>
                
                <div class="form-group">
                    <label for="step1_description">Description</label>
                    <input type="text" id="step1_description" 
                           value="<?php echo esc_attr($atts['description']); ?>" 
                           placeholder="What is this payment for?">
                </div>
                
                <div class="form-group">
                    <button type="button" id="give-button" class="nmi-give-button">
                        Give
                    </button>
                </div>
                
                <div id="step1-messages"></div>
            </div>
            
            <!-- Step 2: Payment Details Form -->
            <div id="step-2" class="payment-step" style="display: none;">
                <div class="payment-summary">
                    <h3>Payment Details</h3>
                    <div class="summary-info">
                        <span class="summary-description"></span>
                        <span class="summary-amount"></span>
                    </div>
                </div>
                
                <form id="nmi-payment-form" class="nmi-payment-form">
                    <?php wp_nonce_field('nmi_payment_nonce', 'nmi_nonce'); ?>
                    
                    <!-- Hidden fields to store step 1 data -->
                    <input type="hidden" id="final_amount" name="amount">
                    <input type="hidden" id="final_description" name="description">
                    
                    <div class="form-group">
                        <label for="nmi_first_name">First Name</label>
                        <input type="text" id="nmi_first_name" name="first_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="nmi_last_name">Last Name</label>
                        <input type="text" id="nmi_last_name" name="last_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="nmi_email">Email</label>
                        <input type="email" id="nmi_email" name="email" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="nmi_card_number">Card Number</label>
                        <input type="text" id="nmi_card_number" name="ccnumber" maxlength="19" 
                               placeholder="1234 5678 9012 3456" required>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group half">
                            <label for="nmi_exp_month">Exp Month</label>
                            <select id="nmi_exp_month" name="ccexp_month" required>
                                <option value="">MM</option>
                                <?php for($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?php echo sprintf('%02d', $i); ?>">
                                        <?php echo sprintf('%02d', $i); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="form-group half">
                            <label for="nmi_exp_year">Exp Year</label>
                            <select id="nmi_exp_year" name="ccexp_year" required>
                                <option value="">YYYY</option>
                                <?php for($i = date('Y'); $i <= date('Y') + 15; $i++): ?>
                                    <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="nmi_cvv">CVV</label>
                        <input type="text" id="nmi_cvv" name="cvv" maxlength="4" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="nmi_address">Address</label>
                        <input type="text" id="nmi_address" name="address1" required>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group half">
                            <label for="nmi_city">City</label>
                            <input type="text" id="nmi_city" name="city" required>
                        </div>
                        
                        <div class="form-group quarter">
                            <label for="nmi_state">State</label>
                            <input type="text" id="nmi_state" name="state" maxlength="2" required>
                        </div>
                        
                        <div class="form-group quarter">
                            <label for="nmi_zip">ZIP</label>
                            <input type="text" id="nmi_zip" name="zip" required>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" id="back-button" class="nmi-back-button">
                            Back
                        </button>
                        <button type="submit" class="nmi-pay-button">
                            <?php echo esc_html($atts['button_text']); ?>
                        </button>
                    </div>
                    
                    <div id="nmi-payment-messages"></div>
                </form>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    public function process_payment() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nmi_nonce'], 'nmi_payment_nonce')) {
            wp_die('Security check failed');
        }
        
        // Get NMI settings
        $settings = get_option('nmi_payment_settings', array());
        $api_key = isset($settings['api_key']) ? $settings['api_key'] : '';
        $sandbox = isset($settings['sandbox']) ? $settings['sandbox'] : true;
        
        if (empty($api_key)) {
            wp_send_json_error('Payment gateway not configured');
            return;
        }
        
        // Sanitize input data
        $payment_data = array(
            'security_key' => $api_key,
            'type' => 'sale',
            'amount' => sanitize_text_field($_POST['amount']),
            'ccnumber' => preg_replace('/\s+/', '', sanitize_text_field($_POST['ccnumber'])),
            'ccexp' => sanitize_text_field($_POST['ccexp_month']) . sanitize_text_field($_POST['ccexp_year']),
            'cvv' => sanitize_text_field($_POST['cvv']),
            'first_name' => sanitize_text_field($_POST['first_name']),
            'last_name' => sanitize_text_field($_POST['last_name']),
            'email' => sanitize_email($_POST['email']),
            'address1' => sanitize_text_field($_POST['address1']),
            'city' => sanitize_text_field($_POST['city']),
            'state' => sanitize_text_field($_POST['state']),
            'zip' => sanitize_text_field($_POST['zip']),
            'orderid' => 'WP-' . time() . '-' . rand(1000, 9999),
            'orderdescription' => sanitize_text_field($_POST['description'])
        );
        
        // NMI API endpoint
        $api_url = $sandbox ? 'https://secure.nmi.com/api/transact.php' : 'https://secure.nmi.com/api/transact.php';
        
        // Make API request
        $response = wp_remote_post($api_url, array(
            'body' => $payment_data,
            'timeout' => 30,
            'sslverify' => true
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error('Payment processing failed: ' . $response->get_error_message());
            return;
        }
        
        $body = wp_remote_retrieve_body($response);
        parse_str($body, $result);
        
        // Save transaction to database
        $this->save_transaction($payment_data, $result);
        
        if (isset($result['response']) && $result['response'] == '1') {
            wp_send_json_success(array(
                'message' => 'Payment successful!',
                'transaction_id' => isset($result['transactionid']) ? $result['transactionid'] : '',
                'amount' => $payment_data['amount']
            ));
        } else {
            $error_message = isset($result['responsetext']) ? $result['responsetext'] : 'Payment failed';
            wp_send_json_error($error_message);
        }
    }
    
    public function admin_menu() {
        add_options_page(
            'NMI Payment Settings',
            'NMI Payment',
            'manage_options',
            'nmi-payment-settings',
            array($this, 'admin_page')
        );
        
        // Add transactions page
        add_management_page(
            'NMI Transactions',
            'NMI Transactions',
            'manage_options',
            'nmi-transactions',
            array($this, 'transactions_page')
        );
    }
    
    public function admin_page() {
        if (isset($_POST['submit'])) {
            $settings = array(
                'api_key' => sanitize_text_field($_POST['api_key']),
                'sandbox' => isset($_POST['sandbox']),
                'default_button_text' => sanitize_text_field($_POST['default_button_text']),
                'default_description' => sanitize_text_field($_POST['default_description']),
            );
            update_option('nmi_payment_settings', $settings);
            echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
        }
        
        $settings = get_option('nmi_payment_settings', array());
        ?>
        <div class="wrap">
            <h1>NMI Payment Gateway Settings</h1>
            <form method="post">
                <table class="form-table">
                    <tr>
                        <th scope="row">API Key</th>
                        <td>
                            <input type="text" name="api_key" 
                                   value="<?php echo esc_attr(isset($settings['api_key']) ? $settings['api_key'] : ''); ?>" 
                                   class="regular-text" required>
                            <p class="description">Enter your NMI Security Key</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Sandbox Mode</th>
                        <td>
                            <input type="checkbox" name="sandbox" value="1" 
                                   <?php checked(isset($settings['sandbox']) ? $settings['sandbox'] : true); ?>>
                            <label>Enable sandbox/test mode</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Default Button Text</th>
                        <td>
                            <input type="text" name="default_button_text" 
                                   value="<?php echo esc_attr(isset($settings['default_button_text']) ? $settings['default_button_text'] : 'Pay Now'); ?>" 
                                   class="regular-text">
                            <p class="description">Default text for the payment button (can be overridden by shortcode)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Default Description</th>
                        <td>
                            <input type="text" name="default_description" 
                                   value="<?php echo esc_attr(isset($settings['default_description']) ? $settings['default_description'] : 'Payment'); ?>" 
                                   class="regular-text">
                            <p class="description">Default payment description (can be overridden by shortcode)</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            
            <h2>Usage</h2>
            <p>Use the shortcode <code>[nmi_payment_form]</code> to display the payment form.</p>
            <p>Available shortcode parameters:</p>
            <ul>
                <li><code>amount</code> - Fixed amount (optional)</li>
                <li><code>description</code> - Payment description (default: "<?php echo esc_html(isset($settings['default_description']) ? $settings['default_description'] : 'Payment'); ?>")</li>
                <li><code>button_text</code> - Button text (default: "<?php echo esc_html(isset($settings['default_button_text']) ? $settings['default_button_text'] : 'Pay Now'); ?>")</li>
            </ul>
            <p>Example: <code>[nmi_payment_form amount="19.99" description="Product Purchase" button_text="Buy Now"]</code></p>
        </div>
        <?php
    }
    
    public function transactions_page() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'nmi_transactions';
        
        // Handle search and pagination
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($paged - 1) * $per_page;
        
        // Build query
        $where = '';
        if (!empty($search)) {
            $where = $wpdb->prepare(
                " WHERE customer_email LIKE %s OR customer_name LIKE %s OR transaction_id LIKE %s OR order_id LIKE %s",
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%'
            );
        }
        
        // Get total count
        $total_query = "SELECT COUNT(*) FROM $table_name" . $where;
        $total_items = $wpdb->get_var($total_query);
        
        // Get transactions
        $transactions_query = "SELECT * FROM $table_name" . $where . " ORDER BY created_at DESC LIMIT $per_page OFFSET $offset";
        $transactions = $wpdb->get_results($transactions_query);
        
        // Calculate pagination
        $total_pages = ceil($total_items / $per_page);
        ?>
        <div class="wrap">
            <h1>NMI Transactions</h1>
            
            <!-- Search Form -->
            <form method="get" style="margin-bottom: 20px;">
                <input type="hidden" name="page" value="nmi-transactions">
                <p class="search-box">
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" 
                           placeholder="Search transactions...">
                    <input type="submit" class="button" value="Search">
                    <?php if ($search): ?>
                        <a href="<?php echo admin_url('tools.php?page=nmi-transactions'); ?>" class="button">Clear</a>
                    <?php endif; ?>
                </p>
            </form>
            
            <!-- Transactions Table -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Transaction ID</th>
                        <th>Order ID</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Customer</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 20px;">
                                <?php echo $search ? 'No transactions found matching your search.' : 'No transactions found.'; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $transaction): ?>
                            <tr>
                                <td><?php echo esc_html($transaction->id); ?></td>
                                <td>
                                    <strong><?php echo esc_html($transaction->transaction_id ?: 'N/A'); ?></strong>
                                </td>
                                <td><?php echo esc_html($transaction->order_id); ?></td>
                                <td>$<?php echo number_format($transaction->amount, 2); ?></td>
                                <td>
                                    <span class="status-<?php echo esc_attr($transaction->status); ?>">
                                        <?php echo ucfirst(esc_html($transaction->status)); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($transaction->customer_name); ?></strong><br>
                                    <small><?php echo esc_html($transaction->customer_email); ?></small>
                                </td>
                                <td>
                                    <?php echo date('M j, Y g:i A', strtotime($transaction->created_at)); ?>
                                </td>
                                <td>
                                    <button type="button" class="button button-small view-details" 
                                            data-id="<?php echo esc_attr($transaction->id); ?>">
                                        View Details
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num">
                            <?php echo number_format($total_items); ?> items
                        </span>
                        <?php
                        $page_links = paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $paged,
                            'type' => 'array'
                        ));
                        
                        if ($page_links) {
                            echo '<span class="pagination-links">' . implode('', $page_links) . '</span>';
                        }
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Transaction Details Modal -->
        <div id="transaction-modal" style="display: none;">
            <div class="transaction-modal-content">
                <span class="close">&times;</span>
                <h2>Transaction Details</h2>
                <div id="transaction-details"></div>
            </div>
        </div>
        
        <style>
        .status-success {
            color: #007cba;
            font-weight: 600;
        }
        
        .status-failed {
            color: #dc3232;
            font-weight: 600;
        }
        
        #transaction-modal {
            position: fixed;
            z-index: 999999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .transaction-modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 600px;
            border-radius: 5px;
            position: relative;
        }
        
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            position: absolute;
            right: 15px;
            top: 10px;
        }
        
        .close:hover {
            color: black;
        }
        
        .detail-row {
            margin-bottom: 10px;
            padding: 5px 0;
            border-bottom: 1px solid #eee;
        }
        
        .detail-label {
            font-weight: 600;
            display: inline-block;
            width: 150px;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Handle view details button
            $('.view-details').on('click', function() {
                var transactionId = $(this).data('id');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'get_transaction_details',
                        transaction_id: transactionId,
                        nonce: '<?php echo wp_create_nonce('nmi_admin_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#transaction-details').html(response.data);
                            $('#transaction-modal').show();
                        } else {
                            alert('Error loading transaction details');
                        }
                    }
                });
            });
            
            // Close modal
            $('.close, #transaction-modal').on('click', function(e) {
                if (e.target === this) {
                    $('#transaction-modal').hide();
                }
            });
        });
        </script>
        <?php
    }
    
    public function get_transaction_details() {
        if (!wp_verify_nonce($_POST['nonce'], 'nmi_admin_nonce') || !current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'nmi_transactions';
        $transaction_id = intval($_POST['transaction_id']);
        
        $transaction = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d", 
            $transaction_id
        ));
        
        if (!$transaction) {
            wp_send_json_error('Transaction not found');
            return;
        }
        
        $payment_data = json_decode($transaction->payment_data, true);
        $response_data = json_decode($transaction->response_data, true);
        
        ob_start();
        ?>
        <div class="detail-row">
            <span class="detail-label">Transaction ID:</span>
            <?php echo esc_html($transaction->transaction_id ?: 'N/A'); ?>
        </div>
        <div class="detail-row">
            <span class="detail-label">Order ID:</span>
            <?php echo esc_html($transaction->order_id); ?>
        </div>
        <div class="detail-row">
            <span class="detail-label">Amount:</span>
            $<?php echo number_format($transaction->amount, 2); ?>
        </div>
        <div class="detail-row">
            <span class="detail-label">Status:</span>
            <span class="status-<?php echo esc_attr($transaction->status); ?>">
                <?php echo ucfirst(esc_html($transaction->status)); ?>
            </span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Customer:</span>
            <?php echo esc_html($transaction->customer_name); ?>
        </div>
        <div class="detail-row">
            <span class="detail-label">Email:</span>
            <?php echo esc_html($transaction->customer_email); ?>
        </div>
        <div class="detail-row">
            <span class="detail-label">Date:</span>
            <?php echo date('M j, Y g:i A', strtotime($transaction->created_at)); ?>
        </div>
        
        <?php if (!empty($payment_data['address1'])): ?>
        <div class="detail-row">
            <span class="detail-label">Address:</span>
            <?php 
            echo esc_html($payment_data['address1']) . '<br>';
            echo esc_html($payment_data['city']) . ', ' . esc_html($payment_data['state']) . ' ' . esc_html($payment_data['zip']);
            ?>
        </div>
        <?php endif; ?>
        
        <?php if (isset($response_data['responsetext'])): ?>
        <div class="detail-row">
            <span class="detail-label">Response:</span>
            <?php echo esc_html($response_data['responsetext']); ?>
        </div>
        <?php endif; ?>
        
        <?php if (isset($response_data['authcode']) && $response_data['authcode']): ?>
        <div class="detail-row">
            <span class="detail-label">Auth Code:</span>
            <?php echo esc_html($response_data['authcode']); ?>
        </div>
        <?php endif; ?>
        
        <div class="detail-row">
            <span class="detail-label">Card (Last 4):</span>
            <?php 
            if (isset($payment_data['ccnumber'])) {
                echo '**** **** **** ' . substr($payment_data['ccnumber'], -4);
            } else {
                echo 'N/A';
            }
            ?>
        </div>
        <?php
        
        $content = ob_get_clean();
        wp_send_json_success($content);
    }
    
    private function create_transactions_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'nmi_transactions';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            transaction_id varchar(100) DEFAULT '',
            order_id varchar(100) DEFAULT '',
            amount decimal(10,2) NOT NULL,
            status varchar(50) DEFAULT '',
            customer_email varchar(100) DEFAULT '',
            customer_name varchar(200) DEFAULT '',
            payment_data text,
            response_data text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    private function save_transaction($payment_data, $response_data) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'nmi_transactions';
        
        $wpdb->insert(
            $table_name,
            array(
                'transaction_id' => isset($response_data['transactionid']) ? $response_data['transactionid'] : '',
                'order_id' => $payment_data['orderid'],
                'amount' => $payment_data['amount'],
                'status' => isset($response_data['response']) ? ($response_data['response'] == '1' ? 'success' : 'failed') : 'failed',
                'customer_email' => $payment_data['email'],
                'customer_name' => $payment_data['first_name'] . ' ' . $payment_data['last_name'],
                'payment_data' => json_encode($payment_data),
                'response_data' => json_encode($response_data)
            ),
            array('%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s')
        );
    }
    
    public function activate() {
        $this->create_transactions_table();
    }
    
    public function deactivate() {
        // Cleanup if needed
    }
}

// Initialize the plugin
new NMI_Payment_Gateway();