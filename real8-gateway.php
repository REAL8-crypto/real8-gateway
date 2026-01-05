<?php
/**
 * Plugin Name: REAL8 Gateway for WooCommerce
 * Plugin URI: https://real8.org
 * Description: Accept Stellar token payments (XLM, REAL8, USDC, EURC, SLVR, GOLD) for WooCommerce orders
* Version: 3.0.8.3
 * Author: REAL8
 * Author URI: https://real8.org
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: real8-gateway
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('REAL8_GATEWAY_VERSION', '3.0.8.3');
define('REAL8_GATEWAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('REAL8_GATEWAY_PLUGIN_URL', plugin_dir_url(__FILE__));
define('REAL8_GATEWAY_PLUGIN_FILE', __FILE__);

// Database version for schema migrations
define('REAL8_GATEWAY_DB_VERSION', '3.0.0');

// Legacy constants - kept for backward compatibility
// @deprecated 3.0.0 Use REAL8_Token_Registry class instead
define('REAL8_GW_ASSET_CODE', 'REAL8');
define('REAL8_GW_ASSET_ISSUER', 'GBVYYQ7XXRZW6ZCNNCL2X2THNPQ6IM4O47HAA25JTAG7Z3CXJCQ3W4CD');

// Load Token Registry early (needed for constants)
require_once plugin_dir_path(__FILE__) . 'includes/class-token-registry.php';

// Stellar Network
define('REAL8_GW_HORIZON_URL', 'https://horizon.stellar.org');
define('REAL8_GW_NETWORK_PASSPHRASE', 'Public Global Stellar Network ; September 2015');

// Pricing API
define('REAL8_GW_PRICING_API', 'https://api.real8.org/prices');

// Payment Settings (Industry Standards)
define('REAL8_GW_PAYMENT_TIMEOUT_MINUTES', 30); // Standard crypto payment window
define('REAL8_GW_PRICE_BUFFER_PERCENT', 2);     // Buffer for price volatility
define('REAL8_GW_PRICE_CACHE_SECONDS', 60);     // Cache price for 1 minute

class REAL8_Gateway {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('plugins_loaded', array($this, 'check_dependencies'));
        add_action('init', array($this, 'init'));
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
        
        // REST API endpoints (fallback when caches block wc-ajax)
        add_action('rest_api_init', array($this, 'register_rest_routes'));
add_filter('plugin_action_links_' . plugin_basename(REAL8_GATEWAY_PLUGIN_FILE), array($this, 'add_settings_link'));
        register_activation_hook(REAL8_GATEWAY_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(REAL8_GATEWAY_PLUGIN_FILE, array($this, 'deactivate'));

        // Register WooCommerce payment gateway
        add_filter('woocommerce_payment_gateways', array($this, 'add_gateway'));
    }

    public function check_dependencies() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return false;
        }
        return true;
    }

    public function woocommerce_missing_notice() {
        ?>
        <div class="error">
            <p><?php esc_html_e('REAL8 Gateway requires WooCommerce to be installed and active.', 'real8-gateway'); ?></p>
        </div>
        <?php
    }

    public function init() {
        if (!$this->check_dependencies()) {
            return;
        }
        $this->include_files();
    }

    private function include_files() {
        require_once REAL8_GATEWAY_PLUGIN_DIR . 'includes/class-stellar-payment-api.php';
        require_once REAL8_GATEWAY_PLUGIN_DIR . 'includes/class-payment-gateway.php';
        require_once REAL8_GATEWAY_PLUGIN_DIR . 'includes/class-payment-monitor.php';
    }

    /**
     * Add REAL8 payment gateway to WooCommerce
     */
    public function add_gateway($gateways) {
        $gateways[] = 'WC_Gateway_REAL8';
        return $gateways;
    }

    public function load_textdomain() {
        $plugin_rel_path = dirname(plugin_basename(REAL8_GATEWAY_PLUGIN_FILE)) . '/languages';
        load_plugin_textdomain('real8-gateway', false, $plugin_rel_path);
    }

    /**
     * Add Settings link to plugins page
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=real8_payment') . '">' . __('Settings', 'real8-gateway') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Declare HPOS compatibility
     */
    public function declare_hpos_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', REAL8_GATEWAY_PLUGIN_FILE, true);
        }
    }

    public function activate() {
        $this->create_tables();
        $this->migrate_database();
        $this->set_default_options();
        $this->schedule_payment_checks();
        flush_rewrite_rules();
    }

    public function deactivate() {
        $this->unschedule_payment_checks();
        flush_rewrite_rules();
    }

    private function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'real8_payments';

        // Updated schema with multi-token support (v3.0.0)
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            memo varchar(64) NOT NULL,
            asset_code varchar(12) NOT NULL DEFAULT 'REAL8',
            asset_issuer varchar(56) DEFAULT NULL,
            amount_token decimal(20,7) NOT NULL,
            amount_usd decimal(10,2) NOT NULL,
            token_price decimal(15,8) NOT NULL,
            merchant_address varchar(56) NOT NULL,
            status varchar(20) DEFAULT 'pending',
            stellar_tx_hash varchar(64) DEFAULT NULL,
            expires_at datetime NOT NULL,
            paid_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY order_id (order_id),
            KEY memo (memo),
            KEY status (status),
            KEY expires_at (expires_at),
            KEY asset_code (asset_code)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Migrate database from v2.x to v3.0 (multi-token support)
     */
    private function migrate_database() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'real8_payments';

        $installed_version = get_option('real8_gateway_db_version', '1.0.0');

        // Skip if already migrated
        if (version_compare($installed_version, '3.0.0', '>=')) {
            return;
        }

        // Check if old columns exist (amount_real8, real8_price)
        $columns = $wpdb->get_col("DESCRIBE $table_name", 0);

        // Add new columns if they don't exist
        if (!in_array('asset_code', $columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN asset_code VARCHAR(12) NOT NULL DEFAULT 'REAL8' AFTER memo");
        }

        if (!in_array('asset_issuer', $columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN asset_issuer VARCHAR(56) DEFAULT NULL AFTER asset_code");
        }

        // Rename old columns to new generic names
        if (in_array('amount_real8', $columns) && !in_array('amount_token', $columns)) {
            $wpdb->query("ALTER TABLE $table_name CHANGE amount_real8 amount_token DECIMAL(20,7) NOT NULL");
        }

        if (in_array('real8_price', $columns) && !in_array('token_price', $columns)) {
            $wpdb->query("ALTER TABLE $table_name CHANGE real8_price token_price DECIMAL(15,8) NOT NULL");
        }

        // Set REAL8 issuer for existing records (they were all REAL8)
        $wpdb->query($wpdb->prepare(
            "UPDATE $table_name SET asset_issuer = %s WHERE asset_issuer IS NULL AND asset_code = 'REAL8'",
            REAL8_GW_ASSET_ISSUER
        ));

        // Add index on asset_code if it doesn't exist
        $indexes = $wpdb->get_results("SHOW INDEX FROM $table_name WHERE Key_name = 'asset_code'");
        if (empty($indexes)) {
            $wpdb->query("ALTER TABLE $table_name ADD INDEX asset_code (asset_code)");
        }

        // Update version
        update_option('real8_gateway_db_version', REAL8_GATEWAY_DB_VERSION);
    }

    private function set_default_options() {
        $defaults = array(
            'real8_gateway_enabled' => 'no',
            'real8_gateway_title' => 'Pay with REAL8',
            'real8_gateway_description' => 'Pay with REAL8 tokens on the Stellar network',
            'real8_gateway_merchant_address' => '',
            'real8_gateway_payment_timeout' => REAL8_GW_PAYMENT_TIMEOUT_MINUTES,
            'real8_gateway_price_buffer' => REAL8_GW_PRICE_BUFFER_PERCENT,
        );

        foreach ($defaults as $key => $value) {
            if (false === get_option($key)) {
                add_option($key, $value);
            }
        }
    }

    /**
     * Schedule cron job to check for payments
     */
    private function schedule_payment_checks() {
        if (!wp_next_scheduled('real8_gateway_check_payments')) {
            wp_schedule_event(time(), 'every_minute', 'real8_gateway_check_payments');
        }
    }

    /**
     * Unschedule payment check cron
     */
    private function unschedule_payment_checks() {
        $timestamp = wp_next_scheduled('real8_gateway_check_payments');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'real8_gateway_check_payments');
        }
    }

    /**
     * REST routes (fallback when caches/HTML delivery block WC-AJAX).
     *
     * POST /wp-json/real8-gateway/v1/check
     * Params: order_id, order_key, force (0|1)
     */
    public function register_rest_routes() {
        // WooCommerce required.
        if (!function_exists('wc_get_order')) {
            return;
        }

        // rest_api_init may run before our init() on some sites; ensure classes exist.
        if (!class_exists('REAL8_Payment_Monitor') || !class_exists('REAL8_Stellar_Payment_API')) {
            $this->include_files();
        }

        register_rest_route('real8-gateway/v1', '/check', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_check_payment_status'),
            'permission_callback' => '__return_true',
        ));
    }

    public function rest_check_payment_status(\WP_REST_Request $request) {
        if (function_exists('nocache_headers')) {
            nocache_headers();
        }

        $order_id  = absint($request->get_param('order_id'));
        $order_key = sanitize_text_field((string) $request->get_param('order_key'));
        $force     = (int) $request->get_param('force');

        $send_error = function($message, $code = 'error') {
            if (function_exists('ob_get_length') && ob_get_length()) {
                @ob_clean();
            }
            return new \WP_REST_Response(array(
                'success' => false,
                'data' => array(
                    'message' => (string) $message,
                    'code' => (string) $code,
                ),
            ), 200);
        };

        if (!$order_id) {
            return $send_error(__('Invalid order', 'real8-gateway'), 'invalid_order');
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return $send_error(__('Order not found', 'real8-gateway'), 'order_not_found');
        }

        // Security: require a valid order key for guest checks.
        if (!$order_key || !hash_equals($order->get_order_key(), $order_key)) {
            return $send_error(__('Invalid order', 'real8-gateway'), 'invalid_order_key');
        }

        // Ensure this order uses this gateway.
        if ($order->get_payment_method() !== 'real8_payment') {
            return $send_error(__('Invalid payment method', 'real8-gateway'), 'invalid_gateway');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'real8_payments';
        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE order_id = %d",
            $order_id
        ));

        if (!$payment) {
            return $send_error(__('Payment not found', 'real8-gateway'), 'payment_not_found');
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

            return new \WP_REST_Response(array(
                'success' => true,
                'data' => array(
                    'status' => 'expired',
                    'message' => __('Payment window has expired', 'real8-gateway'),
                    'expires_in' => 0,
                ),
            ), 200);
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
                    $monitor = \REAL8_Payment_Monitor::get_instance();
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

        return new \WP_REST_Response(array('success' => true, 'data' => $response), 200);
    }

}

