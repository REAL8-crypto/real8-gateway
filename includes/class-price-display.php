<?php
/**
 * REAL8 Price Display
 *
 * Displays REAL8 token equivalents alongside USD prices throughout WooCommerce.
 *
 * @package REAL8_Gateway
 * @version 4.3.6
 */

if (!defined('ABSPATH')) {
    exit;
}

class REAL8_Price_Display {
    /**
     * Stellar API instance
     */
    private $stellar_api;

    /**
     * Whether price display is enabled
     */
    private $enabled = true;

    /**
     * Cached REAL8 price to avoid repeated lookups
     */
    private $real8_price = null;

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Check if gateway is enabled first
        $gateway_enabled = get_option('woocommerce_real8_payment_settings');
        if (!$gateway_enabled || (isset($gateway_enabled['enabled']) && $gateway_enabled['enabled'] !== 'yes')) {
            return;
        }

        // Check if shop prices are enabled
        $this->enabled = get_option('real8_show_shop_prices', 'yes') === 'yes';

        if (!$this->enabled) {
            return;
        }

        // Initialize API
        $this->stellar_api = REAL8_Stellar_Payment_API::get_instance();

        // Hook into price displays
        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Main price display (shop, product pages, related products, etc.)
        add_filter('woocommerce_get_price_html', array($this, 'add_real8_price'), 100, 2);

        // Cart prices
        add_filter('woocommerce_cart_item_price', array($this, 'add_real8_to_cart_price'), 100, 3);
        add_filter('woocommerce_cart_item_subtotal', array($this, 'add_real8_to_subtotal'), 100, 3);

        // Cart/checkout totals
        add_filter('woocommerce_cart_total', array($this, 'add_real8_to_cart_total'), 100);

        // Remove "thank you" notice for pending REAL8 payments
        add_filter('woocommerce_thankyou_order_received_text', array($this, 'filter_thankyou_text'), 10, 2);

        // REAL8 equivalents for subscription recurring totals on checkout
        add_action('wp_footer', array($this, 'recurring_totals_script'));

