<?php
/**
 * Plugin Name: REAL8 Gateway for WooCommerce
 * Plugin URI: https://real8.org
 * Description: Accept REAL8 token payments on the Stellar network for WooCommerce orders
 * Version: 1.0.0
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

define('REAL8_GATEWAY_VERSION', '1.0.0');
define('REAL8_GATEWAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('REAL8_GATEWAY_PLUGIN_URL', plugin_dir_url(__FILE__));
define('REAL8_GATEWAY_PLUGIN_FILE', __FILE__);

// REAL8 Token Constants
define('REAL8_GW_ASSET_CODE', 'REAL8');
define('REAL8_GW_ASSET_ISSUER', 'GBVYYQ7XXRZW6ZCNNCL2X2THNPQ6IM4O47HAA25JTAG7Z3CXJCQ3W4CD');

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

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            memo varchar(64) NOT NULL,
            amount_real8 decimal(20,7) NOT NULL,
            amount_usd decimal(10,2) NOT NULL,
            real8_price decimal(15,8) NOT NULL,
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
            KEY expires_at (expires_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
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
 * Initialize the plugin
 */
function real8_gateway() {
    return REAL8_Gateway::get_instance();
}

real8_gateway();