/**
 * Add custom cron schedule for every minute
 */
add_filter('cron_schedules', function($schedules) {
    $schedules['every_minute'] = array(
        'interval' => 60,
        'display' => __('Every Minute', 'real8-gateway')
    );
    return $schedules;
});

/**
 * Add Stellar paid amount to order totals (emails / order details)
 */
add_filter('woocommerce_get_order_item_totals', function($totals, $order, $tax_display) {
    if (!$order || !is_a($order, 'WC_Order')) {
        return $totals;
    }

    // Only for this gateway
    if ($order->get_payment_method() !== 'real8_payment') {
        return $totals;
    }

    $asset_code = (string) $order->get_meta('_stellar_asset_code');
    if ($asset_code === '') {
        $asset_code = 'REAL8';
    }

    // Prefer the confirmed on-chain amount; fallback to expected amount
    $paid_amount = $order->get_meta('_stellar_paid_amount');
    $expected_amount = $order->get_meta('_stellar_payment_amount');
    $amount = ($paid_amount !== '' && $paid_amount !== null) ? $paid_amount : $expected_amount;

    if ($amount === '' || $amount === null) {
        return $totals;
    }

    $amount_str = wc_format_decimal($amount, 7);

    $row = array(
        'label' => __('Monto en Stellar:', 'real8-gateway'),
        'value' => esc_html($amount_str) . ' ' . esc_html($asset_code),
    );

    // Insert right after payment method if present
    $new = array();
    $inserted = false;
    foreach ($totals as $key => $value) {
        $new[$key] = $value;
        if ($key === 'payment_method') {
            $new['stellar_amount'] = $row;
            $inserted = true;
        }
    }

    if (!$inserted) {
        $new['stellar_amount'] = $row;
    }

    return $new;
}, 20, 3);

/**
 * Initialize the plugin
 */
function real8_gateway() {
    return REAL8_Gateway::get_instance();
}

real8_gateway();
