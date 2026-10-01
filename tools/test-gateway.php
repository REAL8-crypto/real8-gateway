<?php
/** Run with wp eval-file tools/test-gateway.php on a disposable WordPress site. */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) {
    exit;
}

$checks = 0;
$check = function($condition, $message) use (&$checks) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
    WP_CLI::line('PASS ' . $message);
};
$json_response = function($body, $code = 200) {
    return array('headers' => array(), 'body' => wp_json_encode($body), 'response' => array('code' => $code, 'message' => 'Fixture'), 'cookies' => array());
};
$state = array('price' => 1, 'outage' => false, 'transactions' => array(), 'operations' => array(), 'payments' => array(), 'intent_url' => 'https://app.real8.org/pay/pi_fixture', 'requests' => array());
add_filter('pre_http_request', function($pre, $args, $url) use (&$state, $json_response) {
    $state['requests'][] = array($url, $args);
    if (!empty($state['during_request']) && strpos($url, $state['during_request'][0]) !== false) {
        $callback = $state['during_request'][1];
        $state['during_request'] = null;
        $callback();
    }
    if ($state['outage']) {
        return new WP_Error('fixture_outage', 'Audit fixture: service unavailable');
    }
    if (strpos($url, '/prices') !== false) {
        return $json_response(array('REAL8_USDC' => array('priceInUSD' => $state['price'])));
    }
    if (strpos($url, '/operations?') !== false) {
        $hash = basename(dirname(parse_url($url, PHP_URL_PATH)));
        return $json_response(array('_embedded' => array('records' => $state['operations_by_hash'][$hash] ?? $state['operations'])));
    }
    if (strpos($url, '/transactions?') !== false) {
        $records = strpos($url, 'cursor=') !== false && empty($state['repeat_history']) ? array() : $state['transactions'];
        return $json_response(array('_embedded' => array('records' => $records)));
    }
    if (strpos($url, '/payments?') !== false) {
        return $json_response(array('_embedded' => array('records' => $state['payments'])));
    }
    if (strpos($url, '/transactions/') !== false) {
        return $json_response(array('successful' => true, 'memo_type' => 'text', 'memo' => 'audit-memo'));
    }
    if (strpos($url, '/payment-intents') !== false) {
        return $json_response(array('intent_id' => 'pi_fixture', 'payment_url' => $state['intent_url'], 'expires_at' => gmdate('c', time() + 1800)), 201);
    }
    // All network access in this suite is intercepted, including unexpected calls.
    return new WP_Error('unexpected_http', 'Unexpected HTTP request: ' . $url);
}, 10, 3);
add_filter('pre_wp_mail', '__return_true');

$settings = array('enabled' => 'yes', 'merchant_address' => REAL8_GW_ASSET_ISSUER, 'price_buffer' => 0, 'payment_timeout' => 30, 'payment_intents' => 'no');
update_option('woocommerce_currency', 'USD');
update_option('woocommerce_real8_payment_settings', $settings);
update_option('real8_gateway_amount_tolerance_percent', 0);
update_option('real8_gateway_amount_tolerance_min', '0');
$api = REAL8_Stellar_Payment_API::get_instance();
$gateway = new REAL8_WC_Payment_Gateway();
$monitor = REAL8_Payment_Monitor::get_instance();
global $wpdb;
$table = $wpdb->prefix . 'real8_payments';
$claims = $wpdb->prefix . 'real8_transaction_claims';
$orders = array();
$create_order = function($currency = 'USD', $total = 10) use (&$orders) {
    $order = wc_create_order();
    $order->set_currency($currency);
    $order->set_total($total);
    $order->set_payment_method('real8_payment');
    $order->save();
    $orders[] = $order->get_id();
    return $order;
};

