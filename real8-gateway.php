<?php
/**
 * Plugin Name: REAL8 Gateway
 * Plugin URI: https://real8.org
 * Description: Accept REAL8 token payments on the Stellar blockchain for WooCommerce orders
 * Version: 4.6.0
 * Author: REAL8
 * Author URI: https://real8.org
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: real8-gateway
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.3
 * WC tested up to: 11.1
 */

// Payment records use plugin-owned tables; WooCommerce CRUD handles orders.
// Verification and atomic claims require fresh reads; caching could settle stale rows.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

if (!defined('ABSPATH')) {
    exit;
}

define('REAL8_GATEWAY_VERSION', '4.6.0');
define('REAL8_GATEWAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('REAL8_GATEWAY_PLUGIN_URL', plugin_dir_url(__FILE__));
define('REAL8_GATEWAY_PLUGIN_FILE', __FILE__);

// Database version for schema migrations
define('REAL8_GATEWAY_DB_VERSION', '4.0.0');

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
        add_action('woocommerce_blocks_loaded', array($this, 'register_blocks_support'));
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
        
        // REST API endpoints (fallback when caches block wc-ajax)
        add_action('rest_api_init', array($this, 'register_rest_routes'));

        // WC-AJAX endpoints. Registered here, not in the gateway constructor:
        // WooCommerce instantiates gateways lazily, and on a wc-ajax request
        // nothing has built them by the time the action fires, so hooks added
        // by the gateway itself were never there to answer.
        add_action('wc_ajax_real8_check_payment_status', array($this, 'wc_ajax_check_payment_status'));
        add_action('wc_ajax_stellar_get_token_prices', array($this, 'wc_ajax_get_token_prices'));
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
        $this->maybe_migrate_settings();
        if (get_option('real8_gateway_db_version') !== REAL8_GATEWAY_DB_VERSION) {
            $this->create_tables();
            if ($this->tables_exist()) {
                $this->migrate_database();
            } elseif (!get_transient('real8_gateway_schema_error')) {
                // Without both tables no payment can be confirmed. Leave the
                // schema version alone so the next load tries again, and say so.
                set_transient('real8_gateway_schema_error', 1, HOUR_IN_SECONDS);
                real8_gateway_log('REAL8 Gateway: could not create its database tables. Payments cannot be confirmed until the database user may create tables.');
            }
        }

        // Self-healing cron (v4.5.1): scheduling used to happen only in the
        // activation hook, so a plugin update by file replacement (or any
        // event loss) left the payment monitor permanently unscheduled;
        // orders then confirmed only via browser-side thank-you polling.
        $this->schedule_payment_checks();
    }

    /**
     * Migrate gateway settings from older versions.
     * Runs once per upgrade by comparing stored version.
     */
    private function maybe_migrate_settings() {
        $stored_version = get_option('real8_gateway_settings_version', '0');
        if (version_compare($stored_version, '4.6.0', '>=')) {
            return;
        }

        $settings = get_option('woocommerce_real8_payment_settings', array());
        if (empty($settings) || !is_array($settings)) {
            update_option('real8_gateway_settings_version', REAL8_GATEWAY_VERSION);
            return;
        }

        $changed = false;

        // 4.6.0 made the hosted wallet redirect an explicit setting. Until then
        // a merchant switched it on by defining REAL8_PAYMENT_INTENT_SECRET in
        // wp-config.php. A store that already runs it keeps it; new
        // installations start with it off.
        if (!isset($settings['payment_intents'])
            && defined('REAL8_PAYMENT_INTENT_SECRET') && is_string(REAL8_PAYMENT_INTENT_SECRET) && REAL8_PAYMENT_INTENT_SECRET !== '') {
            $settings['payment_intents'] = 'yes';
            $changed = true;
        }

        if (version_compare($stored_version, '4.3.6', '>=')) {
            if ($changed) {
                update_option('woocommerce_real8_payment_settings', $settings);
            }
            update_option('real8_gateway_settings_version', REAL8_GATEWAY_VERSION);
            return;
        }

        // Migrate title: replace old Stellar references
        if (!empty($settings['title']) && stripos($settings['title'], 'Stellar') !== false) {
            $settings['title'] = 'Pay with REAL8';
            $changed = true;
        }

        // Migrate description: replace old multi-token description
        if (!empty($settings['description']) && stripos($settings['description'], 'Stellar tokens') !== false) {
            $settings['description'] = 'Pay with REAL8. Fast, secure, and low fees.';
            $changed = true;
        }

        // Remove accepted_tokens (no longer used)
        if (isset($settings['accepted_tokens'])) {
            unset($settings['accepted_tokens']);
            $changed = true;
        }

        // v4.2.2: Bump tolerance if below 0.5% (was blocking orders)
        if (isset($settings['amount_tolerance_percent']) && (float) $settings['amount_tolerance_percent'] < 0.5) {
            $settings['amount_tolerance_percent'] = 0.5;
            update_option('real8_gateway_amount_tolerance_percent', 0.5);
            $changed = true;
        }

        if ($changed) {
            update_option('woocommerce_real8_payment_settings', $settings);
        }

        update_option('real8_gateway_settings_version', REAL8_GATEWAY_VERSION);
    }

    private function include_files() {
        require_once REAL8_GATEWAY_PLUGIN_DIR . 'includes/class-stellar-payment-api.php';
        require_once REAL8_GATEWAY_PLUGIN_DIR . 'includes/class-payment-gateway.php';
        require_once REAL8_GATEWAY_PLUGIN_DIR . 'includes/class-payment-monitor.php';
        require_once REAL8_GATEWAY_PLUGIN_DIR . 'includes/class-price-display.php';


        // Initialize price display for shop pages
        REAL8_Price_Display::get_instance();

    }

    /**
     * Add REAL8 payment gateway to WooCommerce
     */
    public function add_gateway($gateways) {
        $gateways[] = 'REAL8_WC_Payment_Gateway';
        return $gateways;
    }

    public function register_blocks_support() {
        if (!class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            return;
        }
        require_once REAL8_GATEWAY_PLUGIN_DIR . 'includes/class-blocks-payment-method.php';
        add_action('woocommerce_blocks_payment_method_type_registration', function($registry) {
            $registry->register(new REAL8_Blocks_Payment_Method());
        });
    }


    /**
     * The gateway instance WooCommerce holds, or null when unavailable.
     *
     * @return REAL8_WC_Payment_Gateway|null
     */
    private function get_gateway() {
        if (!function_exists('WC') || !class_exists('REAL8_WC_Payment_Gateway')) {
            return null;
        }
        $gateways = WC()->payment_gateways()->payment_gateways();
        return (isset($gateways['real8_payment']) && $gateways['real8_payment'] instanceof REAL8_WC_Payment_Gateway) ? $gateways['real8_payment'] : null;
    }

    public function wc_ajax_check_payment_status() {
        $gateway = $this->get_gateway();
        if (!$gateway) {
            wp_send_json_error(array('message' => __('REAL8 Payments is not configured.', 'real8-gateway')), 503);
        }
        $gateway->ajax_check_payment_status();
    }

    public function wc_ajax_get_token_prices() {
        $gateway = $this->get_gateway();
        if (!$gateway) {
            wp_send_json_error(array('message' => __('REAL8 Payments is not configured.', 'real8-gateway')), 503);
        }
        $gateway->ajax_get_token_prices();
    }

    /**
     * Add Settings link to plugins page
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=real8_payment')) . '">' . esc_html__('Settings', 'real8-gateway') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Declare HPOS compatibility
     */
    public function declare_hpos_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', REAL8_GATEWAY_PLUGIN_FILE, true);
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', REAL8_GATEWAY_PLUGIN_FILE, true);
        }
    }

    public function activate() {
        $this->create_tables();
        if ($this->tables_exist()) {
            $this->migrate_database();
        }
        $this->set_default_options();
        $this->schedule_payment_checks();
    }

    public function deactivate() {
        $this->unschedule_payment_checks();
    }

    /**
     * Whether both plugin tables are present.
     */
    private function tables_exist() {
        global $wpdb;
        foreach (array('real8_payments', 'real8_transaction_claims') as $name) {
            $table = $wpdb->prefix . $name;
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
                return false;
            }
        }
        return true;
    }

    private function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'real8_payments';

        // Updated schema with multi-token support (v3.0.0)
        $sql = "CREATE TABLE $table_name (
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
            PRIMARY KEY  (id),
            UNIQUE KEY order_id (order_id),
            KEY memo (memo),
            KEY status (status),
            KEY expires_at (expires_at),
            KEY asset_code (asset_code)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        $claims_table = $wpdb->prefix . 'real8_transaction_claims';
        dbDelta("CREATE TABLE $claims_table (
            tx_hash varchar(64) NOT NULL,
            payment_id bigint(20) NOT NULL,
            PRIMARY KEY  (tx_hash),
            KEY payment_id (payment_id)
        ) $charset_collate;");
        // Preserve claims across retries and seed existing confirmed transactions.
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO %i (tx_hash, payment_id) SELECT stellar_tx_hash, id FROM %i WHERE stellar_tx_hash IS NOT NULL AND stellar_tx_hash <> ''",
            $claims_table, $table_name
        ));

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
            update_option('real8_gateway_db_version', REAL8_GATEWAY_DB_VERSION);
            return;
        }

        // Check if old columns exist (amount_real8, real8_price)
        $columns = $wpdb->get_col($wpdb->prepare("DESCRIBE %i", $table_name), 0);

        // Add new columns if they don't exist
        if (!in_array('asset_code', $columns)) {
            $wpdb->query($wpdb->prepare("ALTER TABLE %i ADD COLUMN asset_code VARCHAR(12) NOT NULL DEFAULT 'REAL8' AFTER memo", $table_name));
        }

        if (!in_array('asset_issuer', $columns)) {
            $wpdb->query($wpdb->prepare("ALTER TABLE %i ADD COLUMN asset_issuer VARCHAR(56) DEFAULT NULL AFTER asset_code", $table_name));
        }

        // Rename old columns to new generic names
        if (in_array('amount_real8', $columns) && !in_array('amount_token', $columns)) {
            $wpdb->query($wpdb->prepare("ALTER TABLE %i CHANGE amount_real8 amount_token DECIMAL(20,7) NOT NULL", $table_name));
        }

        if (in_array('real8_price', $columns) && !in_array('token_price', $columns)) {
            $wpdb->query($wpdb->prepare("ALTER TABLE %i CHANGE real8_price token_price DECIMAL(15,8) NOT NULL", $table_name));
        }

        // Set REAL8 issuer for existing records (they were all REAL8)
        $wpdb->query($wpdb->prepare(
            "UPDATE %i SET asset_issuer = %s WHERE asset_issuer IS NULL AND asset_code = 'REAL8'",
            $table_name,
            REAL8_GW_ASSET_ISSUER
        ));

        // Add index on asset_code if it doesn't exist
        $indexes = $wpdb->get_results($wpdb->prepare("SHOW INDEX FROM %i WHERE Key_name = 'asset_code'", $table_name));
        if (empty($indexes)) {
            $wpdb->query($wpdb->prepare("ALTER TABLE %i ADD INDEX asset_code (asset_code)", $table_name));
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
            'real8_show_shop_prices' => 'yes', // Show REAL8 prices in shop
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
            wp_schedule_event(time(), 'real8_gateway_every_minute', 'real8_gateway_check_payments');
        }
    }

    /**
     * Unschedule payment check cron
     */
    private function unschedule_payment_checks() {
        wp_clear_scheduled_hook('real8_gateway_check_payments');
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
            'args' => array(
                'order_id' => array('type' => 'integer', 'required' => true, 'minimum' => 1, 'validate_callback' => 'rest_validate_request_arg'),
                'order_key' => array('type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field', 'validate_callback' => 'rest_validate_request_arg'),
                'force' => array('type' => 'integer', 'default' => 0, 'enum' => array(0, 1, 2), 'validate_callback' => 'rest_validate_request_arg'),
            ),
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

        // Security: require a valid order key for guest checks. Same answer
        // for an unknown order and a wrong key, so the endpoint does not
        // reveal which order numbers exist.
        $order = wc_get_order($order_id);
        if (!$order || !$order_key || !hash_equals($order->get_order_key(), $order_key)) {
            return $send_error(__('Invalid order', 'real8-gateway'), 'invalid_order');
        }

        // Ensure this order uses this gateway.
        if ($order->get_payment_method() !== 'real8_payment') {
            return $send_error(__('Invalid payment method', 'real8-gateway'), 'invalid_gateway');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'real8_payments';
        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM %i WHERE order_id = %d",
            $table,
            $order_id
        ));

        if (!$payment) {
            return $send_error(__('Payment not found', 'real8-gateway'), 'payment_not_found');
        }

        // Expiry handling (keep DB + order consistent). v4.5.3: one last Horizon
        // check before expiring, never expire on a failed lookup (same policy as
        // the cron monitor since v4.5.1).
        $expires_at = strtotime($payment->expires_at);
        if ($expires_at && time() > $expires_at && $payment->status === 'pending') {
            $late = class_exists('REAL8_Payment_Monitor')
                ? \REAL8_Payment_Monitor::get_instance()->manual_check_order($order_id)
                : new \WP_Error('no_monitor', 'Payment monitor not available');
            if ($late === true) {
                $force = false; // confirmed below, do not re-check
            } elseif (is_wp_error($late)) {
                return new \WP_REST_Response(array(
                    'success' => true,
                    'data' => array(
                        'status'      => 'pending',
                        'message'     => __('Payment window has expired; final verification pending', 'real8-gateway'),
                        'expires_in'  => 0,
                        'check_error' => class_exists('REAL8_Payment_Monitor') ? \REAL8_Payment_Monitor::get_instance()->customer_error_message($late) : '',
                    ),
                ), 200);
            } else {
                // Guarded expiry (issue #11): the row is expired and the order
                // failed only if the row is still pending. If the cron monitor
                // confirmed it between our lookup and this write, we must not
                // fail a paid order; report it as pending and let the poll
                // pick up the confirmation.
                $expired = class_exists('REAL8_Payment_Monitor')
                    ? \REAL8_Payment_Monitor::get_instance()->expire_payment($payment)
                    : false;
                if (!$expired) {
                    return new \WP_REST_Response(array(
                        'success' => true,
                        'data' => array(
                            'status'     => 'pending',
                            'message'    => __('Payment window has expired; final verification pending', 'real8-gateway'),
                            'expires_in' => 0,
                        ),
                    ), 200);
                }

                return new \WP_REST_Response(array(
                    'success' => true,
                    'data' => array(
                        'status' => 'expired',
                        'message' => __('Payment window has expired', 'real8-gateway'),
                        'expires_in' => 0,
                    ),
                ), 200);
            }
        }

        // Optional: trigger a manual on-demand check (throttled) to confirm faster after user pays.
        $should_check = $force || $payment->status === 'pending';
        $did_check = false;
        $check_error = '';

        if ($should_check) {
            $did_check = true;
            if (class_exists('REAL8_Payment_Monitor')) {
                $result = REAL8_Payment_Monitor::get_instance()->manual_check_order($order_id);
                if (is_wp_error($result)) {
                    $check_error = REAL8_Payment_Monitor::get_instance()->customer_error_message($result);
                }
            } else {
                $check_error = __('Payment monitor not available.', 'real8-gateway');
            }
        }


        // Re-fetch after a manual check attempt (so we return current state)
        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM %i WHERE order_id = %d",
            $table,
            $order_id
        ));

        if (!$payment) {
            return $send_error(__('Payment not found', 'real8-gateway'), 'payment_not_found');
        }
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
    $schedules['real8_gateway_every_minute'] = array(
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
        'label' => __('REAL8 Total:', 'real8-gateway'),
        'value' => esc_html($amount_str) . ' $' . esc_html($asset_code),
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
 * Record operational payment events in WooCommerce > Status > Logs.
 *
 * @param string $message Event description; never include credentials.
 */
function real8_gateway_log($message) {
    if (function_exists('wc_get_logger')) {
        wc_get_logger()->info($message, array('source' => 'real8-gateway'));
    }
}

/**
 * Initialize the plugin
 */
function real8_gateway() {
    return REAL8_Gateway::get_instance();
}

real8_gateway();
