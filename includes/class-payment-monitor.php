<?php
/**
 * Payment Monitor
 *
 * Handles automatic checking of pending Stellar payments via cron
 * Supports all tokens: XLM, REAL8, wREAL8, USDC, EURC, SLVR, GOLD
 *
 * @package REAL8_Gateway
 * @version 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class REAL8_Payment_Monitor {
    private static $instance = null;
    private $stellar_api;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->stellar_api = REAL8_Stellar_Payment_API::get_instance();

        // Register cron hook
        add_action('real8_gateway_check_payments', array($this, 'check_pending_payments'));

        // Admin notices
        add_action('admin_notices', array($this, 'admin_notices'));
    }

    /**
     * Check all pending payments
     *
     * This runs via cron every minute
     */
    public function check_pending_payments() {
        global $wpdb;
        $table = $wpdb->prefix . 'real8_payments';

        // Get all pending payments
        $pending = $wpdb->get_results(
            "SELECT * FROM $table WHERE status = 'pending' ORDER BY created_at ASC"
        );

        if (empty($pending)) {
            return;
        }

        $gateway_settings = get_option('woocommerce_real8_payment_settings');
        $default_merchant_address = isset($gateway_settings['merchant_address']) ? (string) $gateway_settings['merchant_address'] : '';

        if (empty($default_merchant_address)) {
            error_log('REAL8 Gateway: No merchant address configured');
            return;
        }

foreach ($pending as $payment) {
            $merchant_for_payment = !empty($payment->merchant_address) ? (string) $payment->merchant_address : $default_merchant_address;
            $this->check_single_payment($payment, $merchant_for_payment);
        }
    }

    /**
     * Check a single payment
     *
     * @param object $payment Payment record from database
     * @param string $merchant_address Merchant's Stellar address
     */
    private function check_single_payment($payment, $merchant_address) {
        global $wpdb;
        $table = $wpdb->prefix . 'real8_payments';

        // Check if expired
        $expires_at = strtotime($payment->expires_at);
        if (time() > $expires_at) {
            $this->mark_payment_expired($payment);
            return;
        }

        // Get asset code and issuer from payment record
        $asset_code = isset($payment->asset_code) ? $payment->asset_code : 'REAL8';
        $asset_issuer = isset($payment->asset_issuer) ? $payment->asset_issuer : REAL8_GW_ASSET_ISSUER;

        // Get expected amount (column renamed from amount_real8 to amount_token in v3.0)
        $expected_amount = isset($payment->amount_token) ? (float) $payment->amount_token : (float) $payment->amount_real8;

        // Check for payment on Stellar
        $result = $this->stellar_api->check_payment(
            $merchant_address,
            trim((string) $payment->memo),
            $expected_amount,
            $asset_code,
            $asset_issuer
        );

        if (is_wp_error($result)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('REAL8 Gateway: check_single_payment error: ' . $result->get_error_message());
            }
            return;
        }

        if ($result) {
            $this->mark_payment_confirmed($payment, $result, $asset_code);
        }
    }

    /**
     * Mark payment as expired
     *
     * @param object $payment Payment record
     */
    private function mark_payment_expired($payment) {
        global $wpdb;
        $table = $wpdb->prefix . 'real8_payments';

        $wpdb->update(
            $table,
            array('status' => 'expired'),
            array('id' => $payment->id),
            array('%s'),
            array('%d')
        );

        // Get asset code and amount for message
        $asset_code = isset($payment->asset_code) ? $payment->asset_code : 'REAL8';
        $amount = isset($payment->amount_token) ? $payment->amount_token : 0;
        $memo = isset($payment->memo) ? trim((string) $payment->memo) : '';

        // Update order status with detailed note
        $order = wc_get_order($payment->order_id);
        if ($order && $order->has_status('pending')) {
            // Add detailed expiration note
            $order->add_order_note(sprintf(
                __('Payment EXPIRED. Expected: %1$s %2$s. Memo: %3$s. No matching payment found on Stellar network before deadline.', 'real8-gateway'),
                number_format($amount, 7),
                $asset_code,
                $memo
            ));

            $order->update_status(
                'failed',
                sprintf(
                    /* translators: %s: token code */
                    __('%s payment expired - no payment received within the time limit.', 'real8-gateway'),
                    $asset_code
                )
            );
        }

        error_log(sprintf('REAL8 Gateway: Payment expired for order #%d (%s)', $payment->order_id, $asset_code));
    }

    /**
     * Mark payment as confirmed
     *
     * @param object $payment Payment record
     * @param array $result Payment result from Stellar
     * @param string $asset_code Asset code for display
     */
    private function mark_payment_confirmed($payment, $result, $asset_code = 'REAL8') {
        global $wpdb;
        $table = $wpdb->prefix . 'real8_payments';

        $wpdb->update(
            $table,
            array(
                'status' => 'confirmed',
                'stellar_tx_hash' => $result['tx_hash'],
                'paid_at' => current_time('mysql', true),
            ),
            array('id' => $payment->id),
            array('%s', '%s', '%s'),
            array('%d')
        );

        // Update order
        $order = wc_get_order($payment->order_id);
        if ($order) {
            // Add order note with transaction details
            $note = sprintf(
                /* translators: 1: amount, 2: token code, 3: tx hash, 4: sender address */
                __('%1$s %2$s payment confirmed! TX: %3$s. From: %4$s', 'real8-gateway'),
                number_format($result['amount'], 7),
                $asset_code,
                $result['tx_hash'],
                $result['from']
            );
            $order->add_order_note($note);

            // Save transaction details
            $order->update_meta_data('_stellar_tx_hash', $result['tx_hash']);
            $order->update_meta_data('_stellar_paid_amount', $result['amount']);
            $order->update_meta_data('_stellar_from_address', $result['from']);
            $order->update_meta_data('_stellar_paid_at', $result['created_at']);

            // Mark as processing (or completed depending on settings)
            $order->payment_complete($result['tx_hash']);
            $order->save();

            error_log(sprintf(
                'REAL8 Gateway: %s payment confirmed for order #%d - TX: %s',
                $asset_code,
                $payment->order_id,
                $result['tx_hash']
            ));
        }
    }

    /**
     * Show admin notices for payment issues
     */
    public function admin_notices() {
        // Only show on WooCommerce pages
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'woocommerce') === false) {
            return;
        }

        // Check if gateway is enabled but not configured
        $gateway_settings = get_option('woocommerce_real8_payment_settings');
        if (isset($gateway_settings['enabled']) && $gateway_settings['enabled'] === 'yes') {
            if (empty($gateway_settings['merchant_address'])) {
                ?>
                <div class="notice notice-warning">
                    <p>
                        <strong><?php esc_html_e('Stellar Payment Gateway:', 'real8-gateway'); ?></strong>
                        <?php
                        printf(
                            esc_html__('Payment gateway is enabled but no merchant address is configured. %sGo to settings%s', 'real8-gateway'),
                            '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=real8_payment')) . '">',
                            '</a>'
                        );
                        ?>
                    </p>
                </div>
                <?php
            }
        }

        // Check for pending payments that are about to expire
        global $wpdb;
        $table = $wpdb->prefix . 'real8_payments';

        $expiring_soon = $wpdb->get_var(
            "SELECT COUNT(*) FROM $table
             WHERE status = 'pending'
             AND expires_at < DATE_ADD(NOW(), INTERVAL 5 MINUTE)
             AND expires_at > NOW()"
        );

        if ($expiring_soon > 0) {
            ?>
            <div class="notice notice-info">
                <p>
                    <strong><?php esc_html_e('Stellar Payment Gateway:', 'real8-gateway'); ?></strong>
                    <?php
                    printf(
                        esc_html(_n(
                            '%d pending Stellar payment is about to expire.',
                            '%d pending Stellar payments are about to expire.',
                            $expiring_soon,
                            'real8-gateway'
                        )),
                        $expiring_soon
                    );
                    ?>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Manual check for a specific order
     *
     * @param int $order_id WooCommerce order ID
     * @return bool|WP_Error True if payment found, false if not, WP_Error on error
     */
    public function manual_check_order($order_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'real8_payments';

        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE order_id = %d",
            $order_id
        ));

        if (!$payment) {
            return new WP_Error('not_found', __('No Stellar payment record found for this order', 'real8-gateway'));
        }

        if ($payment->status === 'confirmed') {
            return new WP_Error('already_paid', __('This order has already been paid', 'real8-gateway'));
        }

        if ($payment->status === 'expired') {
            return new WP_Error('expired', __('This payment has expired', 'real8-gateway'));
        }

        $gateway_settings = get_option('woocommerce_real8_payment_settings');
        // Prefer the address stored with this payment record (future-proof if settings change)
        $merchant_address = !empty($payment->merchant_address) ? (string) $payment->merchant_address : '';
        if (empty($merchant_address)) {
            $merchant_address = isset($gateway_settings['merchant_address']) ? (string) $gateway_settings['merchant_address'] : '';
        }

        if (empty($merchant_address)) {
            return new WP_Error('no_address', __('Merchant address not configured', 'real8-gateway'));
        }

        // Get asset details from payment record
        $asset_code = isset($payment->asset_code) ? $payment->asset_code : 'REAL8';
        $asset_issuer = isset($payment->asset_issuer) ? $payment->asset_issuer : REAL8_GW_ASSET_ISSUER;
        $expected_amount = isset($payment->amount_token) ? (float) $payment->amount_token : (float) $payment->amount_real8;

        $result = $this->stellar_api->check_payment(
            $merchant_address,
            trim((string) $payment->memo),
            $expected_amount,
            $asset_code,
            $asset_issuer
        );

        if (is_wp_error($result)) {
            return $result;
        }

        if ($result) {
            $this->mark_payment_confirmed($payment, $result, $asset_code);
            return true;
        }

        return false;
    }

    /**
     * Get pending payments count
     *
     * @return int Count of pending payments
     */
    public function get_pending_count() {
        global $wpdb;
        $table = $wpdb->prefix . 'real8_payments';

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'pending'");
    }

    /**
     * Get payment statistics
     *
     * @return array Payment statistics
     */
    public function get_statistics() {
        global $wpdb;
        $table = $wpdb->prefix . 'real8_payments';

        $stats = array(
            'total' => 0,
            'pending' => 0,
            'confirmed' => 0,
            'expired' => 0,
            'by_token' => array(),
            'total_usd_received' => 0,
        );

        // Overall counts
        $counts = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM $table GROUP BY status"
        );

        foreach ($counts as $row) {
            $stats[$row->status] = (int) $row->count;
            $stats['total'] += (int) $row->count;
        }

        // Per-token statistics
        $token_stats = $wpdb->get_results(
            "SELECT asset_code,
                    COUNT(*) as total_count,
                    SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_count,
                    SUM(CASE WHEN status = 'confirmed' THEN amount_token ELSE 0 END) as total_received,
                    SUM(CASE WHEN status = 'confirmed' THEN amount_usd ELSE 0 END) as total_usd
             FROM $table
             GROUP BY asset_code"
        );

        foreach ($token_stats as $row) {
            $code = $row->asset_code ?: 'REAL8';
            $stats['by_token'][$code] = array(
                'total' => (int) $row->total_count,
                'confirmed' => (int) $row->confirmed_count,
                'amount_received' => (float) $row->total_received,
                'usd_received' => (float) $row->total_usd,
            );
            $stats['total_usd_received'] += (float) $row->total_usd;
        }

        return $stats;
    }
}

// Initialize
add_action('init', function() {
    REAL8_Payment_Monitor::get_instance();
});
