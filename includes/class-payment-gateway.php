<?php
/**
 * WooCommerce Stellar Payment Gateway
 *
 * Multi-token payment gateway supporting XLM, REAL8, wREAL8, USDC, EURC, SLVR, GOLD
 *
 * @package REAL8_Gateway
 * @version 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stellar Payment Gateway class
 */
class WC_Gateway_REAL8 extends WC_Payment_Gateway {

    /**
     * Stellar API instance
     */
    private $stellar_api;

    /**
     * Amount tolerance settings (helps reduce false negatives from rounding/fees)
     * - percent: allows small underpayment as a percentage of expected
     * - min: minimum absolute tolerance in token units
     */
    private $amount_tolerance_percent = 0.0;
    private $amount_tolerance_min = '0.0000000';

    /**
     * Constructor
     */
    public function __construct() {
        $this->id = 'real8_payment';
        $this->icon = '';
        $this->has_fields = true; // Enable payment fields for token selector
        $this->method_title = __('REAL8 Payments', 'real8-gateway');
        $this->method_description = __('Accept payments in Stellar tokens (XLM, REAL8, USDC, EURC, SLVR, GOLD)', 'real8-gateway');

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
        $this->accepted_tokens = $this->get_option('accepted_tokens', array('REAL8'));
        $this->payment_timeout = $this->get_option('payment_timeout', REAL8_GW_PAYMENT_TIMEOUT_MINUTES);
        $this->price_buffer = $this->get_option('price_buffer', REAL8_GW_PRICE_BUFFER_PERCENT);

        // Tolerance defaults are stored in options so the monitor can read them without a gateway instance.
        $this->amount_tolerance_percent = (float) $this->get_option(
            'amount_tolerance_percent',
            (float) get_option('real8_gateway_amount_tolerance_percent', 1.0)
        );
        $this->amount_tolerance_min = (string) $this->get_option(
            'amount_tolerance_min',
            (string) get_option('real8_gateway_amount_tolerance_min', '0.0000001')
        );

        // Ensure accepted_tokens is an array
        if (!is_array($this->accepted_tokens)) {
            $this->accepted_tokens = array($this->accepted_tokens);
        }

        // Initialize Stellar API
        $this->stellar_api = REAL8_Stellar_Payment_API::get_instance();

        // Hooks
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
        add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // AJAX handlers        // WooCommerce WC-AJAX handlers (bypasses /wp-admin/admin ajax.php blocks)
        add_action('wc_ajax_real8_check_payment_status', array($this, 'ajax_check_payment_status'));
        add_action('wc_ajax_nopriv_real8_check_payment_status', array($this, 'ajax_check_payment_status'));
        add_action('wc_ajax_stellar_get_token_prices', array($this, 'ajax_get_token_prices'));
        add_action('wc_ajax_nopriv_stellar_get_token_prices', array($this, 'ajax_get_token_prices'));

    }

