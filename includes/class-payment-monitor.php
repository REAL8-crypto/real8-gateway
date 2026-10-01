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

// Payment records use plugin-owned tables; WooCommerce CRUD handles orders.
// Verification and atomic claims require fresh reads; caching could settle stale rows.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

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

        $this->repair_confirmed_rows();

        // Get all pending payments
        $pending = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE status = 'pending' ORDER BY created_at ASC", $table)
        );

        if (empty($pending)) {
            return;
        }

        $gateway_settings = get_option('woocommerce_real8_payment_settings');
        $default_merchant_address = isset($gateway_settings['merchant_address']) ? (string) $gateway_settings['merchant_address'] : '';

        if (empty($default_merchant_address)) {
            real8_gateway_log('REAL8 Gateway: No merchant address configured');
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
        $is_past_deadline = time() > strtotime($payment->expires_at);

        // Get asset code and issuer from payment record
        $asset_code = isset($payment->asset_code) ? $payment->asset_code : 'REAL8';
        $asset_issuer = isset($payment->asset_issuer) ? $payment->asset_issuer : REAL8_GW_ASSET_ISSUER;

        // Get expected amount (column renamed from amount_real8 to amount_token in v3.0)
        $expected_amount = isset($payment->amount_token) ? (float) $payment->amount_token : (float) $payment->amount_real8;

        // Check for payment on Stellar. This runs even past the deadline
        // (v4.5.1): expiring without one last Horizon check lost payments
        // made in time but seen late. With a cron gap longer than the
        // payment window, a customer who paid within minutes still had the
        // order expire and get cancelled.
        $result = $this->stellar_api->check_payment(
            $merchant_address,
            trim((string) $payment->memo),
            $expected_amount,
            $asset_code,
            $asset_issuer,
            null,
            $this->scan_not_before($payment)
        );

        if (is_wp_error($result)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                real8_gateway_log('REAL8 Gateway: check_single_payment error: ' . $result->get_error_message());
            }
            // Never expire on a failed lookup: a Horizon hiccup at the
            // deadline must not discard a possibly-settled payment. The wait
            // is bounded, though. A row that still cannot be verified long
            // after its deadline is closed for manual reconciliation instead
            // of being re-scanned every minute forever.
            if ($this->verification_wait_exceeded($payment)) {
                $this->expire_payment($payment, $result);
            }
            return;
        }

        if ($result && !$this->tx_is_plausibly_for_payment($payment, $result)) {
            $result = false; // an old on-chain tx carrying this memo is not this order's payment
        }

        if ($result) {
            $confirmed = $this->mark_payment_confirmed($payment, $result, $asset_code);
            if (is_wp_error($confirmed)) {
                real8_gateway_log(sprintf('REAL8 Gateway: payment found for order #%d (TX: %s) but it could not be recorded: %s', (int) $payment->order_id, isset($result['tx_hash']) ? $result['tx_hash'] : '?', $confirmed->get_error_message()));
            }
            return;
        }

        if ($is_past_deadline) {
            $this->mark_payment_expired($payment);
        }
    }

    /**
     * Earliest moment a transaction for this payment row can carry. Nothing
     * older can settle it (see tx_is_plausibly_for_payment()), so the history
     * scan stops there instead of walking the whole account.
     *
     * @param object $payment Payment row
     * @return int Unix time, or 0 when the row has no usable creation time
     */
    private function scan_not_before($payment) {
        $row_ts = isset($payment->created_at) ? strtotime((string) $payment->created_at) : false;
        return $row_ts ? max(0, $row_ts - 60) : 0;
    }

    /**
     * Whether a row has stayed unverifiable for too long after its deadline.
     *
     * @param object $payment Payment row
     * @return bool
     */
    private function verification_wait_exceeded($payment) {
        $expires_ts = isset($payment->expires_at) ? strtotime((string) $payment->expires_at) : false;
        if (!$expires_ts) {
            return false;
        }
        /**
         * Seconds after the payment deadline during which a failing
         * verification keeps the payment pending.
         *
         * @param int $seconds Default one day.
         */
        $wait = (int) apply_filters('real8_gateway_unverified_wait_seconds', DAY_IN_SECONDS);
        return time() > $expires_ts + max(HOUR_IN_SECONDS, $wait);
    }

    /**
     * Message that is safe to show a customer for a failed status check.
     * Transport and service details stay in the log.
     *
     * @param WP_Error $error Error from manual_check_order()
     * @return string
     */
    public function customer_error_message($error) {
        $public = array('verification_throttled', 'already_paid', 'expired', 'not_found', 'scan_limit');
        if (in_array($error->get_error_code(), $public, true)) {
            return $error->get_error_message();
        }
        real8_gateway_log('REAL8 Gateway: payment status check failed: ' . $error->get_error_code() . ' ' . $error->get_error_message());
        return __('Payment verification is temporarily unavailable. Please try again shortly.', 'real8-gateway');
    }

    /**
     * Repair confirmed rows whose order never completed (issue #10).
     *
     * mark_payment_confirmed() claims the row and only then completes the
     * order. If PHP dies between the two, the row says `confirmed`, the order
     * is still unpaid, cron ignores the row because it only scans `pending`,
     * and the manual check answers "already paid". Nothing fixed it. This pass
     * runs every cron tick over recent confirmed rows and completes any order
     * that is still unpaid, from the transaction hash the row already holds.
     *
     * Bounded to the last 30 days so the query stays cheap forever.
     *
     * @return int Number of orders repaired
     */
    public function repair_confirmed_rows() {
        global $wpdb;
        $table = $wpdb->prefix . 'real8_payments';

        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i
             WHERE status = 'confirmed'
               AND stellar_tx_hash IS NOT NULL AND stellar_tx_hash <> ''
               AND paid_at >= (UTC_TIMESTAMP() - INTERVAL 30 DAY)
             ORDER BY paid_at ASC", $table)
        );
        if (empty($rows)) {
            return 0;
        }

        $repaired = 0;
        foreach ($rows as $payment) {
            if ($this->repair_confirmed_row($payment)) {
                $repaired++;
            }
        }
        return $repaired;
    }

    /**
     * Complete the order of one confirmed row if it is still unpaid.
     *
     * @param object $payment Payment row with status confirmed
     * @return bool True if the order was repaired, false if nothing was needed
     */
    private function repair_confirmed_row($payment) {
        $order = wc_get_order($payment->order_id);
        if (!$order || $order->is_paid() || $order->has_status('refunded') || $order->get_meta('_real8_manual_review')) {
            return false;
        }
        // An order that was completed once carries a paid date. If it is no
        // longer in a paid status, someone changed it on purpose (cancelled,
        // put on hold, refunded by hand); that is not a detached confirmation
        // and must not be undone. One attempt per order, so an order that
        // cannot be completed (custom status, trash) is not retried every minute.
        if ($order->get_date_paid() || $order->get_meta('_real8_repair_attempted')) {
            return false;
        }

        $asset_code = isset($payment->asset_code) ? $payment->asset_code : 'REAL8';
        $order->add_order_note(sprintf(
            /* translators: %s: Stellar transaction hash */
            __('Payment was confirmed on Stellar (TX: %s) but this order had not been completed. Completing it now.', 'real8-gateway'),
            $payment->stellar_tx_hash
        ));
        $this->finalize_confirmed_order($payment, array(
            'tx_hash'    => $payment->stellar_tx_hash,
            'amount'     => isset($payment->amount_token) ? (float) $payment->amount_token : 0,
            'from'       => __('(not recorded)', 'real8-gateway'),
            'created_at' => $payment->paid_at,
        ), $asset_code);

        $order = wc_get_order($payment->order_id);
        if ($order && !$order->is_paid() && !$order->get_meta('_real8_manual_review')) {
            $order->update_meta_data('_real8_repair_attempted', (string) time());
            $order->add_order_note(__('The order could not be completed automatically from its confirmed REAL8 payment. Review it manually.', 'real8-gateway'));
            $order->save();
            real8_gateway_log(sprintf('REAL8 Gateway: order #%d could not be completed from confirmed payment row %d', (int) $payment->order_id, (int) $payment->id));
            return false;
        }

        real8_gateway_log(sprintf('REAL8 Gateway: repaired order #%d from confirmed payment row %d (TX: %s)', (int) $payment->order_id, (int) $payment->id, $payment->stellar_tx_hash));
        return true;
    }

    /**
     * Mark payment as expired
     *
     * @param object $payment Payment record
     */
    private function mark_payment_expired($payment) {
        $this->expire_payment($payment);
    }

    /**
     * Expire a payment row and fail its order, but only if the row is still
     * pending. Confirmation has had an atomic claim since 4.5.3; expiry did
     * not, so cron, holding a row it had read as pending at the start of its
     * pass, could stamp `expired` over a row the browser-side check had just
     * confirmed, and the two browser-side expiry paths then failed the order
     * without looking at its status at all (issue #11). One claim here, shared
     * by all three, and the order is touched only by the caller that won it.
     *
     * @param object        $payment    Payment row as read earlier by the caller
     * @param WP_Error|null $unverified Set when the row is closed because
     *                                  verification kept failing, not because
     *                                  a completed check found no payment
     * @return bool True if this call expired the row, false if it was no
     *              longer pending (confirmed meanwhile, or already expired)
     */
    public function expire_payment($payment, $unverified = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'real8_payments';

        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE %i SET status = 'expired' WHERE id = %d AND status = 'pending'",
            $table,
            $payment->id
        ));
        if ($claimed === 0 || $claimed === false) {
            real8_gateway_log(sprintf('REAL8 Gateway: not expiring payment row %d (order #%d), it is no longer pending', (int) $payment->id, (int) $payment->order_id));
            return false;
        }

        // Get asset code and amount for message
        $asset_code = isset($payment->asset_code) ? $payment->asset_code : 'REAL8';
        $amount = isset($payment->amount_token) ? $payment->amount_token : 0;
        $memo = isset($payment->memo) ? trim((string) $payment->memo) : '';

        // Update order status with detailed note
        $order = wc_get_order($payment->order_id);
        if ($order && is_wp_error($unverified)) {
            $order->add_order_note(sprintf(
                /* translators: 1: expected amount, 2: token code, 3: payment memo, 4: technical reason. */
                __('Payment could NOT be verified on Stellar and automatic checks have stopped. Expected: %1$s %2$s. Memo: %3$s. Reason: %4$s. Check the merchant account for this memo before treating the order as unpaid.', 'real8-gateway'),
                number_format($amount, 7),
                $asset_code,
                $memo,
                $unverified->get_error_message()
            ));
            if ($order->has_status('pending')) {
                $order->update_status('on-hold');
            }
            real8_gateway_log(sprintf('REAL8 Gateway: stopped checking unverifiable payment for order #%d (%s)', $payment->order_id, $unverified->get_error_code()));
            return true;
        }
        if ($order && $order->has_status('pending')) {
            // Add detailed expiration note
            $order->add_order_note(sprintf(
                /* translators: 1: expected amount, 2: token code, 3: payment memo. */
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

        real8_gateway_log(sprintf('REAL8 Gateway: Payment expired for order #%d (%s)', $payment->order_id, $asset_code));
        return true;
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
        $tx_hash = isset($result['tx_hash']) ? strtolower((string) $result['tx_hash']) : '';
        if (!preg_match('/^[a-f0-9]{64}$/', $tx_hash)) {
            return false;
        }

        // The primary key makes ownership atomic even across simultaneous workers.
        // Claims survive replacement of a payment row and a PHP interruption.
        $claims_table = $wpdb->prefix . 'real8_transaction_claims';
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO %i (tx_hash, payment_id) VALUES (%s, %d)",
            $claims_table, $tx_hash, $payment->id
        ));
        if ($inserted === false) {
            return new WP_Error('claim_failed', __('Unable to record the payment transaction.', 'real8-gateway'));
        }
        $owner = $wpdb->get_var($wpdb->prepare(
            "SELECT payment_id FROM %i WHERE tx_hash = %s", $claims_table, $tx_hash
        ));
        if ((int) $owner !== (int) $payment->id) {
            return false;
        }

        // Atomic claim: cron and the browser-side check can both find the
        // payment; only the one that flips the row proceeds to payment_complete.
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE %i SET status = 'confirmed', stellar_tx_hash = %s, paid_at = %s WHERE id = %d AND status <> 'confirmed'",
            $table,
            $tx_hash, current_time('mysql', true), $payment->id
        ));
        if ($claimed === false) {
            return new WP_Error('confirmation_failed', __('Unable to save payment confirmation.', 'real8-gateway'));
        }
        if ($claimed === 0) {
            return false;
        }

        $this->finalize_confirmed_order($payment, $result, $asset_code);
        return true;
    }

    /**
     * Bring the WooCommerce order in line with a payment row that is already
     * `confirmed`. Split out of mark_payment_confirmed() so the repair pass and
     * the manual check can re-run it for a row whose order never completed
     * (issue #10). Safe to repeat: payment_complete() only changes an order that
     * is still in a payable status.
     *
     * @param object $payment    Payment row
     * @param array  $result     tx_hash, amount, from, created_at
     * @param string $asset_code Asset code for display
     */
    private function finalize_confirmed_order($payment, $result, $asset_code = 'REAL8') {
        $order = wc_get_order($payment->order_id);
        if ($order) {
            // Late confirmation of an order that already timed out (v4.5.1):
            // WooCommerce's payment_complete() below accepts cancelled/failed
            // orders (OrderStatus::PAYMENT_COMPLETE_STATUSES) and revives
            // them to processing, so leave an explicit trace of the revival.
            if ($order->has_status(array('cancelled', 'failed'))) {
                $order->add_order_note(sprintf(
                    /* translators: %s: previous order status */
                    __('Payment found on Stellar after the order had been marked "%s", reviving the order.', 'real8-gateway'),
                    $order->get_status()
                ));
            }

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

            // A quote covers the order total and currency saved with the payment.
            // Editing the order after quoting requires reconciliation, not automatic fulfilment.
            if ($order->get_currency() !== 'USD' || $order->get_payment_method() !== 'real8_payment' || abs((float) $order->get_total() - (float) $payment->amount_usd) > 0.005) {
                $order->update_meta_data('_real8_manual_review', 'yes');
                $order->add_order_note(__('REAL8 payment received, but the order changed after quoting. Review the payment before fulfilling this order.', 'real8-gateway'));
                if (!$order->is_paid() && !$order->has_status('refunded')) {
                    $order->update_status('on-hold');
                }
                $order->save();
                return;
            }

            // Mark as processing (or completed depending on settings)
            $order->payment_complete($result['tx_hash']);
            $order->save();

            // Report settlement back to the payment-intent API so a revisited
            // pay link shows "paid" instead of pending/expired. Best-effort:
            // a failure only logs, the order is already complete.
            $this->notify_intent_paid($order, $result['tx_hash']);

            real8_gateway_log(sprintf(
                'REAL8 Gateway: %s payment confirmed for order #%d - TX: %s',
                $asset_code,
                $payment->order_id,
                $result['tx_hash']
            ));
        }
    }

    /**
     * Notify api.real8.org that the payment intent for this order settled.
     * Uses the same HMAC scheme as intent creation. Failures are logged only —
     * the intent record is informational, the order state is authoritative.
     *
     * @param WC_Order $order   Order whose intent settled
     * @param string   $tx_hash Stellar transaction hash
     */
    private function notify_intent_paid($order, $tx_hash) {
        $settings = get_option('woocommerce_real8_payment_settings', array());
        if (($settings['payment_intents'] ?? 'no') !== 'yes') {
            return;
        }
        $intent_id = $order->get_meta('_real8_intent_id');
        if (empty($intent_id) || !defined('REAL8_PAYMENT_INTENT_SECRET') || REAL8_PAYMENT_INTENT_SECRET === '') {
            return;
        }

        $body = wp_json_encode(array('tx_hash' => strtolower((string) $tx_hash)));
        $path = '/payment-intents/' . rawurlencode($intent_id) . '/paid';
        // HMAC v2 (4.5.3 / api 1.7.9): bound to method, path and time.
        $ts        = (string) time();
        $signature = hash_hmac('sha256', "v2\nPOST\n" . $path . "\n" . $ts . "\n" . $body, REAL8_PAYMENT_INTENT_SECRET);

        $response = wp_remote_post('https://api.real8.org' . $path, array(
            'headers' => array(
                'Content-Type'      => 'application/json',
                'X-REAL8-Signature' => $signature,
                'X-REAL8-Timestamp' => $ts,
            ),
            'body'    => $body,
            'timeout' => 8,
        ));

        if (is_wp_error($response) || !in_array(wp_remote_retrieve_response_code($response), array(200, 409), true)) {
            $err = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_body($response);
            real8_gateway_log(sprintf('REAL8 Gateway: failed to mark intent %s paid: %s', $intent_id, $err));
        }
    }

    /**
     * Show admin notices for payment issues
     */
    public function admin_notices() {
        // Only show on WooCommerce pages
        $screen = get_current_screen();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice scope.
        if (!current_user_can('manage_woocommerce') || !$screen || $screen->id !== 'woocommerce_page_wc-settings' || !isset($_GET['section']) || sanitize_key(wp_unslash($_GET['section'])) !== 'real8_payment') {
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
                            /* translators: 1: opening settings link, 2: closing link. */
                            esc_html__('Payment gateway is enabled but no merchant address is configured. %1$sGo to settings%2$s', 'real8-gateway'),
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

        $expiring_soon = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i
             WHERE status = 'pending'
             AND expires_at < DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)
             AND expires_at > UTC_TIMESTAMP()", $table)
        );

        if ($expiring_soon > 0) {
            ?>
            <div class="notice notice-info">
                <p>
                    <strong><?php esc_html_e('Stellar Payment Gateway:', 'real8-gateway'); ?></strong>
                    <?php
                    printf(
                        /* translators: %d: number of pending payments. */
                        esc_html(_n(
                            '%d pending Stellar payment is about to expire.',
                            '%d pending Stellar payments are about to expire.',
                            $expiring_soon,
                            'real8-gateway'
                        )),
                        esc_html($expiring_soon)
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
            "SELECT * FROM %i WHERE order_id = %d",
            $table,
            $order_id
        ));

        if (!$payment) {
            return new WP_Error('not_found', __('No Stellar payment record found for this order', 'real8-gateway'));
        }

        if ($payment->status === 'confirmed') {
            // The row is paid. If the order disagrees, that is issue #10, and
            // this is the moment to fix it rather than report "already paid".
            if ($this->repair_confirmed_row($payment)) {
                return true;
            }
            return new WP_Error('already_paid', __('This order has already been paid', 'real8-gateway'));
        }

        if ($payment->status === 'expired') {
            return new WP_Error('expired', __('This payment has expired', 'real8-gateway'));
        }

        $lock_key = 'real8_manual_check_lock_' . absint($order_id);
        if (get_transient($lock_key)) {
            return new WP_Error('verification_throttled', __('Payment verification is pending. Please try again shortly.', 'real8-gateway'));
        }
        set_transient($lock_key, 1, 20);

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
            $asset_issuer,
            null,
            $this->scan_not_before($payment)
        );

        if (is_wp_error($result)) {
            return $result;
        }

        if ($result && !$this->tx_is_plausibly_for_payment($payment, $result)) {
            $result = false;
        }

        if ($result) {
            return $this->mark_payment_confirmed($payment, $result, $asset_code);
        }

        return false;
    }

    /**
     * A matching on-chain tx must not predate the payment row by more than a
     * minute. The memo is reused across payment rows of the same order, so an old
     * (e.g. already refunded) transaction could otherwise confirm a new row
     * Both the database row and Horizon timestamps are UTC; a one-minute
     * allowance covers clock skew without admitting yesterday's payments.
     *
     * @param object $payment Payment row
     * @param array  $result  check_payment() result (has created_at from Horizon)
     * @return bool
     */
    private function tx_is_plausibly_for_payment($payment, $result) {
        $row_ts = isset($payment->created_at) ? strtotime((string) $payment->created_at) : false;
        $tx_ts  = isset($result['created_at']) ? strtotime((string) $result['created_at']) : false;
        if (!$row_ts || !$tx_ts) {
            return false;
        }
        if ($tx_ts < $row_ts - 60) {
            real8_gateway_log(sprintf('REAL8 Gateway: ignoring tx %s dated %s for payment row %d created %s (too old)', isset($result['tx_hash']) ? $result['tx_hash'] : '?', (string) $result['created_at'], (int) $payment->id, (string) $payment->created_at));
            return false;
        }
        return true;
    }

    /**
     * Get pending payments count
     *
     * @return int Count of pending payments
     */
    public function get_pending_count() {
        global $wpdb;
        $table = $wpdb->prefix . 'real8_payments';

        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE status = 'pending'", $table));
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
        $counts = $wpdb->get_results($wpdb->prepare("SELECT status, COUNT(*) as count FROM %i GROUP BY status", $table)
        );

        foreach ($counts as $row) {
            $stats[$row->status] = (int) $row->count;
            $stats['total'] += (int) $row->count;
        }

        // Per-token statistics
        $token_stats = $wpdb->get_results($wpdb->prepare("SELECT asset_code,
                    COUNT(*) as total_count,
                    SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_count,
                    SUM(CASE WHEN status = 'confirmed' THEN amount_token ELSE 0 END) as total_received,
                    SUM(CASE WHEN status = 'confirmed' THEN amount_usd ELSE 0 END) as total_usd
             FROM %i
             GROUP BY asset_code", $table)
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

// Initialize immediately at include time (v4.5.2). This file is included
// from the plugin's init() callback, which itself runs ON the init action;
// registering another init callback at the same priority from inside the
// running hook is silently skipped by WP_Hook, so the monitor never
// instantiated on cron/front-end loads and real8_gateway_check_payments had
// no callback at all.
// The Stellar API class this constructor needs is required before this file
// in include_files(), so direct instantiation is safe here.
REAL8_Payment_Monitor::get_instance();