        // Enqueue frontend styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));
    }

    /**
     * Get REAL8 price (cached for the request)
     *
     * @return float|false Price or false on error
     */
    private function get_real8_price() {
        if ($this->real8_price !== null) {
            return $this->real8_price;
        }

        $price = $this->stellar_api->get_token_price('REAL8');

        if (is_wp_error($price) || $price <= 0) {
            $this->real8_price = false;
            return false;
        }

        $this->real8_price = $price;
        return $price;
    }

    /**
     * Format REAL8 amount for display
     *
     * @param float $amount Amount in REAL8
     * @return string Formatted amount
     */
    private function format_real8_amount($amount) {
        if ($amount >= 1000000) {
            return number_format($amount / 1000000, 2) . 'M';
        } elseif ($amount >= 1000) {
            return number_format($amount, 0);
        } elseif ($amount >= 1) {
            return number_format($amount, 2);
        } else {
            return number_format($amount, 4);
        }
    }

    /**
     * Generate REAL8 price HTML
     *
     * @param float $usd_amount Amount in USD
     * @return string HTML for REAL8 equivalent
     */
    private function get_real8_html($usd_amount) {
        $real8_price = $this->get_real8_price();

        if (!$real8_price || $usd_amount <= 0) {
            return '';
        }

        $real8_amount = $usd_amount / $real8_price;
        $formatted = $this->format_real8_amount($real8_amount);

        return '<span class="real8-equivalent">&asymp; ' . esc_html($formatted) . ' $REAL8</span>';
    }

    /**
     * Add REAL8 price to product price HTML
     *
     * @param string $price_html Original price HTML
     * @param WC_Product $product Product object
     * @return string Modified price HTML
     */
    public function add_real8_price($price_html, $product) {
        // Skip if in admin or doing AJAX that shouldn't show prices
        if (is_admin() && !wp_doing_ajax()) {
            return $price_html;
        }

        // Skip on checkout page (has its own token selector)
        if (function_exists('is_checkout') && is_checkout()) {
            return $price_html;
        }

        // Skip if REAL8 equivalent already present (avoids duplicates when
        // subscription plugins re-apply the woocommerce_get_price_html filter)
        if (strpos($price_html, 'real8-equivalent') !== false) {
            return $price_html;
        }

        // Get price based on product type
        if ($product->is_type('variable')) {
            // For variable products, show range
            $min_price = $product->get_variation_price('min');
            $max_price = $product->get_variation_price('max');

            if ($min_price === $max_price) {
                $real8_html = $this->get_real8_html($min_price);
            } else {
                $real8_price = $this->get_real8_price();
                if ($real8_price && $min_price > 0) {
                    $min_real8 = $this->format_real8_amount($min_price / $real8_price);
                    $max_real8 = $this->format_real8_amount($max_price / $real8_price);
                    $real8_html = '<span class="real8-equivalent">&asymp; ' .
                                  esc_html($min_real8) . ' - ' . esc_html($max_real8) . ' $REAL8</span>';
                } else {
                    $real8_html = '';
                }
            }
        } else {
            // Simple product - use sale price if on sale, otherwise regular price
            $price = $product->get_price();
            $real8_html = $this->get_real8_html($price);
        }

        if (empty($real8_html)) {
            return $price_html;
        }

        return $price_html . $real8_html;
    }

    /**
     * Add REAL8 to cart item price
     *
     * @param string $price_html Price HTML
     * @param array $cart_item Cart item data
     * @param string $cart_item_key Cart item key
     * @return string Modified price HTML
     */
    public function add_real8_to_cart_price($price_html, $cart_item, $cart_item_key) {
        if (strpos($price_html, 'real8-equivalent') !== false) {
            return $price_html;
        }

        $product = $cart_item['data'];
        $price = $product->get_price();

        $real8_html = $this->get_real8_html($price);

        if (empty($real8_html)) {
            return $price_html;
        }

        return $price_html . $real8_html;
    }

    /**
     * Add REAL8 to cart item subtotal
     *
     * @param string $subtotal_html Subtotal HTML
     * @param array $cart_item Cart item data
     * @param string $cart_item_key Cart item key
     * @return string Modified subtotal HTML
     */
    public function add_real8_to_subtotal($subtotal_html, $cart_item, $cart_item_key) {
        if (strpos($subtotal_html, 'real8-equivalent') !== false) {
            return $subtotal_html;
        }

        $product = $cart_item['data'];
        $quantity = $cart_item['quantity'];
        $subtotal = $product->get_price() * $quantity;

        $real8_html = $this->get_real8_html($subtotal);

        if (empty($real8_html)) {
            return $subtotal_html;
        }

        return $subtotal_html . $real8_html;
    }

    /**
     * Add REAL8 equivalent to cart total
     */
    public function add_real8_to_cart_total($total_html) {
        if (strpos($total_html, 'real8-equivalent') !== false) {
            return $total_html;
        }

        $cart_total = WC()->cart ? WC()->cart->get_total('edit') : 0;
        $real8_html = $this->get_real8_html($cart_total);

        if (empty($real8_html)) {
            return $total_html;
        }

        return $total_html . ' ' . $real8_html;
    }

    /**
     * Inject JS on checkout to append REAL8 equivalents to subscription recurring totals.
     * These are rendered by Flexible Subscriptions via wc_price() with no filterable hook.
     */
    public function recurring_totals_script() {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }

        $real8_price = $this->get_real8_price();
        if (!$real8_price) {
            return;
        }
        ?>
        <script>
        (function(){
            var real8Price = <?php echo wp_json_encode($real8_price); ?>;

            function formatReal8(amount) {
                if (amount >= 1000000) return (amount / 1000000).toFixed(2) + 'M';
                if (amount >= 1000) return amount.toLocaleString('en-US', {maximumFractionDigits: 0});
                if (amount >= 1) return amount.toFixed(2);
                return amount.toFixed(4);
            }

            function appendReal8(el) {
                if (!el || el.querySelector('.real8-equivalent')) return;
                var text = el.textContent.replace(/[^0-9.,]/g, '').replace(',', '');
                var usd = parseFloat(text);
                if (!usd || usd <= 0) return;
                var r8 = usd / real8Price;
                var span = document.createElement('span');
                span.className = 'real8-equivalent';
                span.innerHTML = '&asymp; ' + formatReal8(r8) + ' $REAL8';
                el.parentNode.insertBefore(span, el.nextSibling);
            }

            function update() {
                // Recurring total header amount
                document.querySelectorAll('.recurring-total-header > span:last-child').forEach(appendReal8);
                // Recurring details subtotal
                document.querySelectorAll('.recurring-totals-table .total td').forEach(appendReal8);
            }

            jQuery(document.body).on('updated_checkout', update);
            update();
        })();
        </script>
        <?php
    }

    /**
     * Remove "thank you" message for pending REAL8 payments
     */
    public function filter_thankyou_text($text, $order) {
        if (!$order || !is_a($order, 'WC_Order')) {
            return $text;
        }

        if ($order->get_payment_method() !== 'real8_payment') {
            return $text;
        }

        // Hide the message if payment is still pending
        if ($order->has_status(array('pending', 'on-hold'))) {
            return '';
        }

        return $text;
    }

    /**
     * Enqueue frontend styles
     */
    public function enqueue_styles() {
        if (!$this->enabled) {
            return;
        }

        // Only on shop-related pages
        if (is_shop() || is_product() || is_product_category() || is_product_tag() || is_cart() || is_checkout() || is_wc_endpoint_url()) {
            wp_add_inline_style('woocommerce-general', $this->get_inline_css());
        }
    }

    /**
     * Get inline CSS for REAL8 prices
     *
     * @return string CSS
     */
    private function get_inline_css() {
        return '
            .real8-equivalent {
                display: block;
                font-size: 0.85em;
                font-weight: normal;
                color: #e8491d;
                opacity: 0.9;
                margin-top: 2px;
            }
            .woocommerce-cart-form .real8-equivalent,
            .cart_totals .real8-equivalent {
                font-size: 0.8em;
            }
            .woocommerce-mini-cart .real8-equivalent {
                font-size: 0.75em;
            }
            .price del + .real8-equivalent {
                /* Hide REAL8 for strikethrough (original) prices */
                display: none;
            }
            .price ins .real8-equivalent,
            .price > .real8-equivalent {
                display: block;
            }
        ';
    }
}
