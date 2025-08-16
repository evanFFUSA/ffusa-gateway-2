<?php
/**
 * Plugin Name: NMI Payment Gateway
 * Plugin URI: https://www.ffusa.com
 * Description: Customizable NMI payment gateway integration for WordPress sites
 * Version: 1.1.2
 * Author: FFUSA
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('NMI_PAYMENT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('NMI_PAYMENT_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('NMI_PAYMENT_VERSION', '1.1.2');

require_once dirname(__FILE__) . '/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/evanFFUSA/ffusa-gateway-2/',
    __FILE__,
    'ffusa-gateway-2' // Slug of your plugin
);

// Set the branch to check for updates (e.g., main).
$myUpdateChecker->setBranch('main');

// Enable releases for private repositories.
$myUpdateChecker->setAuthentication('y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');

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
        // Create database table for custom fields
        // $this->create_custom_fields_table();
        // Update transactions table structure
        $this->update_transactions_table();

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
        $show_description_field = isset($settings['show_description_field']) ? $settings['show_description_field'] : true;
        $description_field_label = isset($settings['description_field_label']) ? $settings['description_field_label'] : 'Description';
        $description_placeholder = isset($settings['description_placeholder']) ? $settings['description_placeholder'] : 'What is this payment for?';
        
        $atts = shortcode_atts(array(
            'amount' => '',
            'description' => $default_description,
            'button_text' => $default_button_text,
            'show_description' => $show_description_field ? 'true' : 'false'
        ), $atts);
        
        // Convert string to boolean for show_description
        $show_description = ($atts['show_description'] === 'true' || $atts['show_description'] === '1');
        
        ob_start();
        ?>
            <div class="nmi-payment-form-container">
            <!-- Step 1: Amount and Payment Type Selection -->
            <div id="step-1" class="payment-step active">
                <h3>Select Your Contribution</h3>
                
                <!-- Payment Type Toggle -->
                <div class="form-group">
                    <div class="payment-type-tabs">
                        <button type="button" id="one-time-tab" class="payment-type-tab">One Time</button>
                        <button type="button" id="recurring-tab" class="payment-type-tab active">Recurring</button>
                    </div>
                </div>
                
                <!-- One-Time Payment Section -->
                <div id="one-time-section" class="payment-type-section" style="display: none;">
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
                    </div>
                </div>
                
                <!-- Recurring Payment Section -->
                <div id="recurring-section" class="payment-type-section active">
                    <div class="form-group">
                        <label>Select Recurring Amount</label>
                        <div class="amount-buttons recurring-amounts">
                            <button type="button" class="amount-btn recurring-amount-btn" data-amount="10">$10</button>
                            <button type="button" class="amount-btn recurring-amount-btn" data-amount="25">$25</button>
                            <button type="button" class="amount-btn recurring-amount-btn" data-amount="50">$50</button>
                            <button type="button" class="amount-btn recurring-amount-btn" data-amount="100">$100</button>
                        </div>
                        <div class="other-amount-container">
                            <button type="button" id="recurring-other-amount-btn" class="other-amount-toggle">Other Amount</button>
                            <div id="recurring-custom-amount-input" class="custom-amount-input" style="display: none;">
                                <label for="recurring_step1_amount">Enter Amount ($)</label>
                                <input type="number" id="recurring_step1_amount" step="0.01" min="0.01">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                    <label>Billing Frequency</label>
                        <div class="frequency-buttons">
                            <button type="button" class="frequency-btn" data-frequency="monthly" data-days="30">Monthly</button>
                            <button type="button" class="frequency-btn" data-frequency="quarterly" data-days="90">Quarterly</button>
                            <button type="button" class="frequency-btn" data-frequency="annually" data-days="365">Annually</button>
                        </div>
                        <input type="hidden" id="selected_frequency" name="selected_frequency">
                        <input type="hidden" id="selected_frequency_days" name="selected_frequency_days">
                    </div>
                    
                    <!-- Impact message for recurring donations -->
                    <div class="recurring-impact-message">
                        <h4 class="impact-title">You're making an Impact!</h4>
                        <p class="impact-subtitle">Thanks for your ongoing donation! Your continuous support helps us meet ongoing and future needs!</p>
                        <p class="impact-subtitle">We hope you will support us for a long time, but cancel anytime</p>
                    </div>
                </div>
                
                <!-- Hidden fields to track payment type and amount -->
                <input type="hidden" id="selected_amount" name="selected_amount">
                <input type="hidden" id="payment_type" name="payment_type" value="recurring">
                
                <?php if ($show_description): ?>
                <!-- User toggle for description field -->
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="description_toggle" class="description-toggle-checkbox">
                        Add a <?php echo esc_html($description_field_label); ?>
                    </label>
                </div>
                
                <!-- Description field (initially hidden) -->
                <div class="form-group" id="description_field_container" style="display: none;">
                    <label for="step1_description"><?php echo esc_html($description_field_label); ?></label>
                    <input type="text" id="step1_description" 
                        value="<?php echo esc_attr($atts['description']); ?>" 
                        placeholder="<?php echo esc_attr($description_placeholder); ?>">
                </div>
                <?php else: ?>
                <!-- Hidden description field with default value -->
                <input type="hidden" id="step1_description" value="<?php echo esc_attr($atts['description']); ?>">
                <?php endif; ?>
                
                <div class="form-group">
                    <button type="button" id="give-button" class="nmi-give-button">
                        <span id="give-button-text">Give</span>
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
                    
                    <!-- Add this right after the payment summary div and before the first name field -->

                    <?php
                    // Get support direction settings
                    $show_support_direction = isset($settings['show_support_direction']) ? $settings['show_support_direction'] : true;
                    $support_direction_required = isset($settings['support_direction_required']) ? $settings['support_direction_required'] : false;
                    $support_direction_options = isset($settings['support_direction_options']) ? $settings['support_direction_options'] : "General Fund\nEducation Programs\nCommunity Outreach\nEmergency Relief";

                    if ($show_support_direction):
                        $options_array = array_filter(array_map('trim', explode("\n", $support_direction_options)));
                    ?>
                    <div class="form-group">
                        <label for="nmi_support_direction">Please direct my support to:</label>
                        <select id="nmi_support_direction" name="support_direction" <?php echo $support_direction_required ? 'required' : ''; ?>>
                            <option value="">-- Select an option --</option>
                            <?php foreach ($options_array as $option): ?>
                                <option value="<?php echo esc_attr($option); ?>"><?php echo esc_html($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

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

        // Validate all input data
        $validation_errors = $this->validate_payment_data($_POST);
        if (!empty($validation_errors)) {
            wp_send_json_error('Validation failed: ' . implode(', ', $validation_errors));
            return;
        }

        // Rate limiting based on IP address
        $user_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (!$this->check_rate_limit($user_ip)) {
            wp_send_json_error('Too many payment attempts. Please wait 15 minutes before trying again.');
            return;
        }
        
        // Get NMI settings
        $settings = get_option('nmi_payment_settings', array());
        $api_key = isset($settings['api_key']) ? $settings['api_key'] : '';
        $sandbox = isset($settings['sandbox']) ? $settings['sandbox'] : true;
        
        if (empty($api_key)) {
            wp_send_json_error('Payment gateway not configured');
            return;
        }
        
        // Check if this is a recurring payment
        $payment_type = isset($_POST['payment_type']) ? sanitize_text_field($_POST['payment_type']) : 'one-time';
        $is_recurring = ($payment_type === 'recurring');
        
        // NMI API endpoint
        $api_url = $sandbox ? 'https://secure.nmi.com/api/transact.php' : 'https://secure.nmi.com/api/transact.php';
        
        if ($is_recurring) {
            // For recurring payments: Process immediate payment first, then set up subscription
            
            // Step 1: Process immediate one-time payment
            $immediate_payment_data = array(
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
                'orderid' => 'WP-IMMEDIATE-' . time() . '-' . rand(1000, 9999),
                'orderdescription' => sanitize_text_field($_POST['description']) . ' (Initial Payment)',
                'customer_vault' => 'add_customer' // Store customer for recurring billing
            );
            
            // Make immediate payment request
            $immediate_response = wp_remote_post($api_url, array(
                'body' => $immediate_payment_data,
                'timeout' => 30,
                'sslverify' => true
            ));
            
            if (is_wp_error($immediate_response)) {
                wp_send_json_error('Immediate payment processing failed: ' . $immediate_response->get_error_message());
                return;
            }
            
            $immediate_body = wp_remote_retrieve_body($immediate_response);
            parse_str($immediate_body, $immediate_result);
            
            // Check if immediate payment was successful
            if (!isset($immediate_result['response']) || $immediate_result['response'] != '1') {
                $error_message = isset($immediate_result['responsetext']) ? $immediate_result['responsetext'] : 'Immediate payment failed';
                
                // Clean output buffer
                if (ob_get_level()) {
                    ob_clean();
                }
                
                wp_send_json_error('Initial payment failed: ' . $error_message);
                return;
            }
            
            // Save the immediate transaction
            $this->save_transaction($immediate_payment_data, $immediate_result);
            
            // Step 2: Set up recurring subscription using customer vault ID
            $customer_vault_id = isset($immediate_result['customer_vault_id']) ? $immediate_result['customer_vault_id'] : '';
            
            if (empty($customer_vault_id)) {
                // Clean output buffer
                if (ob_get_level()) {
                    ob_clean();
                }
                
                wp_send_json_error('Customer vault ID not returned. Recurring billing setup failed.');
                return;
            }
            
            $recurring_payment_data = array(
                'security_key' => $api_key,
                'recurring' => 'add_subscription',
                'plan_payments' => '0',
                'day_frequency' => sanitize_text_field($_POST['selected_frequency_days']),
                'plan_amount' => sanitize_text_field($_POST['amount']),
                'customer_vault_id' => $customer_vault_id,
                'orderid' => 'WP-RECURRING-' . time() . '-' . rand(1000, 9999),
                'orderdescription' => sanitize_text_field($_POST['description']) . ' (Recurring)',
                'start_date' => date('Ymd', strtotime('+' . sanitize_text_field($_POST['selected_frequency_days']) . ' days'))
            );
            
            // Make recurring subscription request
            $recurring_response = wp_remote_post($api_url, array(
                'body' => $recurring_payment_data,
                'timeout' => 30,
                'sslverify' => true
            ));
            
            if (is_wp_error($recurring_response)) {
                wp_send_json_error('Recurring billing setup failed: ' . $recurring_response->get_error_message());
                return;
            }
            
            $recurring_body = wp_remote_retrieve_body($recurring_response);
            parse_str($recurring_body, $recurring_result);
            
            // Save the recurring subscription setup
            $this->save_transaction($recurring_payment_data, $recurring_result);
            
            // Check if recurring setup was successful
            if (isset($recurring_result['response']) && $recurring_result['response'] == '1') {
                $subscription_id = isset($recurring_result['subscription_id']) ? $recurring_result['subscription_id'] : '';
                $transaction_id = isset($immediate_result['transactionid']) ? $immediate_result['transactionid'] : '';
                
                // Clean output buffer to prevent any stray output
                if (ob_get_level()) {
                    ob_clean();
                }
                
                wp_send_json_success(array(
                    'message' => 'Payment processed and recurring billing setup successful!',
                    'transaction_id' => $transaction_id,
                    'subscription_id' => $subscription_id,
                    'amount' => $immediate_payment_data['amount'],
                    'frequency' => sanitize_text_field($_POST['selected_frequency']),
                    'next_billing_date' => date('M j, Y', strtotime('+' . sanitize_text_field($_POST['selected_frequency_days']) . ' days'))
                ));
            } else {
                $error_message = isset($recurring_result['responsetext']) ? $recurring_result['responsetext'] : 'Recurring billing setup failed';
                
                // Clean output buffer
                if (ob_get_level()) {
                    ob_clean();
                }
                
                wp_send_json_error('Payment processed successfully, but recurring setup failed: ' . $error_message);
            }
            
        } else {
            // For one-time payments, use original logic
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
                // Clean output buffer
                if (ob_get_level()) {
                    ob_clean();
                }
                
                wp_send_json_success(array(
                    'message' => 'Payment successful!',
                    'transaction_id' => isset($result['transactionid']) ? $result['transactionid'] : '',
                    'amount' => $payment_data['amount']
                ));
            } else {
                $error_message = isset($result['responsetext']) ? $result['responsetext'] : 'Payment failed';
                
                // Clean output buffer
                if (ob_get_level()) {
                    ob_clean();
                }
                
                wp_send_json_error($error_message);
            }
        }
    }
    

    private function check_rate_limit($identifier, $max_attempts = 5, $time_window = 900) { // 15 minutes
        $transient_key = 'nmi_rate_limit_' . md5($identifier);
        $attempts = get_transient($transient_key);
        
        if ($attempts === false) {
            set_transient($transient_key, 1, $time_window);
            return true;
        }
        
        if ($attempts >= $max_attempts) {
            return false;
        }
        
        set_transient($transient_key, $attempts + 1, $time_window);
        return true;
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
            'sandbox' => isset($_POST['sandbox']),
            'default_button_text' => sanitize_text_field($_POST['default_button_text']),
            'default_description' => sanitize_text_field($_POST['default_description']),
            'show_description_field' => isset($_POST['show_description_field']),
            'description_field_required' => isset($_POST['description_field_required']),
            'description_field_label' => sanitize_text_field($_POST['description_field_label']),
            'description_placeholder' => sanitize_text_field($_POST['description_placeholder']),
            'support_direction_options' => sanitize_textarea_field($_POST['support_direction_options']),
            'show_support_direction' => isset($_POST['show_support_direction']),
            'support_direction_required' => isset($_POST['support_direction_required']),
        );
        
        // Only update API key if a new one is provided (not the masked placeholder)
        $api_key_input = sanitize_text_field($_POST['api_key']);
        if (!empty($api_key_input) && $api_key_input !== '••••••••••••••••••••') {
            $settings['api_key'] = $api_key_input;
        } else {
            // Keep existing API key
            $existing_settings = get_option('nmi_payment_settings', array());
            $settings['api_key'] = isset($existing_settings['api_key']) ? $existing_settings['api_key'] : '';
        }
        
        update_option('nmi_payment_settings', $settings);
        echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
    }
        
        $settings = get_option('nmi_payment_settings', array());
        $api_key = isset($settings['api_key']) ? $settings['api_key'] : '';
        $api_key_display = !empty($api_key) ? '••••••••••••••••••••' : '';
        ?>
        <div class="wrap">
        <h1>NMI Payment Gateway Settings</h1>
        <form method="post">
            <table class="form-table">
                <tr>
                    <th scope="row">API Key</th>
                    <td>
                        <div style="position: relative;">
                            <input type="password" 
                                   id="nmi_api_key" 
                                   name="api_key" 
                                   value="<?php echo esc_attr($api_key_display); ?>" 
                                   class="regular-text" 
                                   placeholder="Enter your NMI Security Key"
                                   autocomplete="new-password">
                            <button type="button" 
                                    id="toggle_api_key" 
                                    class="button button-secondary" 
                                    style="margin-left: 10px;"
                                    onclick="toggleApiKeyVisibility()">
                                <?php echo !empty($api_key) ? 'Change' : 'Show'; ?>
                            </button>
                            <?php if (!empty($api_key)): ?>
                                <button type="button" 
                                        id="clear_api_key" 
                                        class="button button-secondary" 
                                        style="margin-left: 5px; color: #dc3232;"
                                        onclick="clearApiKey()">
                                    Clear
                                </button>
                            <?php endif; ?>
                        </div>
                        <p class="description">
                            Enter your NMI Security Key. 
                            <?php if (!empty($api_key)): ?>
                                <span style="color: #46b450;">✓ API Key is configured</span>
                            <?php else: ?>
                                <span style="color: #dc3232;">⚠ API Key is required</span>
                            <?php endif; ?>
                        </p>
                        <input type="hidden" id="api_key_changed" name="api_key_changed" value="0">
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
                <tr>
                    <th scope="row">Show Description Field</th>
                    <td>
                        <input type="checkbox" name="show_description_field" value="1" 
                               <?php checked(isset($settings['show_description_field']) ? $settings['show_description_field'] : true); ?>>
                        <label>Show description field on payment form</label>
                        <p class="description">Allow users to toggle a custom description for their payment</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Description Field Required</th>
                    <td>
                        <input type="checkbox" name="description_field_required" value="1" 
                               <?php checked(isset($settings['description_field_required']) ? $settings['description_field_required'] : false); ?>>
                        <label>Make description field required when visible</label>
                        <p class="description">When the description field is shown, require users to fill it out</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Description Field Label</th>
                    <td>
                        <input type="text" name="description_field_label" 
                               value="<?php echo esc_attr(isset($settings['description_field_label']) ? $settings['description_field_label'] : 'Description'); ?>" 
                               class="regular-text">
                        <p class="description">Label text for the description field</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Description Placeholder Text</th>
                    <td>
                        <input type="text" name="description_placeholder" 
                               value="<?php echo esc_attr(isset($settings['description_placeholder']) ? $settings['description_placeholder'] : 'What is this payment for?'); ?>" 
                               class="regular-text">
                        <p class="description">Placeholder text shown in the description field</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Support Direction Options</th>
                    <td>
                        <textarea name="support_direction_options" 
                                rows="5" 
                                class="large-text"
                                placeholder="Enter one option per line"><?php echo esc_textarea(isset($settings['support_direction_options']) ? $settings['support_direction_options'] : "General Fund\nEducation Programs\nCommunity Outreach\nEmergency Relief"); ?></textarea>
                        <p class="description">Enter dropdown options for "Please direct my support to:" field. One option per line.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Show Support Direction Field</th>
                    <td>
                        <input type="checkbox" name="show_support_direction" value="1" 
                            <?php checked(isset($settings['show_support_direction']) ? $settings['show_support_direction'] : true); ?>>
                        <label>Show "Please direct my support to:" dropdown on payment form</label>
                        <p class="description">Allow users to specify where their donation should be directed</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Support Direction Required</th>
                    <td>
                        <input type="checkbox" name="support_direction_required" value="1" 
                            <?php checked(isset($settings['support_direction_required']) ? $settings['support_direction_required'] : false); ?>>
                        <label>Make support direction field required</label>
                        <p class="description">Require users to select a support direction option</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
            <script>
        function toggleApiKeyVisibility() {
            var input = document.getElementById('nmi_api_key');
            var button = document.getElementById('toggle_api_key');
            var changed = document.getElementById('api_key_changed');
            
            if (input.type === 'password') {
                input.type = 'text';
                input.value = '';
                input.placeholder = 'Enter your new NMI Security Key';
                input.focus();
                button.textContent = 'Cancel';
                changed.value = '1';
            } else {
                input.type = 'password';
                input.value = '••••••••••••••••••••';
                input.placeholder = 'Enter your NMI Security Key';
                button.textContent = 'Change';
                changed.value = '0';
            }
        }
        
        function clearApiKey() {
            if (confirm('Are you sure you want to clear the API key? This will disable payment processing until a new key is entered.')) {
                var input = document.getElementById('nmi_api_key');
                input.type = 'text';
                input.value = '';
                input.placeholder = 'Enter your NMI Security Key';
                document.getElementById('api_key_changed').value = '1';
                document.getElementById('toggle_api_key').textContent = 'Show';
                
                // Hide the clear button since we're clearing
                var clearBtn = document.getElementById('clear_api_key');
                if (clearBtn) {
                    clearBtn.style.display = 'none';
                }
            }
        }
        
        // Prevent form submission if trying to save the masked value
        document.querySelector('form').addEventListener('submit', function(e) {
            var input = document.getElementById('nmi_api_key');
            var changed = document.getElementById('api_key_changed');
            
            if (input.value === '••••••••••••••••••••' && changed.value === '0') {
                // It's the masked value and hasn't been changed, which is fine
                return true;
            }
            
            if (input.value === '' && changed.value === '1') {
                // User is clearing the API key
                return true;
            }
            
            if (input.value !== '' && input.value !== '••••••••••••••••••••') {
                // User has entered a new API key
                return true;
            }
            
            // Shouldn't reach here, but just in case
            return true;
        });
        </script>
        
        <h2>Usage</h2>
        <p>Use the shortcode <code>[nmi_payment_form]</code> to display the payment form.</p>
        <p>Available shortcode parameters:</p>
        <ul>
            <li><code>amount</code> - Fixed amount (optional)</li>
            <li><code>description</code> - Payment description (default: "<?php echo esc_html(isset($settings['default_description']) ? $settings['default_description'] : 'Payment'); ?>")</li>
            <li><code>button_text</code> - Button text (default: "<?php echo esc_html(isset($settings['default_button_text']) ? $settings['default_button_text'] : 'Pay Now'); ?>")</li>
            <li><code>show_description</code> - Show/hide description field (default: <?php echo isset($settings['show_description_field']) && $settings['show_description_field'] ? 'true' : 'false'; ?>)</li>
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
        
        /// Build query with proper escaping
        $where_clause = '';
        $prepare_values = array();

        if (!empty($search)) {
            $where_clause = " WHERE customer_email LIKE %s OR customer_name LIKE %s OR transaction_id LIKE %s OR support_direction LIKE %s";
            $search_term = '%' . $wpdb->esc_like($search) . '%';
            $prepare_values = array($search_term, $search_term, $search_term, $search_term, $per_page, $offset);
        } else {
            $prepare_values = array($per_page, $offset);
        }

        // Get total count
        if (!empty($search)) {
            $total_items = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE customer_email LIKE %s OR customer_name LIKE %s OR transaction_id LIKE %s OR support_direction LIKE %s",
                $search_term, $search_term, $search_term, $search_term
            ));
        } else {
            $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        }

        // Get transactions with proper preparation
        if (!empty($search)) {
            $transactions = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table_name WHERE customer_email LIKE %s OR customer_name LIKE %s OR transaction_id LIKE %s OR support_direction LIKE %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $search_term, $search_term, $search_term, $search_term, $per_page, $offset
            ));
        } else {
            $transactions = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $per_page, $offset
            ));
        }
        
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
            
            <!-- Simplified Transactions Table -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Transaction ID</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Customer</th>
                        <th>Email</th>
                        <th>Support Direction</th>
                        <th>Date</th>
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
                                <td>$<?php echo number_format($transaction->amount, 2); ?></td>
                                <td>
                                    <span class="status-<?php echo esc_attr($transaction->status); ?>">
                                        <?php echo ucfirst(esc_html($transaction->status)); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($transaction->customer_name); ?></strong>
                                </td>
                                <td>
                                    <?php echo esc_html($transaction->customer_email); ?>
                                </td>
                                <td>
                                    <?php echo esc_html($transaction->support_direction ?: 'Not Specified'); ?>
                                </td>
                                <td>
                                    <?php echo date('M j, Y g:i A', strtotime($transaction->created_at)); ?>
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
        
        <style>
        .status-success {
            color: #007cba;
            font-weight: 600;
        }
        
        .status-failed {
            color: #dc3232;
            font-weight: 600;
        }
        </style>
        <?php
    }

    private function save_transaction($payment_data, $response_data) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'nmi_transactions';
        
        // Get support direction from POST data (since it's not in payment_data)
        $support_direction = isset($_POST['support_direction']) ? sanitize_text_field($_POST['support_direction']) : '';
        
        // Only store essential transaction information
        $wpdb->insert(
            $table_name,
            array(
                'transaction_id' => isset($response_data['transactionid']) ? sanitize_text_field($response_data['transactionid']) : '',
                'amount' => floatval($payment_data['amount']),
                'status' => isset($response_data['response']) ? ($response_data['response'] == '1' ? 'success' : 'failed') : 'failed',
                'customer_email' => sanitize_email($payment_data['email']),
                'customer_name' => sanitize_text_field($payment_data['first_name'] . ' ' . $payment_data['last_name']),
                'support_direction' => $support_direction
            ),
            array('%s', '%f', '%s', '%s', '%s', '%s')
        );
    }

    private function validate_payment_data($data) {
        $errors = array();
        
        // Get settings to check if support direction is required
        $settings = get_option('nmi_payment_settings', array());
        $support_direction_required = isset($settings['support_direction_required']) ? $settings['support_direction_required'] : false;
        $show_support_direction = isset($settings['show_support_direction']) ? $settings['show_support_direction'] : true;
        
        // Validate support direction if required
        if ($show_support_direction && $support_direction_required) {
            if (!isset($data['support_direction']) || empty(trim($data['support_direction']))) {
                $errors[] = 'Please select where you would like to direct your support';
            }
        }
        
        // Validate amount
        if (!isset($data['amount']) || !is_numeric($data['amount']) || floatval($data['amount']) <= 0) {
            $errors[] = 'Invalid amount';
        }
        
        // Validate email
        if (!isset($data['email']) || !is_email($data['email'])) {
            $errors[] = 'Invalid email address';
        }
        
        // Validate card number (basic length check)
        if (!isset($data['ccnumber'])) {
            $errors[] = 'Card number is required';
        } else {
            $card_number = preg_replace('/\s+/', '', $data['ccnumber']);
            if (!preg_match('/^\d{13,19}$/', $card_number)) {
                $errors[] = 'Invalid card number format';
            }
        }
        
        // Validate CVV
        if (!isset($data['cvv']) || !preg_match('/^\d{3,4}$/', $data['cvv'])) {
            $errors[] = 'Invalid CVV';
        }
        
        // Validate expiration
        if (!isset($data['ccexp_month']) || !isset($data['ccexp_year'])) {
            $errors[] = 'Expiration date is required';
        } else {
            $exp_month = intval($data['ccexp_month']);
            $exp_year = intval($data['ccexp_year']);
            $current_year = intval(date('Y'));
            $current_month = intval(date('n'));
            
            if ($exp_month < 1 || $exp_month > 12) {
                $errors[] = 'Invalid expiration month';
            }
            
            if ($exp_year < $current_year || ($exp_year == $current_year && $exp_month < $current_month)) {
                $errors[] = 'Card has expired';
            }
        }
        
        // Validate required fields
        $required_fields = array('first_name', 'last_name', 'address1', 'city', 'state', 'zip');
        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || empty(trim($data[$field]))) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            }
        }
        
        return $errors;
    }
    
    private function create_transactions_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'nmi_transactions';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            transaction_id varchar(100) DEFAULT '',
            amount decimal(10,2) NOT NULL,
            status varchar(50) DEFAULT '',
            customer_email varchar(100) DEFAULT '',
            customer_name varchar(200) DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY transaction_id (transaction_id),
            KEY customer_email (customer_email),
            KEY status (status)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    private function update_transactions_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'nmi_transactions';
        
        // Check if the column already exists
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'support_direction'");
        
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN support_direction varchar(200) DEFAULT '' AFTER customer_name");
        }
    }

    /* REMOVAL OF CUSTOM FIELDS TABLE - UNCOMMENT IF NEEDED
    public function remove_custom_fields_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'nmi_custom_fields';
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
    }
    */

    public function activate() {
        $this->create_transactions_table();
        // $this->remove_custom_fields_table(); //temporary removal of custom fields table
    }
    
    public function deactivate() {
        // Cleanup if needed
    }
}

// Initialize the plugin
new NMI_Payment_Gateway();