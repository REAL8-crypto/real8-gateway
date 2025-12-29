<?php
/**
 * Stellar Payment API
 *
 * Handles REAL8 price fetching and Stellar payment verification
 *
 * @package REAL8_Gateway
 */

if (!defined('ABSPATH')) {
    exit;
}

class REAL8_Stellar_Payment_API {
    private static $instance = null;
    private $price_cache_key = 'real8_gateway_price_cache';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Constructor
    }

    /**
     * Get current REAL8 price in USD
     *
     * @param bool $force_refresh Force refresh from API
     * @return float|WP_Error Price in USD or error
     */
    public function get_real8_price($force_refresh = false) {
        // Check cache first
        if (!$force_refresh) {
            $cached = get_transient($this->price_cache_key);
            if ($cached !== false) {
                return (float) $cached;
            }
        }

        // Fetch from API
        $price = $this->fetch_price_from_api();

        if (is_wp_error($price)) {
            // Try to return last known good price
            $last_price = get_option('real8_gateway_last_known_price');
            if ($last_price) {
                return (float) $last_price;
            }
            return $price; // Return the error
        }

        // Cache the price
        set_transient($this->price_cache_key, $price, REAL8_GW_PRICE_CACHE_SECONDS);
        update_option('real8_gateway_last_known_price', $price);

        return $price;
    }

    /**
     * Fetch price from api.real8.org
     *
     * @return float|WP_Error
     */
    private function fetch_price_from_api() {
        $response = wp_remote_get(REAL8_GW_PRICING_API, array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/json',
            ),
        ));

        if (is_wp_error($response)) {
            return new WP_Error('api_error', 'Failed to connect to pricing API: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('api_error', 'Pricing API returned status ' . $code);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data || !isset($data['REAL8_USDC']['priceInUSD'])) {
            return new WP_Error('api_error', 'Invalid response from pricing API');
        }

        $price = (float) $data['REAL8_USDC']['priceInUSD'];

        if ($price <= 0) {
            return new WP_Error('api_error', 'Invalid price from API: ' . $price);
        }

        return $price;
    }

    /**
     * Calculate REAL8 amount for given USD amount
     *
     * @param float $usd_amount Amount in USD
     * @param bool $include_buffer Include price buffer for volatility
     * @return array|WP_Error Array with amount and price details or error
     */
    public function calculate_real8_amount($usd_amount, $include_buffer = true) {
        $price = $this->get_real8_price();

        if (is_wp_error($price)) {
            return $price;
        }

        // Apply price buffer (customers pay slightly more to account for volatility)
        $buffer_multiplier = $include_buffer ? (1 - (REAL8_GW_PRICE_BUFFER_PERCENT / 100)) : 1;
        $effective_price = $price * $buffer_multiplier;

        $real8_amount = $usd_amount / $effective_price;

        return array(
            'real8_amount' => round($real8_amount, 7), // Stellar supports 7 decimals
            'usd_amount' => $usd_amount,
            'price_per_real8' => $price,
            'effective_price' => $effective_price,
            'buffer_percent' => REAL8_GW_PRICE_BUFFER_PERCENT,
        );
    }

    /**
     * Generate unique payment memo for an order
     *
     * @param int $order_id WooCommerce order ID
     * @return string Unique memo (max 28 chars for Stellar text memo)
     */
    public function generate_payment_memo($order_id) {
        // Format: R8-{order_id}-{random}
        // Keep it short for Stellar text memo limit (28 chars)
        $random = substr(md5(uniqid(mt_rand(), true)), 0, 6);
        return 'R8-' . $order_id . '-' . $random;
    }

    /**
     * Check if a payment has been received for a given memo
     *
     * @param string $merchant_address The merchant's Stellar address
     * @param string $memo The payment memo to look for
     * @param float $expected_amount Expected REAL8 amount
     * @param string $since_cursor Cursor for pagination (optional)
     * @return array|false Payment details or false if not found
     */
    public function check_payment($merchant_address, $memo, $expected_amount, $since_cursor = null) {
        // Query Stellar Horizon for payments to this address
        $url = REAL8_GW_HORIZON_URL . '/accounts/' . $merchant_address . '/payments';
        $params = array(
            'limit' => 100,
            'order' => 'desc',
        );

        if ($since_cursor) {
            $params['cursor'] = $since_cursor;
        }

        $url .= '?' . http_build_query($params);

        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'headers' => array(
                'Accept' => 'application/json',
            ),
        ));

        if (is_wp_error($response)) {
            error_log('REAL8 Gateway: Failed to check payments: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            error_log('REAL8 Gateway: Horizon API returned status ' . $code);
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['_embedded']['records'])) {
            return false;
        }

        // Look through payments for matching memo and amount
        foreach ($data['_embedded']['records'] as $payment) {
            // Only process payment operations
            if ($payment['type'] !== 'payment') {
                continue;
            }

            // Check if it's REAL8 token
            if (!isset($payment['asset_code']) || $payment['asset_code'] !== REAL8_GW_ASSET_CODE) {
                continue;
            }

            if (!isset($payment['asset_issuer']) || $payment['asset_issuer'] !== REAL8_GW_ASSET_ISSUER) {
                continue;
            }

            // Get transaction details to check memo
            $tx_hash = $payment['transaction_hash'];
            $tx_details = $this->get_transaction_details($tx_hash);

            if (!$tx_details) {
                continue;
            }

            // Check memo matches
            $tx_memo = isset($tx_details['memo']) ? $tx_details['memo'] : '';
            if ($tx_memo !== $memo) {
                continue;
            }

            // Check amount (allow small difference for rounding)
            $received_amount = (float) $payment['amount'];
            $tolerance = 0.0000001; // 1 stroop tolerance

            if ($received_amount >= ($expected_amount - $tolerance)) {
                return array(
                    'tx_hash' => $tx_hash,
                    'amount' => $received_amount,
                    'from' => $payment['from'],
                    'created_at' => $payment['created_at'],
                    'paging_token' => $payment['paging_token'],
                );
            }
        }

        return false;
    }

    /**
     * Get transaction details from Stellar
     *
     * @param string $tx_hash Transaction hash
     * @return array|false Transaction details or false
     */
    public function get_transaction_details($tx_hash) {
        $url = REAL8_GW_HORIZON_URL . '/transactions/' . $tx_hash;

        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/json',
            ),
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return $data;
    }

    /**
     * Validate a Stellar address
     *
     * @param string $address Stellar public key
     * @return bool True if valid
     */
    public function validate_stellar_address($address) {
        // Stellar addresses start with G and are 56 characters
        if (strlen($address) !== 56) {
            return false;
        }

        if ($address[0] !== 'G') {
            return false;
        }

        // Check for valid base32 characters
        $valid_chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        for ($i = 0; $i < strlen($address); $i++) {
            if (strpos($valid_chars, $address[$i]) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if merchant address has REAL8 trustline
     *
     * @param string $address Stellar address
     * @return bool True if trustline exists
     */
    public function check_real8_trustline($address) {
        $url = REAL8_GW_HORIZON_URL . '/accounts/' . $address;

        $response = wp_remote_get($url, array(
            'timeout' => 10,
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['balances'])) {
            return false;
        }

        foreach ($data['balances'] as $balance) {
            if (isset($balance['asset_code']) &&
                $balance['asset_code'] === REAL8_GW_ASSET_CODE &&
                isset($balance['asset_issuer']) &&
                $balance['asset_issuer'] === REAL8_GW_ASSET_ISSUER) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clear price cache
     */
    public function clear_price_cache() {
        delete_transient($this->price_cache_key);
    }
}
