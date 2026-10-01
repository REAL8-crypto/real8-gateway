<?php
/**
 * Register REAL8 Payments with the WooCommerce Checkout block.
 *
 * @package REAL8_Gateway
 */

if (!defined('ABSPATH')) {
    exit;
}

class REAL8_Blocks_Payment_Method extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
    protected $name = 'real8_payment';

    public function initialize() {
        $this->settings = get_option('woocommerce_real8_payment_settings', array());
    }

    public function is_active() {
        return ($this->settings['enabled'] ?? 'no') === 'yes'
            && !empty($this->settings['merchant_address'])
            && get_woocommerce_currency() === 'USD';
    }

    public function get_payment_method_script_handles() {
        wp_register_script(
            'real8-checkout-blocks',
            REAL8_GATEWAY_PLUGIN_URL . 'assets/js/checkout-blocks.js',
            array('wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'),
            REAL8_GATEWAY_VERSION,
            true
        );
        return array('real8-checkout-blocks');
    }

    public function get_payment_method_data() {
        return array(
            'title' => $this->settings['title'] ?? __('Pay with REAL8', 'real8-gateway'),
            'description' => wp_strip_all_tags($this->settings['description'] ?? __('Pay with REAL8 tokens on Stellar.', 'real8-gateway')),
            'supports' => array('products'),
        );
    }
}
