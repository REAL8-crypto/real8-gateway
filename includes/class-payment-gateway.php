<?php
/**
 * WooCommerce REAL8 Payment Gateway
 *
 * @package REAL8_Gateway
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REAL8 Payment Gateway class
 */
class WC_Gateway_REAL8 extends WC_Payment_Gateway {

    /**
     * Stellar API instance
     */
    private $stellar_api;

    /**
     * Constructor
     */
    public function __construct() {
        $this->id = 'real8_payment';
        $this->icon = ''; // Can add REAL8 logo URL
        $this->has_fields = false;
        $this->method_title = __('REAL8 Payment', 'real8-gateway');
        $this->method_description = __('Accept payments in REAL8 tokens on the Stellar network', 'real8-gateway');

        // Supported features
        $this->supports = array(
            'products',
        );

        // Load settings
        $this->init_form_fields();
        $this->init_settings();

        // Get settings
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->merchant_address = $this->get_option('merchant_address');
        $this->payment_timeout = $this->get_option('payment_timeout', REAL8_GW_PAYMENT_TIMEOUT_MINUTES);
        $this->price_buffer = $this->get_option('price_buffer', REAL8_GW_PRICE_BUFFER_PERCENT);

        // Initialize Stellar API
        $this->stellar_api = REAL8_Stellar_Payment_API::get_instance();

        // Hooks
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
        add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // AJAX handlers
        add_action('wp_ajax_real8_check_payment_status', array($this, 'ajax_check_payment_status'));
        add_action('wp_ajax_nopriv_real8_check_payment_status', array($this, 'ajax_check_payment_status'));
        add_action('wp_ajax_real8_refresh_price', array($this, 'ajax_refresh_price'));
        add_action('wp_ajax_nopriv_real8_refresh_price', array($this, 'ajax_refresh_price'));
    }

    /**
     * Initialize Gateway Settings Form Fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'real8-gateway'),
                'type' => 'checkbox',
                'label' => __('Enable REAL8 Payment', 'real8-gateway'),
                'default' => 'no',
            ),
            'title' => array(
                'title' => __('Title', 'real8-gateway'),
                'type' => 'text',
                'description' => __('Payment method title displayed to customers', 'real8-gateway'),
                'default' => __('Pay with REAL8', 'real8-gateway'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'real8-gateway'),
                'type' => 'textarea',
                'description' => __('Payment method description displayed during checkout', 'real8-gateway'),
                'default' => __('Pay with REAL8 tokens on the Stellar blockchain. Fast, secure, and low fees.', 'real8-gateway'),
                'desc_tip' => true,
            ),
            'merchant_address' => array(
                'title' => __('Merchant Stellar Address', 'real8-gateway'),
                'type' => 'text',
                'description' => __('Your Stellar public key (starts with G) where REAL8 payments will be received. Must have REAL8 trustline.', 'real8-gateway'),
                'default' => '',
                'desc_tip' => true,
                'custom_attributes' => array(
                    'placeholder' => 'GXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX',
                    'maxlength' => 56,
                ),
            ),
            'payment_timeout' => array(
                'title' => __('Payment Timeout (minutes)', 'real8-gateway'),
                'type' => 'number',
                'description' => __('How long customers have to complete payment. Default: 30 minutes.', 'real8-gateway'),
                'default' => REAL8_GW_PAYMENT_TIMEOUT_MINUTES,
                'desc_tip' => true,
                'custom_attributes' => array(
                    'min' => 5,
                    'max' => 120,
                ),
            ),
            'price_buffer' => array(
                'title' => __('Price Buffer (%)', 'real8-gateway'),
                'type' => 'number',
                'description' => __('Buffer percentage to account for price volatility. Customers pay this % more in REAL8. Default: 2%.', 'real8-gateway'),
                'default' => REAL8_GW_PRICE_BUFFER_PERCENT,
                'desc_tip' => true,
                'custom_attributes' => array(
                    'min' => 0,
                    'max' => 10,
                    'step' => '0.5',
                ),
            ),
            'instructions' => array(
                'title' => __('Payment Instructions', 'real8-gateway'),
                'type' => 'textarea',
                'description' => __('Instructions shown on thank-you page and in emails. Use {amount}, {address}, {memo} as placeholders.', 'real8-gateway'),
                'default' => __("Send exactly {amount} REAL8 to:\n\nAddress: {address}\nMemo (TEXT): {memo}\n\nIMPORTANT: You must include the memo exactly as shown, or your payment cannot be matched to your order.", 'real8-gateway'),
            ),
        );
    }

    /**
     * Validate merchant address field
     */
    public function validate_merchant_address_field($key, $value) {
        if (empty($value)) {
            return '';
        }

        $value = strtoupper(trim($value));

        if (!$this->stellar_api->validate_stellar_address($value)) {
            WC_Admin_Settings::add_error(__('Invalid Stellar address. Must be 56 characters starting with G.', 'real8-gateway'));
            return $this->get_option($key);
        }

        // Check trustline
        if (!$this->stellar_api->check_real8_trustline($value)) {
            WC_Admin_Settings::add_error(__('This Stellar address does not have a REAL8 trustline. Please add the trustline first.', 'real8-gateway'));
            return $this->get_option($key);
        }

        return $value;
    }