try {
    $check(get_option('real8_gateway_db_version') === '4.0.0', 'schema migration runs on normal plugin loading');
    $check((bool) has_action('real8_gateway_check_payments') && (bool) wp_next_scheduled('real8_gateway_check_payments'), 'cron monitor registered and scheduled');
    $check((bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $claims)), 'transaction claim table exists');
    $check(class_exists('REAL8_Blocks_Payment_Method'), 'Checkout block integration loads');
    $check(has_action('wc_ajax_real8_check_payment_status', array(REAL8_Gateway::get_instance(), 'wc_ajax_check_payment_status')) !== false
        && has_action('wc_ajax_stellar_get_token_prices', array(REAL8_Gateway::get_instance(), 'wc_ajax_get_token_prices')) !== false,
        'WC-AJAX endpoints are registered without waiting for WooCommerce to build the gateway');
    $blocks = new REAL8_Blocks_Payment_Method();
    $blocks->initialize();
    $check($blocks->is_active(), 'Checkout block method active for a configured USD store');

    foreach (array(0, -1, 'invalid', array(1)) as $invalid) {
        $state['price'] = $invalid;
        $check(is_wp_error($api->get_token_price('REAL8', true)), 'invalid pricing response rejected: ' . wp_json_encode($invalid));
    }
    $state['price'] = 1;
    $state['outage'] = true;
    $api->clear_price_cache();
    update_option('stellar_gw_last_price_REAL8', 999);
    $check(is_wp_error($api->get_token_price('REAL8', true)), 'outage cannot use a stale or hardcoded rate');
    $state['outage'] = false;
    $settings['price_buffer'] = 10;
    update_option('woocommerce_real8_payment_settings', $settings);
    $quote = $api->calculate_token_amount(9, 'REAL8');
    $check(abs($quote['token_amount'] - 10) < 0.0000001, 'configured price buffer changes the quote');
    $check(is_wp_error($api->calculate_token_amount(0, 'REAL8')), 'zero payment totals rejected');
    $settings['price_buffer'] = 0;
    update_option('woocommerce_real8_payment_settings', $settings);

    $euro = $create_order('EUR');
    $check($gateway->process_payment($euro->get_id())['result'] === 'fail', 'non-USD order rejected before quoting');
    update_option('woocommerce_currency', 'EUR');
    $check(!$gateway->is_available() && !$blocks->is_active(), 'classic and block gateway unavailable in a non-USD store');
    update_option('woocommerce_currency', 'USD');

    $order = $create_order();
    $result = $gateway->process_payment($order->get_id());
    $check($result['result'] === 'success' && strpos($result['redirect'], 'app.real8.org') === false, 'local instructions work without hosted-service credentials');
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE order_id = %d', $table, $order->get_id()));
    $check($row && (float) $row->amount_token === 10.0, 'payment record and quote persisted');
    $gateway->process_payment($order->get_id());
    $same = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE order_id = %d', $table, $order->get_id()));
    $check($same->memo === $row->memo, 'valid pending checkout reuses its quote and memo');
    $check(count(array_filter($state['requests'], function($request) { return strpos($request[0], '/payment-intents') !== false; })) === 0, 'hosted service receives no requests without opt-in');

    $request = new WP_REST_Request('POST', '/real8-gateway/v1/check');
    $request->set_param('order_id', $order->get_id());
    $request->set_param('order_key', 'wrong-key');
    $count = count($state['requests']);
    $denied = rest_do_request($request);
    $check(!$denied->get_data()['success'] && count($state['requests']) === $count, 'invalid order key cannot inspect or verify payments');
    $request = new WP_REST_Request('POST', '/real8-gateway/v1/check');
    $request->set_param('order_id', $order->get_id());
    $request->set_param('order_key', array('bad'));
    $check(rest_do_request($request)->get_status() === 400, 'array-shaped REST credentials rejected by route schema');

    $hash = hash('sha256', 'real8-audit-' . $order->get_id());
    $tx = array('successful' => true, 'memo_type' => 'text', 'memo' => $row->memo, 'hash' => $hash, 'created_at' => gmdate('c'), 'paging_token' => '1');
    $op = array('type' => 'payment', 'to' => $settings['merchant_address'], 'asset_code' => 'REAL8', 'asset_issuer' => REAL8_GW_ASSET_ISSUER, 'amount' => '10.0000000', 'from' => REAL8_GW_ASSET_ISSUER);
    $state['transactions'] = array($tx);
    $state['operations'] = array($op);
    $check($monitor->manual_check_order($order->get_id()) === true, 'matching on-chain payment completes the order');
    $check(wc_get_order($order->get_id())->is_paid(), 'WooCommerce order is paid after verification');
    $check(!$monitor->expire_payment($row) && wc_get_order($order->get_id())->is_paid(), 'stale expiry cannot overwrite confirmation');
    $check($gateway->process_payment($order->get_id())['result'] === 'fail', 'paid order cannot be reopened at checkout');

    $other = $create_order();
    $state['transactions'] = array();
    $state['operations'] = array();
    $gateway->process_payment($other->get_id());
    $other_row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE order_id = %d', $table, $other->get_id()));
    $confirm = new ReflectionMethod($monitor, 'mark_payment_confirmed');
    $check($confirm->invoke($monitor, $other_row, array('tx_hash' => $hash, 'amount' => 10, 'from' => 'fixture', 'created_at' => gmdate('c')), 'REAL8') === false, 'a transaction hash cannot settle a second payment row');
    $check(!wc_get_order($other->get_id())->is_paid(), 'duplicate transaction leaves second order unpaid');
    $old_memo = $other_row->memo;
    $wpdb->update($table, array('expires_at' => gmdate('Y-m-d H:i:s', time() - 120)), array('id' => $other_row->id));
    $gateway->process_payment($other->get_id());
    $retry = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE order_id = %d', $table, $other->get_id()));
    $check($retry->memo !== $old_memo && (float) $retry->amount_token === 10.0, 'expired attempt gets a fresh memo while preserving its locked quote');
    $age = new ReflectionMethod($monitor, 'tx_is_plausibly_for_payment');
    $check(!$age->invoke($monitor, $retry, array('created_at' => gmdate('c', time() - 3600), 'tx_hash' => $hash)), 'old transaction cannot settle a fresh payment');

    // A newer memo-matched transaction with the wrong destination must not
    // hide a valid earlier payment (including path payments).
    $state['transactions'] = array($tx, $tx);
    $bad_hash = hash('sha256', 'bad-memo-match-' . $order->get_id());
    $state['transactions'][0]['hash'] = $bad_hash;
    $bad_op = $op;
    $bad_op['to'] = str_repeat('G', 56);
    $state['operations_by_hash'] = array($bad_hash => array($bad_op), $hash => array($op));
    $found = $api->check_payment($settings['merchant_address'], $row->memo, 10, 'REAL8', REAL8_GW_ASSET_ISSUER);
    $check(is_array($found) && $found['tx_hash'] === $hash, 'a bad recent memo match cannot hide a valid earlier payment');
    unset($state['operations_by_hash']);
    $state['operations'] = array($op);
    $state['operations'][0]['asset_issuer'] = str_repeat('G', 56);
    $check($api->check_payment($settings['merchant_address'], $row->memo, 10, 'REAL8', REAL8_GW_ASSET_ISSUER) === false, 'wrong asset issuer cannot settle a payment');
    $state['operations'] = array($op);
    $state['operations'][0]['type'] = 'path_payment_strict_receive';
    unset($state['operations'][0]['amount']);
    $state['operations'][0]['source_amount'] = '1000';
    $check($api->check_payment($settings['merchant_address'], $row->memo, 10, 'REAL8', REAL8_GW_ASSET_ISSUER) === false, 'path-payment source amount cannot stand in for destination amount');
    $other_tx = array_merge($tx, array('memo' => 'different-memo'));
    $state['transactions'] = array_fill(0, 200, $other_tx);
    $state['repeat_history'] = true;
    $limit = $api->check_payment($settings['merchant_address'], $row->memo, 10, 'REAL8', REAL8_GW_ASSET_ISSUER, null, time() - 60);
    $check(is_wp_error($limit) && $limit->get_error_code() === 'scan_limit', 'incomplete history scans cannot justify payment expiry');
    // The same busy account, but its history predates the payment: the scan
    // is complete after one page and the unpaid row may expire.
    $state['transactions'] = array_fill(0, 200, array_merge($other_tx, array('created_at' => gmdate('c', time() - 7200))));
    $count = count($state['requests']);
    $check($api->check_payment($settings['merchant_address'], $row->memo, 10, 'REAL8', REAL8_GW_ASSET_ISSUER, null, time() - 60) === false
        && count(array_filter(array_slice($state['requests'], $count), function($request) { return strpos($request[0], '/transactions?') !== false; })) === 1,
        'history older than the payment ends the scan after one page');
    $state['transactions'] = array_fill(0, 5, $other_tx);
    $check($api->check_payment($settings['merchant_address'], $row->memo, 10, 'REAL8', REAL8_GW_ASSET_ISSUER) === false, 'a short page is the end of the account history, not an incomplete scan');
    $state['repeat_history'] = false;
    $state['transactions'] = array();
    $state['operations'] = array();

    $late_order = $create_order();
    $gateway->process_payment($late_order->get_id());
    $late_row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE order_id = %d', $table, $late_order->get_id()));
    $wpdb->update($table, array('expires_at' => gmdate('Y-m-d H:i:s', time() - 120)), array('id' => $late_row->id));
    $late_row->expires_at = gmdate('Y-m-d H:i:s', time() - 120);
    $state['outage'] = true;
    $request = new WP_REST_Request('POST', '/real8-gateway/v1/check');
    $request->set_param('order_id', $late_order->get_id());
    $request->set_param('order_key', $late_order->get_order_key());
    $response = rest_do_request($request)->get_data();
    $check($response['data']['status'] === 'pending' && !wc_get_order($late_order->get_id())->is_paid(), 'deadline verification outage leaves the payment pending');
    $check(!empty($response['data']['check_error']) && stripos($response['data']['check_error'], 'fixture') === false, 'customers do not see transport error details');
    $count = count($state['requests']);
    rest_do_request($request);
    $check(count($state['requests']) === $count, 'expiry checks share the per-order service throttle');
    $state['outage'] = false;
    delete_transient('real8_manual_check_lock_' . $late_order->get_id());
    $late_hash = hash('sha256', 'late-order-' . $late_order->get_id());
    $state['transactions'] = array(array_merge($tx, array('memo' => $late_row->memo, 'hash' => $late_hash)));
    $state['operations'] = array($op);
    $single = new ReflectionMethod($monitor, 'check_single_payment');
    $single->invoke($monitor, $late_row, $settings['merchant_address']);
    $check(wc_get_order($late_order->get_id())->is_paid(), 'a matching payment is confirmed even when cron observes it after the deadline');
    $state['transactions'] = array();
    $state['operations'] = array();

    // Confirmed-row repair must recover interrupted completion and skip refunds.
    $paid = wc_get_order($order->get_id());
    $paid->set_date_paid(null);
    $paid->set_status('pending');
    $paid->save();
    $check($monitor->manual_check_order($order->get_id()) === true && wc_get_order($order->get_id())->is_paid(), 'confirmed-row repair completes an unpaid order');
    $paid = wc_get_order($order->get_id());
    $paid->update_status('cancelled');
    $monitor->repair_confirmed_rows();
    $check(wc_get_order($order->get_id())->has_status('cancelled'), 'repair does not undo a merchant cancelling a paid order');
    $paid = wc_get_order($order->get_id());
    $paid->update_status('refunded');
    $repair = new ReflectionMethod($monitor, 'repair_confirmed_row');
    $confirmed = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE order_id = %d', $table, $order->get_id()));
    $check($repair->invoke($monitor, $confirmed) === false && wc_get_order($order->get_id())->has_status('refunded'), 'repair does not reopen refunded orders');

    $review_order = $create_order();
    $gateway->process_payment($review_order->get_id());
    $review_row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE order_id = %d', $table, $review_order->get_id()));
    $review_order = wc_get_order($review_order->get_id());
    $review_order->set_total(20);
    $review_order->save();
    $review_hash = hash('sha256', 'review-order-' . $review_order->get_id());
    $confirm->invoke($monitor, $review_row, array('tx_hash' => $review_hash, 'amount' => 10, 'from' => 'fixture', 'created_at' => gmdate('c')), 'REAL8');
    $review_order = wc_get_order($review_order->get_id());
    $check(!$review_order->is_paid() && $review_order->has_status('on-hold') && $review_order->get_meta('_real8_manual_review') === 'yes', 'payment for an edited order requires merchant review rather than fulfilment');
    $review_row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE order_id = %d', $table, $review_order->get_id()));
    $check(!$repair->invoke($monitor, $review_row), 'repair cannot bypass a required merchant review');

    // Decimal persistence must preserve all seven Stellar decimal places.
    $state['price'] = 3;
    $api->clear_price_cache();
    $precision_order = $create_order();
    $gateway->process_payment($precision_order->get_id());
    $precision = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE order_id = %d', $table, $precision_order->get_id()));
    $check($precision->amount_token === '3.3333333', 'database payment amount retains seven decimal places');
    $state['price'] = 1;
    $api->clear_price_cache();
    $precision_order = wc_get_order($precision_order->get_id());
    $precision_order->set_total(20);
    $precision_order->save();
    $gateway->process_payment($precision_order->get_id());
    $changed = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE order_id = %d', $table, $precision_order->get_id()));
    $check((float) $changed->amount_token === 20.0 && $changed->memo !== $precision->memo, 'changing the order total invalidates its old quote');

    // A payment that stays unverifiable long after its deadline is closed for
    // manual reconciliation instead of being re-scanned forever.
    $stuck_order = $create_order();
    $gateway->process_payment($stuck_order->get_id());
    $stuck_row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE order_id = %d', $table, $stuck_order->get_id()));
    $stuck_row->expires_at = gmdate('Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS);
    $wpdb->update($table, array('expires_at' => $stuck_row->expires_at), array('id' => $stuck_row->id));
    $state['outage'] = true;
    $single->invoke($monitor, $stuck_row, $settings['merchant_address']);
    $state['outage'] = false;
    $check($wpdb->get_var($wpdb->prepare('SELECT status FROM %i WHERE id = %d', $table, $stuck_row->id)) === 'expired' && wc_get_order($stuck_order->get_id())->has_status('on-hold'),
        'a payment unverifiable for a day past its deadline is held for manual reconciliation');

    // A confirmation that lands while the customer re-submits checkout wins.
    $confirm_row = function($order_id) use ($wpdb, $table) {
        return function() use ($wpdb, $table, $order_id) {
            $wpdb->update($table, array('status' => 'confirmed', 'stellar_tx_hash' => hash('sha256', 'race-' . $order_id), 'paid_at' => gmdate('Y-m-d H:i:s')), array('order_id' => $order_id));
        };
    };
    foreach (array('/transactions?' => 'during verification', '/prices' => 'during pricing') as $endpoint => $label) {
        $race_order = $create_order();
        $gateway->process_payment($race_order->get_id());
        $race_row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE order_id = %d', $table, $race_order->get_id()));
        $wpdb->update($table, array('expires_at' => gmdate('Y-m-d H:i:s', time() - 120)), array('id' => $race_row->id));
        delete_transient('real8_manual_check_lock_' . $race_order->get_id());
        $race_order = wc_get_order($race_order->get_id());
        $race_order->delete_meta_data('_stellar_payment_locked_at');
        $race_order->save();
        $api->clear_price_cache();
        $state['during_request'] = array($endpoint, $confirm_row($race_order->get_id()));
        $result = $gateway->process_payment($race_order->get_id());
        $after = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE order_id = %d', $table, $race_order->get_id()));
        $check($result['result'] === 'success' && $after->status === 'confirmed' && $after->memo === $race_row->memo, 'a confirmation landing ' . $label . ' is not overwritten by a new quote');
    }
    $state['during_request'] = null;

    $request = new WP_REST_Request('POST', '/real8-gateway/v1/check');
    $request->set_param('order_id', 999999991);
    $request->set_param('order_key', 'wrong-key');
    $unknown = rest_do_request($request)->get_data();
    $request->set_param('order_id', $order->get_id());
    $wrong = rest_do_request($request)->get_data();
    $check($unknown['data'] === $wrong['data'], 'status endpoint answers alike for an unknown order and a wrong key');

    $legacy = new ReflectionMethod($api, 'check_payment_legacy');
    $legacy_payment = $op + array('transaction_hash' => $hash, 'created_at' => gmdate('c'), 'paging_token' => '1');
    $legacy_payment['to'] = str_repeat('G', 56);
    $state['payments'] = array($legacy_payment);
    $check($legacy->invoke($api, $settings['merchant_address'], 'audit-memo', 10, 'REAL8', REAL8_GW_ASSET_ISSUER) === false, 'fallback scan rejects payments to another destination');
    $state['outage'] = true;
    $check(is_wp_error($legacy->invoke($api, $settings['merchant_address'], 'audit-memo', 10, 'REAL8', REAL8_GW_ASSET_ISSUER)), 'fallback network failures propagate as errors');
    $state['outage'] = false;
    $state['payments'] = array();

    update_option('real8_gateway_amount_tolerance_min', '999999999');
    $minimum = new ReflectionMethod($api, 'min_expected_with_tolerance');
    $check((float) $minimum->invoke($api, '10', 7) >= 9.5, 'absolute underpayment tolerance cannot bypass the five-percent cap');
    update_option('real8_gateway_amount_tolerance_min', '0');

    if (!defined('REAL8_PAYMENT_INTENT_SECRET')) {
        $no_secret = new ReflectionMethod(REAL8_Gateway::get_instance(), 'maybe_migrate_settings');
        $plain_settings = $settings;
        unset($plain_settings['payment_intents']);
        update_option('woocommerce_real8_payment_settings', $plain_settings);
        update_option('real8_gateway_settings_version', '4.5.4');
        $no_secret->invoke(REAL8_Gateway::get_instance());
        $check(!isset(get_option('woocommerce_real8_payment_settings')['payment_intents']), 'an upgraded store without the hosted credential stays on local instructions');
        define('REAL8_PAYMENT_INTENT_SECRET', 'audit-fixture-not-a-production-secret');
    }
    $migrate = new ReflectionMethod(REAL8_Gateway::get_instance(), 'maybe_migrate_settings');
    $legacy_settings = $settings;
    unset($legacy_settings['payment_intents']);
    update_option('woocommerce_real8_payment_settings', $legacy_settings);
    update_option('real8_gateway_settings_version', '4.5.4');
    $migrate->invoke(REAL8_Gateway::get_instance());
    $migrated = get_option('woocommerce_real8_payment_settings');
    $check(($migrated['payment_intents'] ?? '') === 'yes' && get_option('real8_gateway_settings_version') === REAL8_GATEWAY_VERSION, 'a store upgraded with the hosted credential keeps its wallet redirect');
    $settings['payment_intents'] = 'no';
    update_option('woocommerce_real8_payment_settings', $settings);
    $migrate->invoke(REAL8_Gateway::get_instance());
    $check((get_option('woocommerce_real8_payment_settings')['payment_intents'] ?? '') === 'no', 'the migration runs once and never overrides a saved choice');

    $settings['payment_intents'] = 'yes';
    update_option('woocommerce_real8_payment_settings', $settings);
    $hosted = new REAL8_WC_Payment_Gateway();
    $state['intent_url'] = 'https://untrusted.example/pay/pi_fixture';
    $intent_order = $create_order();
    $result = $hosted->process_payment($intent_order->get_id());
    $check(strpos($result['redirect'], 'untrusted.example') === false && strpos($result['redirect'], 'app.real8.org') === false, 'untrusted hosted redirect falls back to local instructions');
    $state['intent_url'] = 'https://app.real8.org/pay/pi_fixture';
    $result = $hosted->process_payment($intent_order->get_id());
    $check($result['redirect'] === $state['intent_url'], 'opted-in valid hosted redirect accepted');
    $request = end($state['requests']);
    $signature = hash_hmac('sha256', "v2\nPOST\n/payment-intents\n" . $request[1]['headers']['X-REAL8-Timestamp'] . "\n" . $request[1]['body'], REAL8_PAYMENT_INTENT_SECRET);
    $check(hash_equals($signature, $request[1]['headers']['X-REAL8-Signature']), 'hosted request HMAC binds method, path, timestamp and payload');

    WP_CLI::success($checks . ' gateway regression checks passed.');
} finally {
    foreach ($orders as $id) {
        $payment_id = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE order_id = %d', $table, $id));
        if ($payment_id) {
            $wpdb->delete($claims, array('payment_id' => $payment_id));
        }
        $wpdb->delete($table, array('order_id' => $id));
        $order = wc_get_order($id);
        if ($order) {
            $order->delete(true);
        }
    }
    $settings['payment_intents'] = 'no';
    update_option('woocommerce_real8_payment_settings', $settings);
}