    /**
     * Initialize Gateway Settings Form Fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'real8-gateway'),
                'type' => 'checkbox',
                'label' => __('Habilitar Pagos REAL8 / Stellar', 'real8-gateway'),
                'default' => 'no',
            ),
            'title' => array(
                'title' => __('Title', 'real8-gateway'),
                'type' => 'text',
                'description' => __('Payment method title displayed to customers', 'real8-gateway'),
                'default' => __('Pay with Stellar', 'real8-gateway'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'real8-gateway'),
                'type' => 'textarea',
                'description' => __('Payment method description displayed during checkout', 'real8-gateway'),
                'default' => __('Pay with Stellar tokens (XLM, REAL8, USDC, EURC, SLVR, GOLD). Fast, secure, and low fees.', 'real8-gateway'),
                'desc_tip' => true,
            ),
            'accepted_tokens' => array(
                'title' => __('Criptomonedas aceptadas', 'real8-gateway'),
                'type' => 'multiselect',
                'class' => 'wc-enhanced-select',
                'description' => __('Select which Stellar tokens to accept as payment. You must have trustlines for non-XLM tokens.', 'real8-gateway'),
                'options' => REAL8_Token_Registry::get_token_options(),
                'default' => array('REAL8'),
                'desc_tip' => true,
                'custom_attributes' => array(
                    'data-placeholder' => __('Select tokens...', 'real8-gateway'),
                ),
            ),
            'merchant_address' => array(
                'title' => __('Your Stellar Public Key', 'real8-gateway'),
                'type' => 'merchant_address',
                'description' => __('Your Stellar public key (starts with G) where payments will be received.', 'real8-gateway'),
                'default' => '',
                'desc_tip' => false,
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
                'description' => __('Buffer percentage to account for price volatility. Default: 2%.', 'real8-gateway'),
                'default' => REAL8_GW_PRICE_BUFFER_PERCENT,
                'desc_tip' => true,
                'custom_attributes' => array(
                    'min' => 0,
                    'max' => 10,
                    'step' => '0.5',
                ),
            ),

            // Amount tolerance (optional)
            'amount_tolerance_percent' => array(
                'title'       => __('Underpayment Tolerance (%)', 'real8-gateway'),
                'type'        => 'number',
                'description' => __('Allows a small underpayment (percentage of expected token amount) to avoid false negatives caused by rounding/fees. Set 0 to require the exact amount.', 'real8-gateway'),
                'default'     => (float) get_option('real8_gateway_amount_tolerance_percent', 1.0),
                'desc_tip'    => true,
                'custom_attributes' => array(
                    'min'  => 0,
                    'max'  => 10,
                    'step' => '0.1',
                ),
            ),
            'amount_tolerance_min' => array(
                'title'       => __('Minimum Tolerance (token units)', 'real8-gateway'),
                'type'        => 'text',
                'description' => __('Minimum absolute tolerance in the selected token (e.g. 0.05). This is applied if it is larger than the percent tolerance.', 'real8-gateway'),
                'default'     => (string) get_option('real8_gateway_amount_tolerance_min', '0.0000001'),
                'desc_tip'    => true,
            ),
        );
    }

    /**
     * Persist tolerance settings for background monitor usage.
     */
    public function process_admin_options() {
        $saved = parent::process_admin_options();

        // Mirror into standalone options so the cron monitor can read them without depending on gateway settings load.
        $p = (float) $this->get_option('amount_tolerance_percent', 1.0);
        $m = (string) $this->get_option('amount_tolerance_min', '0.0000001');
        update_option('real8_gateway_amount_tolerance_percent', $p);
        update_option('real8_gateway_amount_tolerance_min', $m);

        return $saved;
    }

    /**
     * Validate merchant address field - now checks all enabled token trustlines
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

        if (empty($this->accepted_tokens)) {
            return false;
        }

        return true;
    }

    /**
     * Display payment fields at checkout (token selector)
     */
    public function payment_fields() {
        // Show description
        if ($this->description) {
            echo '<p>' . wp_kses_post($this->description) . '</p>';
        }

        // Get prices for enabled tokens
        $prices = $this->stellar_api->get_all_token_prices($this->accepted_tokens);
        $cart_total = WC()->cart ? WC()->cart->get_total('edit') : 0;

        echo '<div class="stellar-token-selector" id="stellar-token-selector">';
        echo '<p class="form-row form-row-wide">';
        echo '<label>' . esc_html__('Select Payment Token', 'real8-gateway') . ' <span class="required">*</span></label>';
        echo '</p>';

        echo '<div class="stellar-token-options">';

        $first = true;
        foreach ($this->accepted_tokens as $token_code) {
            $token = REAL8_Token_Registry::get_token($token_code);
            if (!$token) continue;

            $price = isset($prices[$token_code]) ? $prices[$token_code] : null;
            $amount = ($price && $cart_total > 0) ? round($cart_total / $price, 7) : null;

            $checked = $first ? 'checked' : '';
            $first = false;

            echo '<label class="stellar-token-option' . ($checked ? ' selected' : '') . '">';
            echo '<input type="radio" name="stellar_selected_token" value="' . esc_attr($token_code) . '" ' . $checked . ' required />';
            echo '<span class="stellar-token-info">';
            echo '<span class="stellar-token-name" style="color: ' . esc_attr($token['color']) . ';">' . esc_html($token_code) . '</span>';
            echo '<span class="stellar-token-fullname">' . esc_html($token['name']) . '</span>';
            if ($amount !== null) {
                echo '<span class="stellar-token-amount">~' . esc_html(number_format($amount, 4)) . ' ' . esc_html($token_code) . '</span>';
            }
            echo '</span>';
            echo '</label>';
        }

        echo '</div>';

        // Hidden field for order total (for JS calculations)
        echo '<input type="hidden" id="stellar-cart-total" value="' . esc_attr($cart_total) . '" />';

        echo '</div>';

        // Add inline styles for token selector
        $this->output_token_selector_styles();
    }

