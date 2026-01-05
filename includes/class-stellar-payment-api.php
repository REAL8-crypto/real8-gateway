<?php
/**
 * Stellar Payment API
 *
 * Handles multi-token price fetching and Stellar payment verification.
 * Uses dual-source pricing: api.real8.org for REAL8/XLM/USDC,
 * Stellar Horizon orderbook for EURC/SLVR/GOLD/wREAL8.
 *
 * @package REAL8_Gateway
 * @version 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class REAL8_Stellar_Payment_API {
    /**
     * Compare two decimal strings at a given scale.
     * Returns 1 if $a > $b, 0 if equal, -1 if $a < $b.
     */
    private function dec_compare($a, $b, $scale = 7) {
        $a = is_string($a) ? trim($a) : (string) $a;
        $b = is_string($b) ? trim($b) : (string) $b;

        if (function_exists('bccomp')) {
            return bccomp($a, $b, (int) $scale);
        }

        // Fallback: float compare (best effort)
        $fa = (float) $a;
        $fb = (float) $b;
        if (abs($fa - $fb) < pow(10, -1 * (int) $scale)) return 0;
        return ($fa > $fb) ? 1 : -1;
    }

    /**
     * Add a small tolerance to a decimal string.
     */
    private function dec_add_tol($a, $tol = '0.000001', $scale = 7) {
        $a = is_string($a) ? trim($a) : (string) $a;

        if (function_exists('bcadd')) {
            return bcadd($a, (string) $tol, (int) $scale);
        }

        return (string) ((float) $a + (float) $tol);
    }

    /**
     * Normalize a numeric string to a fixed decimal scale.
     */
    private function normalize_dec($n, $scale = 7) {
        $n = is_string($n) ? trim($n) : (string) $n;
        if ($n === '' || !is_numeric($n)) {
            return number_format(0, (int) $scale, '.', '');
        }
        return number_format((float) $n, (int) $scale, '.', '');
    }

    private function dec_sub($a, $b, $scale = 7) {
        $a = $this->normalize_dec($a, $scale);
        $b = $this->normalize_dec($b, $scale);
        if (function_exists('bcsub')) {
            return bcsub($a, $b, (int) $scale);
        }
        return $this->normalize_dec(((float) $a - (float) $b), $scale);
    }

    /**
     * Compute max(percent-of-expected, min-abs) tolerance.
     * Stored in standalone options so it works for cron/CLI too.
     */
    private function get_amount_tolerance($expected_amount, $scale = 7) {
        $expected_amount = $this->normalize_dec($expected_amount, $scale);

        $percent = (float) get_option('real8_gateway_amount_tolerance_percent', 1.0);
        $min_abs = (string) get_option('real8_gateway_amount_tolerance_min', '0.0000001');
        $min_abs = $this->normalize_dec($min_abs, $scale);

        if ($percent <= 0 && $this->dec_compare($min_abs, '0', $scale) <= 0) {
            return $this->normalize_dec('0', $scale);
        }

        $tol_pct = $this->normalize_dec(((float) $expected_amount) * max(0.0, $percent) / 100.0, $scale);

        return ($this->dec_compare($tol_pct, $min_abs, $scale) >= 0) ? $tol_pct : $min_abs;
    }

    private function min_expected_with_tolerance($expected_amount, $scale = 7) {
        $expected_amount = $this->normalize_dec($expected_amount, $scale);
        $tol = $this->get_amount_tolerance($expected_amount, $scale);

        // expected - tol (clamped to >= 0)
        $min_expected = $this->dec_sub($expected_amount, $tol, $scale);
        if ($this->dec_compare($min_expected, '0', $scale) < 0) {
            return $this->normalize_dec('0', $scale);
        }
        return $min_expected;
    }

    /**
     * Fetch JSON from Horizon and return decoded array.
     */
    private function horizon_get_json($url, $timeout = 15) {
        $response = wp_remote_get($url, array(
            'timeout' => $timeout,
            'headers' => array(
                'Accept' => 'application/json',
            ),
        ));

        if (is_wp_error($response)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('REAL8 Gateway: Horizon request error: ' . $response->get_error_message() . ' URL: ' . $url);
            }
            return new WP_Error('horizon_error', $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('REAL8 Gateway: Horizon status ' . $code . ' URL: ' . $url . ' BODY: ' . substr($body, 0, 300));
            }
            return new WP_Error('horizon_status', 'Horizon returned HTTP ' . $code);
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return new WP_Error('horizon_json', 'Invalid JSON from Horizon');
        }
        return $data;
    }

    /**
     * Robust check by scanning recent transactions (memo is available directly in /transactions).
     * This supports payment + path_payment operations reliably.
     */
    private function check_payment_via_transactions($merchant_address, $memo, $expected_amount, $asset_code = 'REAL8', $asset_issuer = null) {
        // v3.0.7-style verification: paginate /transactions to find memo first, then inspect ops for the matching payment
        $memo = trim((string) $memo);
        $expected_amount = is_string($expected_amount) ? trim($expected_amount) : (string) $expected_amount;

        // Apply tolerance by reducing the minimum acceptable amount.
        $min_expected = $this->min_expected_with_tolerance($expected_amount, 7);
        $is_native = REAL8_Token_Registry::is_native($asset_code);

        $cursor = null;
        $pages = 0;
        $found_tx = null;
        $tx_hash = '';

        while ($pages < 5) {
            $params = array('order' => 'desc', 'limit' => 200);
            if ($cursor) {
                $params['cursor'] = $cursor;
            }

            $tx_url = REAL8_GW_HORIZON_URL . '/accounts/' . rawurlencode($merchant_address) . '/transactions?' . http_build_query($params);
            $tx_data = $this->horizon_get_json($tx_url, 15);
            if (is_wp_error($tx_data)) {
                return $tx_data;
            }

            $records = isset($tx_data['_embedded']['records']) && is_array($tx_data['_embedded']['records']) ? $tx_data['_embedded']['records'] : array();
            if (empty($records)) {
                break;
            }

            foreach ($records as $tx) {
                if (isset($tx['successful']) && !$tx['successful']) {
                    continue;
                }

                // Only consider text memos
                if (isset($tx['memo_type']) && (string) $tx['memo_type'] !== 'text') {
                    continue;
                }

                $tx_memo = isset($tx['memo']) ? (string) $tx['memo'] : '';
                if (trim($tx_memo) !== $memo) {
                    continue;
                }

                $tx_hash = isset($tx['hash']) ? (string) $tx['hash'] : '';
                if (!$tx_hash) {
                    continue;
                }

                $found_tx = $tx;
                break 2;
            }

            // paginate using last paging_token
            $last = end($records);
            $cursor = (is_array($last) && isset($last['paging_token'])) ? (string) $last['paging_token'] : '';
            if (!$cursor) {
                break;
            }

            $pages++;
        }

        if (!$found_tx || !$tx_hash) {
            return false;
        }

        // Fetch operations for the matching tx and find the payment op to the merchant
        $ops_url = REAL8_GW_HORIZON_URL . '/transactions/' . rawurlencode($tx_hash) . '/operations?order=asc&limit=200';
        $ops_data = $this->horizon_get_json($ops_url, 15);
        if (is_wp_error($ops_data)) {
            return $ops_data;
        }

        $ops_records = isset($ops_data['_embedded']['records']) && is_array($ops_data['_embedded']['records']) ? $ops_data['_embedded']['records'] : array();
        if (empty($ops_records)) {
            return false;
        }

        foreach ($ops_records as $op) {
            $type = isset($op['type']) ? (string) $op['type'] : '';
            if (!in_array($type, array('payment', 'path_payment_strict_receive', 'path_payment_strict_send', 'path_payment'), true)) {
                continue;
            }

            $to = isset($op['to']) ? (string) $op['to'] : '';
            if ($to !== $merchant_address) {
                continue;
            }

            // Asset checks
            if ($is_native) {
                // XLM: asset_type must be native
                if (isset($op['asset_type']) && (string) $op['asset_type'] !== 'native') {
                    continue;
                }
                // Some op payloads may omit asset_type; if asset_code exists, it's not native
                if (!isset($op['asset_type']) && isset($op['asset_code'])) {
                    continue;
                }
            } else {
                if (!isset($op['asset_code']) || strtoupper((string) $op['asset_code']) !== strtoupper((string) $asset_code)) {
                    continue;
                }
                if ($asset_issuer && (!isset($op['asset_issuer']) || (string) $op['asset_issuer'] !== (string) $asset_issuer)) {
                    continue;
                }
            }

            // Amount: prefer 'amount' (destination amount).
            $candidate = isset($op['amount']) ? (string) $op['amount'] : '';
            if (!$candidate && isset($op['source_amount'])) {
                $candidate = (string) $op['source_amount'];
            }
            if (!$candidate) {
                continue;
            }

            // Compare candidate + small stroop tolerance >= minimum acceptable (expected minus configured tolerance)
            $candidate_plus = $this->dec_add_tol($candidate, '0.000001', 7);
            if ($this->dec_compare($candidate_plus, $min_expected, 7) >= 0) {
                return array(
                    'tx_hash' => $tx_hash,
                    'amount' => (float) $candidate,
                    'from' => isset($op['from']) ? (string) $op['from'] : '',
                    'created_at' => isset($found_tx['created_at']) ? (string) $found_tx['created_at'] : '',
                    'paging_token' => isset($found_tx['paging_token']) ? (string) $found_tx['paging_token'] : '',
                    'asset_code' => $is_native ? 'XLM' : (string) $asset_code,
                    'asset_issuer' => $is_native ? null : (string) $asset_issuer,
                );
            }
        }

        return false;
    }


    private static $instance = null;

    /**
     * Cache duration for prices (5 minutes)
     */
    const PRICE_CACHE_DURATION = 300;

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
     * Get current price for any supported token in USD
     *
     * @param string $token_code Token code (e.g., 'REAL8', 'XLM', 'EURC')
     * @param bool $force_refresh Force refresh from API/Horizon
     * @return float|WP_Error Price in USD or error
     */
    public function get_token_price($token_code, $force_refresh = false) {
        $token_code = strtoupper($token_code);
        $cache_key = 'stellar_gw_price_' . $token_code;

        // Check cache first
        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                return (float) $cached;
            }
        }

        // Fetch price based on token source
        if (REAL8_Token_Registry::is_api_priced($token_code)) {
            $price = $this->fetch_price_from_api($token_code);
        } else {
            $price = $this->fetch_price_from_horizon($token_code);
        }

        if (is_wp_error($price)) {
            // Try to return last known good price
            $last_price = get_option('stellar_gw_last_price_' . $token_code);
            if ($last_price) {
                return (float) $last_price;
            }

            // Try fallback price
            $fallback_prices = REAL8_Token_Registry::get_fallback_prices();
            if (isset($fallback_prices[$token_code])) {
                return (float) $fallback_prices[$token_code];
            }

            return $price; // Return the error
        }

        // Cache the price
        set_transient($cache_key, $price, self::PRICE_CACHE_DURATION);
        update_option('stellar_gw_last_price_' . $token_code, $price);

        return $price;
    }

    /**
     * Get prices for all enabled tokens
     *
     * @param array $token_codes Array of token codes
     * @param bool $force_refresh Force refresh
     * @return array Token code => price array
     */
    public function get_all_token_prices($token_codes = null, $force_refresh = false) {
        if ($token_codes === null) {
            $token_codes = REAL8_Token_Registry::get_all_token_codes();
        }

        $prices = array();
        foreach ($token_codes as $code) {
            $price = $this->get_token_price($code, $force_refresh);
            $prices[$code] = is_wp_error($price) ? null : $price;
        }

        return $prices;
    }

    /**
     * Fetch price from api.real8.org
     * Used for: REAL8, XLM, USDC
     *
     * @param string $token_code Token code
     * @return float|WP_Error
     */
    private function fetch_price_from_api($token_code) {
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

        if (!$data) {
            return new WP_Error('api_error', 'Invalid response from pricing API');
        }

        // Parse response based on token
        switch ($token_code) {
            case 'REAL8':
                if (isset($data['REAL8_USDC']['priceInUSD'])) {
                    return (float) $data['REAL8_USDC']['priceInUSD'];
                }
                if (isset($data['REAL8']['priceInUSD'])) {
                    return (float) $data['REAL8']['priceInUSD'];
                }
                break;

            case 'XLM':
                if (isset($data['XLM']['priceInUSD'])) {
                    return (float) $data['XLM']['priceInUSD'];
                }
                // XLM might need to be calculated from USDC rate
                break;

            case 'USDC':
                // USDC is pegged to $1
                return 1.0;
        }

        return new WP_Error('api_error', 'Price not found for ' . $token_code);
    }

    /**
     * Fetch price from Stellar Horizon orderbook
     * Used for: EURC, SLVR, GOLD, wREAL8
     *
     * Uses triangular pricing: TOKEN/XLM × XLM/USD = TOKEN/USD
     *
     * @param string $token_code Token code
     * @return float|WP_Error
     */
    private function fetch_price_from_horizon($token_code) {
        // First get XLM/USD price
        $xlm_usd = $this->get_token_price('XLM');
        if (is_wp_error($xlm_usd)) {
            // Use fallback XLM price
            $xlm_usd = 0.45;
        }

        // Get TOKEN/XLM price from orderbook
        $token_xlm = $this->fetch_orderbook_price($token_code, 'XLM');

        if (is_wp_error($token_xlm)) {
            return $token_xlm;
        }

        // Calculate USD price: TOKEN_USD = TOKEN_XLM × XLM_USD
        $token_usd = $token_xlm * $xlm_usd;

        return $token_usd;
    }

    /**
     * Fetch mid-price from Stellar Horizon orderbook
     *
     * @param string $selling_token Token being sold
     * @param string $buying_token Token being bought (usually XLM)
     * @return float|WP_Error Mid-price or error
     */
    private function fetch_orderbook_price($selling_token, $buying_token) {
        // Build query parameters
        $params = array_merge(
            REAL8_Token_Registry::get_horizon_params($selling_token, 'selling'),
            REAL8_Token_Registry::get_horizon_params($buying_token, 'buying'),
            array('limit' => 1)
        );

        $url = REAL8_GW_HORIZON_URL . '/order_book?' . http_build_query($params);

        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/json',
            ),
        ));

        if (is_wp_error($response)) {
            return new WP_Error('horizon_error', 'Failed to fetch orderbook: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('horizon_error', 'Horizon API returned status ' . $code);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // Calculate mid-price from best bid and ask
        $ask_price = isset($data['asks'][0]['price']) ? (float) $data['asks'][0]['price'] : 0;
        $bid_price = isset($data['bids'][0]['price']) ? (float) $data['bids'][0]['price'] : 0;

        if ($ask_price <= 0 && $bid_price <= 0) {
            return new WP_Error('no_liquidity', 'No orderbook liquidity for ' . $selling_token . '/' . $buying_token);
        }

        // Use mid-price if both available, otherwise use whichever is available
        if ($ask_price > 0 && $bid_price > 0) {
            return ($ask_price + $bid_price) / 2;
        }

        return $ask_price > 0 ? $ask_price : $bid_price;
    }

    /**
     * Calculate token amount for given USD amount
     *
     * @param float $usd_amount Amount in USD
     * @param string $token_code Token code
     * @param bool $include_buffer Include price buffer for volatility
     * @return array|WP_Error Array with amount and price details or error
     */
    public function calculate_token_amount($usd_amount, $token_code, $include_buffer = true) {
        $token_code = strtoupper($token_code);
        $price = $this->get_token_price($token_code);

        if (is_wp_error($price)) {
            return $price;
        }

        // Apply price buffer (customers pay slightly more to account for volatility)
        $buffer_multiplier = $include_buffer ? (1 - (REAL8_GW_PRICE_BUFFER_PERCENT / 100)) : 1;
        $effective_price = $price * $buffer_multiplier;

        $token_amount = $usd_amount / $effective_price;

        return array(
            'token_amount' => round($token_amount, 7), // Stellar supports 7 decimals
            'token_code' => $token_code,
            'usd_amount' => $usd_amount,
            'price_per_token' => $price,
            'effective_price' => $effective_price,
            'buffer_percent' => REAL8_GW_PRICE_BUFFER_PERCENT,
        );
    }

    /**
     * Legacy method for backward compatibility
     * @deprecated 3.0.0 Use get_token_price('REAL8') instead
     */
    public function get_real8_price($force_refresh = false) {
        return $this->get_token_price('REAL8', $force_refresh);
    }

    /**
     * Legacy method for backward compatibility
     * @deprecated 3.0.0 Use calculate_token_amount() instead
     */
    public function calculate_real8_amount($usd_amount, $include_buffer = true) {
        $result = $this->calculate_token_amount($usd_amount, 'REAL8', $include_buffer);

        if (is_wp_error($result)) {
            return $result;
        }

        // Map to old format for backward compatibility
        return array(
            'real8_amount' => $result['token_amount'],
            'usd_amount' => $result['usd_amount'],
            'price_per_real8' => $result['price_per_token'],
            'effective_price' => $result['effective_price'],
            'buffer_percent' => $result['buffer_percent'],
        );
    }

    /**
     * Generate unique payment memo for an order
     *
     * @param int $order_id WooCommerce order ID
     * @return string Unique memo (max 28 chars for Stellar text memo)
     */
    public function generate_payment_memo($order_id) {
        // Format: S-{order_id}-{random} (S for Stellar, generic)
        // Keep it short for Stellar text memo limit (28 chars)
        $random = substr(md5(uniqid(mt_rand(), true)), 0, 6);
        return 'S-' . $order_id . '-' . $random;
    }

    /**
     * Check if a payment has been received for a given memo
     *
     * @param string $merchant_address The merchant's Stellar address
     * @param string $memo The payment memo to look for
     * @param float $expected_amount Expected token amount
     * @param string $asset_code Asset code to look for
     * @param string|null $asset_issuer Asset issuer (null for XLM)
     * @param string $since_cursor Cursor for pagination (optional)
     * @return array|false Payment details or false if not found
     */
    public function check_payment($merchant_address, $memo, $expected_amount, $asset_code = 'REAL8', $asset_issuer = null, $since_cursor = null) {
        // Robust implementation: check recent transactions first (memo available directly).
        $result = $this->check_payment_via_transactions($merchant_address, $memo, $expected_amount, $asset_code, $asset_issuer);

        if (is_wp_error($result)) {
            return $result;
        }

        if ($result) {
            return $result;
        }

        // Fallback to legacy payments scan (best effort, may be skipped if too many payments)
        return $this->check_payment_legacy($merchant_address, $memo, $expected_amount, $asset_code, $asset_issuer, $since_cursor);
    }


    private function check_payment_legacy($merchant_address, $memo, $expected_amount, $asset_code = 'REAL8', $asset_issuer = null, $since_cursor = null) {
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
            error_log('Stellar Gateway: Failed to check payments: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            error_log('Stellar Gateway: Horizon API returned status ' . $code);
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['_embedded']['records'])) {
            return false;
        }

        // Determine if we're looking for native XLM or a credit asset
        $is_native = REAL8_Token_Registry::is_native($asset_code);

        // Look through payments for matching memo and amount
        foreach ($data['_embedded']['records'] as $payment) {
            // Only process payment operations
            if ($payment['type'] !== 'payment') {
                continue;
            }

            // Check asset type matches
            if ($is_native) {
                // Looking for XLM (native)
                if ($payment['asset_type'] !== 'native') {
                    continue;
                }
            } else {
                // Looking for a credit asset
                if (!isset($payment['asset_code']) || $payment['asset_code'] !== $asset_code) {
                    continue;
                }

                if (!isset($payment['asset_issuer']) || $payment['asset_issuer'] !== $asset_issuer) {
                    continue;
                }
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

            // Check amount against minimum acceptable (expected minus configured tolerance)
            $min_expected = $this->min_expected_with_tolerance($expected_amount, 7);
            $candidate_plus = $this->dec_add_tol((string) $payment['amount'], '0.000001', 7);

            if ($this->dec_compare($candidate_plus, $min_expected, 7) >= 0) {
                return array(
                    'tx_hash' => $tx_hash,
                    'amount' => (float) $payment['amount'],
                    'from' => $payment['from'],
                    'created_at' => $payment['created_at'],
                    'paging_token' => $payment['paging_token'],
                    'asset_code' => $is_native ? 'XLM' : $payment['asset_code'],
                    'asset_issuer' => $is_native ? null : $payment['asset_issuer'],
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
     * Check if address has trustline for a specific token
     *
     * @param string $address Stellar address
     * @param string $token_code Token code
     * @return bool True if trustline exists (or native XLM)
     */
    public function check_token_trustline($address, $token_code) {
        // XLM doesn't need a trustline
        if (REAL8_Token_Registry::is_native($token_code)) {
            return true;
        }

        $token = REAL8_Token_Registry::get_token($token_code);
        if (!$token) {
            return false;
        }

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
                $balance['asset_code'] === $token['code'] &&
                isset($balance['asset_issuer']) &&
                $balance['asset_issuer'] === $token['issuer']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check trustlines for multiple tokens
     *
     * @param string $address Stellar address
     * @param array $token_codes Array of token codes
     * @return array Token code => bool (has trustline)
     */
    public function check_multiple_trustlines($address, $token_codes) {
        $url = REAL8_GW_HORIZON_URL . '/accounts/' . $address;

        $response = wp_remote_get($url, array(
            'timeout' => 10,
        ));

        if (is_wp_error($response)) {
            // Return all false on error
            return array_fill_keys($token_codes, false);
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return array_fill_keys($token_codes, false);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        $results = array();

        foreach ($token_codes as $token_code) {
            // XLM doesn't need a trustline
            if (REAL8_Token_Registry::is_native($token_code)) {
                $results[$token_code] = true;
                continue;
            }

            $token = REAL8_Token_Registry::get_token($token_code);
            if (!$token) {
                $results[$token_code] = false;
                continue;
            }

            $has_trustline = false;
            if (isset($data['balances'])) {
                foreach ($data['balances'] as $balance) {
                    if (isset($balance['asset_code']) &&
                        $balance['asset_code'] === $token['code'] &&
                        isset($balance['asset_issuer']) &&
                        $balance['asset_issuer'] === $token['issuer']) {
                        $has_trustline = true;
                        break;
                    }
                }
            }

            $results[$token_code] = $has_trustline;
        }

        return $results;
    }

    /**
     * Legacy method for backward compatibility
     * @deprecated 3.0.0 Use check_token_trustline() instead
     */
    public function check_real8_trustline($address) {
        return $this->check_token_trustline($address, 'REAL8');
    }

    /**
     * Clear price cache for a specific token or all tokens
     *
     * @param string|null $token_code Token code or null for all
     */
    public function clear_price_cache($token_code = null) {
        if ($token_code) {
            delete_transient('stellar_gw_price_' . strtoupper($token_code));
        } else {
            foreach (REAL8_Token_Registry::get_all_token_codes() as $code) {
                delete_transient('stellar_gw_price_' . $code);
            }
        }
    }
}
