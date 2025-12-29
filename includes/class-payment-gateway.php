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
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

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
                'type' => 'merchant_address',
                'description' => __('Your Stellar public key (starts with G) where REAL8 payments will be received. Each merchant needs their own Stellar wallet with REAL8 trustline.', 'real8-gateway'),
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
            'real8-gateway-admin',
            REAL8_GATEWAY_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            REAL8_GATEWAY_VERSION
        );

        wp_enqueue_script(
            'real8-gateway-admin',
            REAL8_GATEWAY_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            REAL8_GATEWAY_VERSION,
            true
        );

        wp_localize_script('real8-gateway-admin', 'real8_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('real8_admin_nonce'),
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

        // Check wallet status
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

                    <div id="real8-wallet-status" class="real8-wallet-status" style="margin-top: 10px;">
                        <?php echo $this->render_wallet_status($wallet_status); ?>
                    </div>

                    <p class="description">
                        <?php echo wp_kses_post($data['description']); ?>
                    </p>

                    <div class="real8-wallet-help" style="margin-top: 15px; padding: 15px; background: #f8f9fa; border-left: 4px solid #007cba; border-radius: 4px;">
                        <strong><?php esc_html_e('How to set up your merchant wallet:', 'real8-gateway'); ?></strong>
                        <ol style="margin: 10px 0 0 20px;">
                            <li><?php esc_html_e('Create a Stellar wallet (use Lobstr, Solar, or any Stellar wallet)', 'real8-gateway'); ?></li>
                            <li><?php esc_html_e('Fund it with at least 1.5 XLM (for account reserve)', 'real8-gateway'); ?></li>
                            <li><?php esc_html_e('Add REAL8 trustline:', 'real8-gateway'); ?>
                                <br><code style="font-size: 11px;">Asset: REAL8 | Issuer: <?php echo esc_html(REAL8_GW_ASSET_ISSUER); ?></code>
                            </li>
                            <li><?php esc_html_e('Paste your public key (starts with G) above', 'real8-gateway'); ?></li>
                        </ol>
                    </div>
                </fieldset>
            </td>
        </tr>
        <?php

        return ob_get_clean();
    }

    /**
     * Check wallet status on Stellar network
     */
    private function check_wallet_status($address) {
        $status = array(
            'exists' => false,
            'funded' => false,
            'has_trustline' => false,
            'xlm_balance' => 0,
            'real8_balance' => 0,
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

        // Check balances
        foreach ($data['balances'] as $balance) {
            if ($balance['asset_type'] === 'native') {
                $status['xlm_balance'] = (float) $balance['balance'];
            } elseif (
                isset($balance['asset_code']) &&
                $balance['asset_code'] === REAL8_GW_ASSET_CODE &&
                isset($balance['asset_issuer']) &&
                $balance['asset_issuer'] === REAL8_GW_ASSET_ISSUER
            ) {
                $status['has_trustline'] = true;
                $status['real8_balance'] = (float) $balance['balance'];
            }
        }

        return $status;
    }

    /**
     * Render wallet status HTML
     */
    private function render_wallet_status($status) {
        if ($status['error'] === 'no_address') {
            return '<div class="real8-status-box real8-status-warning">
                <span class="dashicons dashicons-warning"></span>
                <span>' . esc_html__('No wallet address configured. Enter your Stellar public key above.', 'real8-gateway') . '</span>
            </div>';
        }

        if ($status['error'] === 'invalid_format') {
            return '<div class="real8-status-box real8-status-error">
                <span class="dashicons dashicons-no"></span>
                <span>' . esc_html__('Invalid address format. Stellar addresses are 56 characters starting with G.', 'real8-gateway') . '</span>
            </div>';
        }

        if ($status['error'] === 'not_found') {
            return '<div class="real8-status-box real8-status-error">
                <span class="dashicons dashicons-no"></span>
                <span>' . esc_html__('Wallet not found on Stellar network. Make sure the account exists and is funded with at least 1 XLM.', 'real8-gateway') . '</span>
            </div>';
        }

        if ($status['error'] === 'network_error' || $status['error'] === 'api_error') {
            return '<div class="real8-status-box real8-status-warning">
                <span class="dashicons dashicons-warning"></span>
                <span>' . esc_html__('Could not verify wallet. Network error - please try again.', 'real8-gateway') . '</span>
            </div>';
        }

        // Account exists - show detailed status
        $html = '<div class="real8-status-checks">';

        // Account exists check
        $html .= '<div class="real8-check real8-check-success">
            <span class="dashicons dashicons-yes-alt"></span>
            <span>' . esc_html__('Account exists on Stellar', 'real8-gateway') . '</span>
        </div>';

        // XLM balance check
        if ($status['xlm_balance'] >= 1.5) {
            $html .= '<div class="real8-check real8-check-success">
                <span class="dashicons dashicons-yes-alt"></span>
                <span>' . sprintf(esc_html__('Funded with %s XLM', 'real8-gateway'), number_format($status['xlm_balance'], 2)) . '</span>
            </div>';
        } else {
            $html .= '<div class="real8-check real8-check-warning">
                <span class="dashicons dashicons-warning"></span>
                <span>' . sprintf(esc_html__('Low XLM balance: %s (recommend 1.5+ XLM)', 'real8-gateway'), number_format($status['xlm_balance'], 2)) . '</span>
            </div>';
        }

        // REAL8 trustline check
        if ($status['has_trustline']) {
            $html .= '<div class="real8-check real8-check-success">
                <span class="dashicons dashicons-yes-alt"></span>
                <span>' . sprintf(esc_html__('REAL8 trustline active (Balance: %s REAL8)', 'real8-gateway'), number_format($status['real8_balance'], 2)) . '</span>
            </div>';
        } else {
            $html .= '<div class="real8-check real8-check-error">
                <span class="dashicons dashicons-no"></span>
                <span>' . esc_html__('REAL8 trustline missing - you cannot receive REAL8 payments!', 'real8-gateway') . '</span>
            </div>';
        }

        $html .= '</div>';

        // Overall status
        if ($status['has_trustline'] && $status['xlm_balance'] >= 1.5) {
            $html .= '<div class="real8-status-box real8-status-success" style="margin-top: 10px;">
                <span class="dashicons dashicons-yes-alt"></span>
                <span><strong>' . esc_html__('Ready to receive REAL8 payments!', 'real8-gateway') . '</strong></span>
            </div>';
        } elseif (!$status['has_trustline']) {
            $html .= '<div class="real8-status-box real8-status-error" style="margin-top: 10px;">
                <span class="dashicons dashicons-warning"></span>
                <span><strong>' . esc_html__('Action required: Add REAL8 trustline to receive payments', 'real8-gateway') . '</strong></span>
            </div>';
        }

        return $html;
    }
}