    /**
     * Output inline styles for token selector
     */
    private function output_token_selector_styles() {
        ?>
        <style>
        .stellar-token-options {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }
        .stellar-token-option {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            border: 2px solid #ddd;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
            background: #fff;
            flex: 1;
            min-width: 140px;
        }
        .stellar-token-option:hover {
            border-color: #007cba;
            background: #f8f9fa;
        }
        .stellar-token-option.selected,
        .stellar-token-option:has(input:checked) {
            border-color: #007cba;
            background: #e8f4fc;
        }
        .stellar-token-option input[type="radio"] {
            margin-right: 10px;
        }
        .stellar-token-info {
            display: flex;
            flex-direction: column;
        }
        .stellar-token-name {
            font-weight: bold;
            font-size: 1.1em;
        }
        .stellar-token-fullname {
            font-size: 0.85em;
            color: #666;
        }
        .stellar-token-amount {
            font-size: 0.9em;
            color: #007cba;
            margin-top: 4px;
        }
        </style>
        <?php
    }

    /**
     * Validate payment fields
     */
    public function validate_fields() {
        if (empty($_POST['stellar_selected_token'])) {
            wc_add_notice(__('Please select a payment token.', 'real8-gateway'), 'error');
            return false;
        }

        $selected_token = sanitize_text_field($_POST['stellar_selected_token']);

        if (!in_array($selected_token, $this->accepted_tokens)) {
            wc_add_notice(__('Invalid payment token selected.', 'real8-gateway'), 'error');
            return false;
        }

        return true;
    }