    /**
     * Check if gateway is available
     */
    public function is_available() {
        if ($this->enabled !== 'yes') {
            return false;
        }

        if (empty($this->merchant_address)) {
            return false;
        }

        return true;
    }

    /**
     * Process the payment
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        // Calculate REAL8 amount
        $order_total = $order->get_total();
        $calculation = $this->stellar_api->calculate_real8_amount($order_total, true);

        if (is_wp_error($calculation)) {
            wc_add_notice(__('Unable to process REAL8 payment. Please try again or choose another payment method.', 'real8-gateway'), 'error');
            return array(
                'result' => 'fail',
            );
        }

        // Generate unique memo
        $memo = $this->stellar_api->generate_payment_memo($order_id);

        // Calculate expiry time
        $expires_at = gmdate('Y-m-d H:i:s', time() + ($this->payment_timeout * 60));

        // Save payment record
        global $wpdb;
        $table = $wpdb->prefix . 'real8_payments';

        $wpdb->replace($table, array(
            'order_id' => $order_id,
            'memo' => $memo,
            'amount_real8' => $calculation['real8_amount'],
            'amount_usd' => $order_total,
            'real8_price' => $calculation['price_per_real8'],
            'merchant_address' => $this->merchant_address,
            'status' => 'pending',
            'expires_at' => $expires_at,
            'created_at' => current_time('mysql', true),
        ), array('%d', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s'));

        // Save payment details to order meta
        $order->update_meta_data('_real8_payment_memo', $memo);
        $order->update_meta_data('_real8_payment_amount', $calculation['real8_amount']);
        $order->update_meta_data('_real8_payment_price', $calculation['price_per_real8']);
        $order->update_meta_data('_real8_payment_expires', $expires_at);
        $order->update_meta_data('_real8_merchant_address', $this->merchant_address);

        // Update order status to pending payment
        $order->update_status('pending', __('Awaiting REAL8 payment', 'real8-gateway'));
        $order->save();

        // Empty cart
        WC()->cart->empty_cart();

        // Return success and redirect to thank you page
        return array(
            'result' => 'success',
            'redirect' => $this->get_return_url($order),
        );
    }

    /**
     * Output payment instructions on thank you page
     */
    public function thankyou_page($order_id) {
        $order = wc_get_order($order_id);

        if (!$order || $order->get_payment_method() !== $this->id) {
            return;
        }

        // Get payment details
        $memo = $order->get_meta('_real8_payment_memo');
        $amount = $order->get_meta('_real8_payment_amount');
        $expires = $order->get_meta('_real8_payment_expires');
        $merchant = $order->get_meta('_real8_merchant_address');

        if (!$memo || !$amount) {
            return;
        }

        // Check if already paid
        global $wpdb;
        $table = $wpdb->prefix . 'real8_payments';
        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE order_id = %d",
            $order_id
        ));

        $status = $payment ? $payment->status : 'pending';

        // Include the payment instructions template
        include REAL8_GATEWAY_PLUGIN_DIR . 'includes/templates/payment-instructions.php';
    }

    /**
     * Add payment instructions to emails
     */
    public function email_instructions($order, $sent_to_admin, $plain_text = false) {
        if (!$order || $order->get_payment_method() !== $this->id) {
            return;
        }

        if ($order->has_status('pending')) {
            $memo = $order->get_meta('_real8_payment_memo');
            $amount = $order->get_meta('_real8_payment_amount');
            $merchant = $order->get_meta('_real8_merchant_address');

            if ($memo && $amount) {
                $instructions = $this->get_option('instructions');
                $instructions = str_replace('{amount}', number_format($amount, 7), $instructions);
                $instructions = str_replace('{address}', $merchant, $instructions);
                $instructions = str_replace('{memo}', $memo, $instructions);

                if ($plain_text) {
                    echo "\n" . esc_html($instructions) . "\n\n";
                } else {
                    echo '<h2>' . esc_html__('Payment Instructions', 'real8-gateway') . '</h2>';
                    echo '<div style="background: #f8f8f8; padding: 15px; margin-bottom: 20px; border-radius: 5px;">';
                    echo '<pre style="white-space: pre-wrap; font-family: monospace; margin: 0;">' . esc_html($instructions) . '</pre>';
                    echo '</div>';
                }
            }
        }
    }

    /**
     * Enqueue scripts for payment page
     */
    public function enqueue_scripts() {
        if (is_checkout() || is_wc_endpoint_url('order-received')) {
            wp_enqueue_style(
                'real8-gateway-checkout',
                REAL8_GATEWAY_PLUGIN_URL . 'assets/css/checkout.css',
                array(),
                REAL8_GATEWAY_VERSION
            );

            wp_enqueue_script(
                'real8-gateway-checkout',
                REAL8_GATEWAY_PLUGIN_URL . 'assets/js/checkout.js',
                array('jquery'),
                REAL8_GATEWAY_VERSION,
                true
            );

            wp_localize_script('real8-gateway-checkout', 'real8_gateway', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('real8_gateway_nonce'),
                'check_interval' => 15000, // Check every 15 seconds
                'strings' => array(
                    'checking' => __('Checking payment status...', 'real8-gateway'),
                    'paid' => __('Payment received! Redirecting...', 'real8-gateway'),
                    'expired' => __('Payment window expired', 'real8-gateway'),
                    'error' => __('Error checking payment', 'real8-gateway'),
                ),
            ));
        }
    }

    /**
     * AJAX handler to check payment status
     */
    public function ajax_check_payment_status() {
        check_ajax_referer('real8_gateway_nonce', 'nonce');

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;

        if (!$order_id) {
            wp_send_json_error(array('message' => 'Invalid order'));
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => 'Order not found'));
        }

        // Get payment record
        global $wpdb;
        $table = $wpdb->prefix . 'real8_payments';
        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE order_id = %d",
            $order_id
        ));

        if (!$payment) {
            wp_send_json_error(array('message' => 'Payment not found'));
        }

        // Check if expired
        $expires_at = strtotime($payment->expires_at);
        if (time() > $expires_at && $payment->status === 'pending') {
            $wpdb->update($table, array('status' => 'expired'), array('order_id' => $order_id));
            $order->update_status('failed', __('REAL8 payment expired', 'real8-gateway'));

            wp_send_json_success(array(
                'status' => 'expired',
                'message' => __('Payment window has expired', 'real8-gateway'),
            ));
        }

        // Return current status
        wp_send_json_success(array(
            'status' => $payment->status,
            'tx_hash' => $payment->stellar_tx_hash,
            'expires_in' => max(0, $expires_at - time()),
        ));
    }

    /**
     * AJAX handler to refresh REAL8 price
     */
    public function ajax_refresh_price() {
        check_ajax_referer('real8_gateway_nonce', 'nonce');

        $price = $this->stellar_api->get_real8_price(true);

        if (is_wp_error($price)) {
            wp_send_json_error(array('message' => $price->get_error_message()));
        }

        wp_send_json_success(array(
            'price' => $price,
            'formatted' => '$' . number_format($price, 6),
        ));
    }
}