    /**
     * Process the payment
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        // If guest, require a valid order_key to avoid order enumeration
        if ($order && !is_user_logged_in()) {
            $real_key = $order->get_order_key();
            if (!$order_key || !hash_equals($real_key, $order_key)) {
                wp_send_json_error(array('message' => 'Invalid order'), 403);
            }
        }
        global $wpdb;
        $table = $wpdb->prefix . 'real8_payments';

        // Get selected token
        $selected_token = isset($_POST['stellar_selected_token'])
            ? sanitize_text_field($_POST['stellar_selected_token'])
            : 'REAL8';

        // Validate token is enabled
        if (!in_array($selected_token, $this->accepted_tokens)) {
            wc_add_notice(__('Invalid payment token.', 'real8-gateway'), 'error');
            return array('result' => 'fail');
        }

        // Get token details
        $token = REAL8_Token_Registry::get_token($selected_token);
        if (!$token) {
            wc_add_notice(__('Invalid token configuration.', 'real8-gateway'), 'error');
            return array('result' => 'fail');
        }

        // Check if there's an existing pending payment for this order
        $existing_payment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE order_id = %d AND status = 'pending'",
            $order_id
        ));

        // Check if existing payment is still valid (not expired and same token)
        $reuse_existing = false;
        if ($existing_payment) {
            $expires_at_ts = strtotime($existing_payment->expires_at);
            $same_token = ($existing_payment->asset_code === $selected_token);
            $not_expired = (time() < $expires_at_ts);

            if ($same_token && $not_expired) {
                $reuse_existing = true;
            }
        }

        if ($reuse_existing) {
            // Reuse existing memo and payment details
            $memo = $existing_payment->memo;
            $expires_at = $existing_payment->expires_at;
            $token_amount = $existing_payment->amount_token;
            $token_price = $existing_payment->token_price;

            // Add order note about returning customer
            $order->add_order_note(sprintf(
                __('Customer returned to pay. Reusing existing payment details: %1$s %2$s, Memo: %3$s, Expires: %4$s', 'real8-gateway'),
                number_format($token_amount, 7),
                $selected_token,
                $memo,
                $expires_at
            ));
        } else {
            // Calculate token amount fresh
            $order_total = $order->get_total();
            $calculation = $this->stellar_api->calculate_token_amount($order_total, $selected_token, true);

            if (is_wp_error($calculation)) {
                wc_add_notice(__('Unable to calculate payment amount. Please try again.', 'real8-gateway'), 'error');
                return array('result' => 'fail');
            }

            // Check if we have an existing memo we should reuse (even if token changed)
            $existing_memo = $order->get_meta('_stellar_payment_memo');
            if (!empty($existing_memo)) {
                $memo = $existing_memo;
            } else {
                // Generate unique memo
                $memo = $this->stellar_api->generate_payment_memo($order_id);
            }

            // Calculate expiry time
            $expires_at = gmdate('Y-m-d H:i:s', time() + ($this->payment_timeout * 60));
            $token_amount = $calculation['token_amount'];
            $token_price = $calculation['price_per_token'];

            // Save payment record to database (replace any existing)
            $wpdb->replace($table, array(
                'order_id' => $order_id,
                'memo' => $memo,
                'asset_code' => $selected_token,
                'asset_issuer' => $token['issuer'],
                'amount_token' => $token_amount,
                'amount_usd' => $order_total,
                'token_price' => $token_price,
                'merchant_address' => $this->merchant_address,
                'status' => 'pending',
                'expires_at' => $expires_at,
                'created_at' => current_time('mysql', true),
            ), array('%d', '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s'));

            // Add detailed order note for payment initiation
            $order->add_order_note(sprintf(
                __('Stellar payment initiated: %1$s %2$s (@ $%3$s/%2$s). Memo: %4$s. Address: %5$s. Expires: %6$s', 'real8-gateway'),
                number_format($token_amount, 7),
                $selected_token,
                number_format($token_price, 6),
                $memo,
                $this->merchant_address,
                $expires_at
            ));

            // Save payment details to order meta
            $order->update_meta_data('_stellar_payment_memo', $memo);
            $order->update_meta_data('_stellar_asset_code', $selected_token);
            $order->update_meta_data('_stellar_asset_issuer', $token['issuer']);
            $order->update_meta_data('_stellar_payment_amount', $token_amount);
            $order->update_meta_data('_stellar_payment_price', $token_price);
            $order->update_meta_data('_stellar_payment_expires', $expires_at);
            $order->update_meta_data('_stellar_merchant_address', $this->merchant_address);
        }

        // Update order status to pending payment (if not already)
        if (!$order->has_status('pending')) {
            $order->update_status('pending', sprintf(
                /* translators: %s: token code */
                __('Awaiting %s payment', 'real8-gateway'),
                $selected_token
            ));
        }
        $order->save();

        // Empty cart (if not already empty)
        if (WC()->cart && !WC()->cart->is_empty()) {
            WC()->cart->empty_cart();
        }

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
        $memo = $order->get_meta('_stellar_payment_memo');
        $amount = $order->get_meta('_stellar_payment_amount');
        $expires = $order->get_meta('_stellar_payment_expires');
        $merchant = $order->get_meta('_stellar_merchant_address');
        $asset_code = $order->get_meta('_stellar_asset_code');
        $asset_issuer = $order->get_meta('_stellar_asset_issuer');

        // Fallback for old orders
        if (empty($asset_code)) {
            $asset_code = 'REAL8';
            $asset_issuer = REAL8_GW_ASSET_ISSUER;
            $memo = $order->get_meta('_real8_payment_memo');
            $amount = $order->get_meta('_real8_payment_amount');
            $expires = $order->get_meta('_real8_payment_expires');
            $merchant = $order->get_meta('_real8_merchant_address');
        }

        if (!$memo || !$amount) {
            return;
        }

        // Get token info
        $token = REAL8_Token_Registry::get_token($asset_code);
        $is_native = REAL8_Token_Registry::is_native($asset_code);

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
            $memo = $order->get_meta('_stellar_payment_memo');
            $amount = $order->get_meta('_stellar_payment_amount');
            $merchant = $order->get_meta('_stellar_merchant_address');
            $asset_code = $order->get_meta('_stellar_asset_code');

            // Fallback for old orders
            if (empty($asset_code)) {
                $asset_code = 'REAL8';
                $memo = $order->get_meta('_real8_payment_memo');
                $amount = $order->get_meta('_real8_payment_amount');
                $merchant = $order->get_meta('_real8_merchant_address');
            }

            if ($memo && $amount) {
                if ($plain_text) {
                    echo "\n";
                    /* translators: %1$s: amount, %2$s: token code, %3$s: address, %4$s: memo */
                    printf(
                        esc_html__("Send exactly %1\$s %2\$s to:\n\nAddress: %3\$s\nMemo (TEXT): %4\$s\n\nIMPORTANT: Include the memo exactly as shown.", 'real8-gateway'),
                        number_format($amount, 7),
                        $asset_code,
                        $merchant,
                        $memo
                    );
                    echo "\n\n";
                } else {
                    echo '<h2>' . esc_html__('Payment Instructions', 'real8-gateway') . '</h2>';
                    echo '<div style="background: #f8f8f8; padding: 15px; margin-bottom: 20px; border-radius: 5px; font-family: monospace;">';
                    echo '<p><strong>' . esc_html__('Amount:', 'real8-gateway') . '</strong> ' . esc_html(number_format($amount, 7)) . ' ' . esc_html($asset_code) . '</p>';
                    echo '<p><strong>' . esc_html__('Address:', 'real8-gateway') . '</strong> ' . esc_html($merchant) . '</p>';
                    echo '<p><strong>' . esc_html__('Memo (TEXT):', 'real8-gateway') . '</strong> ' . esc_html($memo) . '</p>';
                    echo '<p style="color: #d63638;"><strong>' . esc_html__('IMPORTANT:', 'real8-gateway') . '</strong> ' . esc_html__('Include the memo exactly as shown.', 'real8-gateway') . '</p>';
                    echo '</div>';
                }
            }
        }
    }

    /**
     * Enqueue scripts for payment page
     */
    public function enqueue_scripts() {
        if (is_checkout() || is_wc_endpoint_url('order-received') || is_wc_endpoint_url('order-pay')) {
            wp_enqueue_style(
                'stellar-gateway-checkout',
                REAL8_GATEWAY_PLUGIN_URL . 'assets/css/checkout.css',
                array(),
                REAL8_GATEWAY_VERSION
            );

            wp_enqueue_script(
                'stellar-gateway-checkout',
                REAL8_GATEWAY_PLUGIN_URL . 'assets/js/checkout.js',
                array('jquery'),
                REAL8_GATEWAY_VERSION,
                true
            );

            wp_localize_script('stellar-gateway-checkout', 'real8_gateway', array(                'wc_ajax_url' => add_query_arg('wc-ajax', 'real8_check_payment_status', home_url('/')),                'wc_ajax_prices_url' => add_query_arg('wc-ajax', 'stellar_get_token_prices', home_url('/')),
                'home_url' => home_url('/'),
                'rest_check_url' => rest_url('real8-gateway/v1/check'),
                'order_key' => isset($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : '',
                'nonce' => wp_create_nonce('stellar_gateway_nonce'),
                'check_interval' => 15000,
                'accepted_tokens' => $this->accepted_tokens,
                'strings' => array(
                    'checking' => __('Checking payment status...', 'real8-gateway'),
                    'paid' => __('Payment received! Redirecting...', 'real8-gateway'),
                    'expired' => __('Payment window expired', 'real8-gateway'),
                    'error' => __('Error checking payment', 'real8-gateway'),
                    'manual_check' => __('Comprobar pago ahora', 'real8-gateway'),
                    'manual_checking' => __('Comprobando ahora...', 'real8-gateway'),
                    'manual_checked' => __('Verificación completada.', 'real8-gateway'),
                ),
            ));
        }
    }

    /**
     * AJAX handler to check payment status
     */
    public function ajax_check_payment_status() {
        // WC-AJAX endpoint ONLY (no admin ajax). Nonce is optional; validate if present.
        $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['nonce'])) : '';
        if ($nonce && !wp_verify_nonce($nonce, 'stellar_gateway_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'), 403);
        }

        $order_id  = isset($_REQUEST['order_id']) ? absint($_REQUEST['order_id']) : 0;
        $order_key = isset($_REQUEST['order_key']) ? sanitize_text_field(wp_unslash($_REQUEST['order_key'])) : '';
        $force     = isset($_REQUEST['force']) ? (int) $_REQUEST['force'] : 0;

        if (!$order_id) {
            wp_send_json_error(array('message' => 'Invalid order'), 400);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => 'Order not found'), 404);
        }

        // Security: require a valid order key for guest checks.
        if (!$order_key || !hash_equals($order->get_order_key(), $order_key)) {
            wp_send_json_error(array('message' => 'Invalid order'), 403);
        }

        // Ensure this order uses this gateway.
        if ($order->get_payment_method() !== 'real8_payment') {
            wp_send_json_error(array('message' => 'Invalid order'), 400);
        }

        // Get payment record
        global $wpdb;
        $table = $wpdb->prefix . 'real8_payments';
        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE order_id = %d",
            $order_id
        ));

        if (!$payment) {
            wp_send_json_error(array('message' => 'Payment not found'), 404);
        }

        // Expiry handling (keep DB + order consistent)
        $expires_at = strtotime($payment->expires_at);
        if ($expires_at && time() > $expires_at && $payment->status === 'pending') {
            $wpdb->update($table, array('status' => 'expired'), array('order_id' => $order_id));

            $asset_code = isset($payment->asset_code) ? $payment->asset_code : 'REAL8';
            $order->update_status('failed', sprintf(
                /* translators: %s: token code */
                __('%s payment expired', 'real8-gateway'),
                $asset_code
            ));

            wp_send_json_success(array(
                'status' => 'expired',
                'message' => __('Payment window has expired', 'real8-gateway'),
                'expires_in' => 0,
            ));
        }

        // Optional: trigger a manual on-demand check (throttled) to confirm faster after user pays.
        $should_check = $force || $payment->status === 'pending';
        $did_check = false;
        $check_error = '';

        if ($should_check) {
            $lock_key = 'real8_manual_check_lock_' . $order_id;
            if (!get_transient($lock_key)) {
                set_transient($lock_key, 1, 20); // prevent hammering the Stellar API
                $did_check = true;

                if (class_exists('REAL8_Payment_Monitor')) {
                    $monitor = REAL8_Payment_Monitor::get_instance();
                    $result = $monitor->manual_check_order($order_id);

                    if (is_wp_error($result)) {
                        $check_error = $result->get_error_message();
                    }
                } else {
                    $check_error = 'Payment monitor not available';
                }
            }
        }

        // Re-fetch after a manual check attempt (so we return current state)
        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE order_id = %d",
            $order_id
        ));

        $expires_at = strtotime($payment->expires_at);
        $response = array(
            'status' => $payment->status,
            'tx_hash' => isset($payment->stellar_tx_hash) ? $payment->stellar_tx_hash : '',
            'expires_in' => $expires_at ? max(0, $expires_at - time()) : 0,
            'checked' => $did_check ? 1 : 0,
        );

        if ($check_error) {
            $response['check_error'] = $check_error;
        }

        wp_send_json_success($response);
    }



    /**
     * AJAX handler to get token prices
     */
    public function ajax_get_token_prices() {
        $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['nonce'])) : '';
        if ($nonce && !wp_verify_nonce($nonce, 'stellar_gateway_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'), 403);
        }

        $tokens = isset($_REQUEST['tokens']) ? array_map('sanitize_text_field', (array) $_POST['tokens']) : $this->accepted_tokens;
        $prices = $this->stellar_api->get_all_token_prices($tokens);

        wp_send_json_success(array(
            'prices' => $prices,
        ));
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if ('woocommerce_page_wc-settings' !== $hook) {
            return;
        }

        if (!isset($_GET['section']) || $_GET['section'] !== 'real8_payment') {
            return;
        }

        wp_enqueue_style(
            'stellar-gateway-admin',
            REAL8_GATEWAY_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            REAL8_GATEWAY_VERSION
        );

        wp_enqueue_script(
            'stellar-gateway-admin',
            REAL8_GATEWAY_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            REAL8_GATEWAY_VERSION,
            true
        );

        wp_localize_script('stellar-gateway-admin', 'stellar_admin', array(            'nonce' => wp_create_nonce('stellar_admin_nonce'),
            'strings' => array(
                'checking' => __('Checking wallet status...', 'real8-gateway'),
                'valid' => __('Valid', 'real8-gateway'),
                'invalid' => __('Invalid', 'real8-gateway'),
            ),
        ));
    }

    /**
     * Generate custom field HTML for merchant address
     */
    public function generate_merchant_address_html($key, $data) {
        $field_key = $this->get_field_key($key);
        $defaults = array(
            'title' => '',
            'description' => '',
            'default' => '',
        );

        $data = wp_parse_args($data, $defaults);
        $value = $this->get_option($key);

        // Check wallet status for all enabled tokens
        $wallet_status = $this->check_wallet_status($value);

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?></label>
            </th>
            <td class="forminp">
                <fieldset>
                    <legend class="screen-reader-text"><span><?php echo wp_kses_post($data['title']); ?></span></legend>
                    <input type="text"
                           class="input-text regular-input"
                           name="<?php echo esc_attr($field_key); ?>"
                           id="<?php echo esc_attr($field_key); ?>"
                           style="width: 450px; font-family: monospace;"
                           value="<?php echo esc_attr($value); ?>"
                           placeholder="GXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX"
                           maxlength="56" />

                    <div id="stellar-wallet-status" class="stellar-wallet-status" style="margin-top: 10px;">
                        <?php echo $this->render_wallet_status($wallet_status); ?>
                    </div>

                    <p class="description">
                        <?php echo wp_kses_post($data['description']); ?>
                    </p>

                    <div class="stellar-wallet-help" style="margin-top: 15px; padding: 15px; background: #f8f9fa; border-left: 4px solid #007cba; border-radius: 4px;">
                        <p style="margin: 0 0 10px 0;">
                            <?php
                            printf(
                                /* translators: %s: URL to wallet app */
                                esc_html__('If you don\'t have one, you can create a REAL8/Stellar Wallet at %s', 'real8-gateway'),
                                '<a href="https://app.real8.org/" target="_blank">https://app.real8.org/</a>'
                            );
                            ?>
                        </p>
                        <p style="margin: 0 0 10px 0;">
                            <?php esc_html_e('Once you have your Wallet, you can paste your public key (starts with G) above.', 'real8-gateway'); ?>
                        </p>
                        <p style="margin: 0; color: #d63638; font-weight: bold;">
                            <?php esc_html_e('VERY IMPORTANT: If this is your first time creating a REAL8/Stellar account, remember to keep your Secret Key (it starts with S) in a safe place. If you lose it, you will not be able to recover your funds.', 'real8-gateway'); ?>
                        </p>
                    </div>
                </fieldset>
            </td>
        </tr>
        <?php

        return ob_get_clean();
    }

    /**
     * Check wallet status on Stellar network - now checks all enabled tokens
     */
    private function check_wallet_status($address) {
        $status = array(
            'exists' => false,
            'funded' => false,
            'xlm_balance' => 0,
            'token_trustlines' => array(),
            'error' => null,
        );

        if (empty($address)) {
            $status['error'] = 'no_address';
            return $status;
        }

        if (!$this->stellar_api->validate_stellar_address($address)) {
            $status['error'] = 'invalid_format';
            return $status;
        }

        // Fetch account from Horizon
        $url = REAL8_GW_HORIZON_URL . '/accounts/' . $address;
        $response = wp_remote_get($url, array('timeout' => 10));

        if (is_wp_error($response)) {
            $status['error'] = 'network_error';
            return $status;
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code === 404) {
            $status['error'] = 'not_found';
            return $status;
        }

        if ($code !== 200) {
            $status['error'] = 'api_error';
            return $status;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data || !isset($data['balances'])) {
            $status['error'] = 'invalid_response';
            return $status;
        }

        $status['exists'] = true;
        $status['funded'] = true;

        // Build a map of balances
        $balances_map = array();
        foreach ($data['balances'] as $balance) {
            if ($balance['asset_type'] === 'native') {
                $status['xlm_balance'] = (float) $balance['balance'];
                $balances_map['XLM'] = (float) $balance['balance'];
            } elseif (isset($balance['asset_code']) && isset($balance['asset_issuer'])) {
                $key = $balance['asset_code'] . ':' . $balance['asset_issuer'];
                $balances_map[$key] = (float) $balance['balance'];
            }
        }

        // Check trustlines for all supported tokens
        foreach (REAL8_Token_Registry::get_all_tokens() as $token_code => $token) {
            if ($token['is_native']) {
                $status['token_trustlines'][$token_code] = array(
                    'has_trustline' => true,
                    'balance' => isset($balances_map['XLM']) ? $balances_map['XLM'] : 0,
                );
            } else {
                $key = $token['code'] . ':' . $token['issuer'];
                $has_trustline = isset($balances_map[$key]);
                $status['token_trustlines'][$token_code] = array(
                    'has_trustline' => $has_trustline,
                    'balance' => $has_trustline ? $balances_map[$key] : 0,
                );
            }
        }

        return $status;
    }

    /**
     * Render wallet status HTML - now shows status for all enabled tokens
     */
    private function render_wallet_status($status) {
        if ($status['error'] === 'no_address') {
            return '<div class="stellar-status-box stellar-status-warning">
                <span class="dashicons dashicons-warning"></span>
                <span>' . esc_html__('No wallet address configured. Enter your Stellar public key above.', 'real8-gateway') . '</span>
            </div>';
        }

        if ($status['error'] === 'invalid_format') {
            return '<div class="stellar-status-box stellar-status-error">
                <span class="dashicons dashicons-no"></span>
                <span>' . esc_html__('Invalid address format. Stellar addresses are 56 characters starting with G.', 'real8-gateway') . '</span>
            </div>';
        }

        if ($status['error'] === 'not_found') {
            return '<div class="stellar-status-box stellar-status-error">
                <span class="dashicons dashicons-no"></span>
                <span>' . esc_html__('Wallet not found on Stellar network. Make sure the account exists and is funded with at least 1 XLM.', 'real8-gateway') . '</span>
            </div>';
        }

        if ($status['error'] === 'network_error' || $status['error'] === 'api_error') {
            return '<div class="stellar-status-box stellar-status-warning">
                <span class="dashicons dashicons-warning"></span>
                <span>' . esc_html__('Could not verify wallet. Network error - please try again.', 'real8-gateway') . '</span>
            </div>';
        }

        // Account exists - show detailed status
        $html = '<div class="stellar-status-checks">';

        // Account exists check
        $html .= '<div class="stellar-check stellar-check-success">
            <span class="dashicons dashicons-yes-alt"></span>
            <span>' . esc_html__('Account exists on Stellar/REAL8', 'real8-gateway') . '</span>
        </div>';

        // Get REAL8 balance
        $real8_balance = isset($status['token_trustlines']['REAL8']['balance']) ? $status['token_trustlines']['REAL8']['balance'] : 0;

        // XLM and REAL8 balance check
        if ($status['xlm_balance'] >= 1.5) {
            $html .= '<div class="stellar-check stellar-check-success">
                <span class="dashicons dashicons-yes-alt"></span>
                <span>' . sprintf(esc_html__('Funded with %s XLM and %s REAL8', 'real8-gateway'), number_format($status['xlm_balance'], 2), number_format($real8_balance, 2)) . '</span>
            </div>';
        } else {
            $html .= '<div class="stellar-check stellar-check-warning">
                <span class="dashicons dashicons-warning"></span>
                <span>' . sprintf(esc_html__('Low XLM balance: %s (recommend 1.5+ XLM)', 'real8-gateway'), number_format($status['xlm_balance'], 2)) . '</span>
            </div>';
        }

        // Token trustlines
        $html .= '<div class="stellar-trustlines" style="margin-top: 10px;">';
        $html .= '<strong>' . esc_html__('Lineas de Confianza:', 'real8-gateway') . '</strong>';
        $html .= '<div style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px;">';

        foreach (REAL8_Token_Registry::get_all_tokens() as $token_code => $token) {
            $trustline_info = isset($status['token_trustlines'][$token_code])
                ? $status['token_trustlines'][$token_code]
                : array('has_trustline' => false, 'balance' => 0);

            $has = $trustline_info['has_trustline'];
            $balance = $trustline_info['balance'];

            $class = $has ? 'stellar-token-ok' : 'stellar-token-missing';
            $icon = $has ? 'dashicons-yes' : 'dashicons-no';
            $title = $has
                ? sprintf(__('%s: %.2f balance', 'real8-gateway'), $token_code, $balance)
                : sprintf(__('%s: No trustline', 'real8-gateway'), $token_code);

            $html .= '<span class="' . esc_attr($class) . '" title="' . esc_attr($title) . '" style="padding: 4px 8px; border-radius: 4px; font-size: 12px;">';
            $html .= '<span class="dashicons ' . esc_attr($icon) . '" style="font-size: 14px; width: 14px; height: 14px;"></span> ';
            $html .= esc_html($token_code);
            $html .= '</span>';
        }

        $html .= '</div></div>';

        $html .= '</div>';

        // Count enabled tokens with trustlines
        $enabled_ok = 0;
        $enabled_missing = 0;
        foreach ($this->accepted_tokens as $token_code) {
            if (isset($status['token_trustlines'][$token_code])) {
                if ($status['token_trustlines'][$token_code]['has_trustline']) {
                    $enabled_ok++;
                } else {
                    $enabled_missing++;
                }
            }
        }

        // Overall status
        if ($enabled_missing === 0 && $status['xlm_balance'] >= 1.5) {
            $html .= '<div class="stellar-status-box stellar-status-success" style="margin-top: 10px;">
                <span class="dashicons dashicons-yes-alt"></span>
                <span><strong>' . esc_html__('Cuenta preparada para recibir Pagos REAL8 / Stellar', 'real8-gateway') . '</strong></span>
            </div>';
        } elseif ($enabled_missing > 0) {
            $html .= '<div class="stellar-status-box stellar-status-warning" style="margin-top: 10px;">
                <span class="dashicons dashicons-warning"></span>
                <span><strong>' . sprintf(
                    /* translators: %d: number of missing trustlines */
                    esc_html__('Warning: %d enabled token(s) missing trustlines', 'real8-gateway'),
                    $enabled_missing
                ) . '</strong></span>
            </div>';
        }

        // Add styles
        $html .= '<style>
            .stellar-token-ok { background: #d4edda; color: #155724; }
            .stellar-token-missing { background: #f8d7da; color: #721c24; }
            .stellar-status-box { padding: 10px 15px; border-radius: 4px; display: flex; align-items: center; gap: 8px; }
            .stellar-status-success { background: #d4edda; color: #155724; }
            .stellar-status-warning { background: #fff3cd; color: #856404; }
            .stellar-status-error { background: #f8d7da; color: #721c24; }
            .stellar-check { display: flex; align-items: center; gap: 5px; margin: 5px 0; }
            .stellar-check-success { color: #155724; }
            .stellar-check-warning { color: #856404; }
            .stellar-check-error { color: #721c24; }
        </style>';

        return $html;
    }
}