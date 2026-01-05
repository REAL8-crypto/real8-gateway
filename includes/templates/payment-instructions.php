<?php
/**
 * Payment Instructions Template
 *
 * Displayed on thank-you page after order placement
 * Supports all Stellar tokens: XLM, REAL8, wREAL8, USDC, EURC, SLVR, GOLD
 *
 * @package REAL8_Gateway
 * @version 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Variables available: $order, $memo, $amount, $expires, $merchant, $status, $asset_code, $asset_issuer
$expires_timestamp = strtotime($expires);
$time_remaining = max(0, $expires_timestamp - time());
$minutes_remaining = ceil($time_remaining / 60);

// Get token display info from registry
$token_info = REAL8_Token_Registry::get_token($asset_code);
$token_name = $token_info ? $token_info['name'] : $asset_code;
$token_color = $token_info ? $token_info['color'] : '#666666';
$is_native = $token_info ? $token_info['is_native'] : false;
?>

<div id="real8-payment-instructions" class="real8-payment-box" data-order-id="<?php echo esc_attr($order->get_id()); ?>" data-order-key="<?php echo esc_attr($order->get_order_key()); ?>" data-status="<?php echo esc_attr($status); ?>">

    <?php if ($status === 'completed' || $status === 'confirmed'): ?>
        <!-- Payment Confirmed -->
        <div class="real8-payment-status real8-status-confirmed">
            <span class="real8-status-icon">&#10004;</span>
            <h3><?php esc_html_e('Payment Received!', 'real8-gateway'); ?></h3>
            <p>
                <?php
                printf(
                    /* translators: %s: token code (e.g., REAL8, XLM, USDC) */
                    esc_html__('Your %s payment has been confirmed. Thank you for your order!', 'real8-gateway'),
                    esc_html($asset_code)
                );
                ?>
            </p>
        </div>

    <?php elseif ($status === 'expired'): ?>
        <!-- Payment Expired -->
        <div class="real8-payment-status real8-status-expired">
            <span class="real8-status-icon">&#10060;</span>
            <h3><?php esc_html_e('Payment Expired', 'real8-gateway'); ?></h3>
            <p><?php esc_html_e('The payment window has expired. Please contact support if you made a payment.', 'real8-gateway'); ?></p>
        </div>

    <?php else: ?>
        <!-- Awaiting Payment -->
        <div class="real8-payment-status real8-status-pending">
            <span class="real8-status-icon real8-pulse" style="color: <?php echo esc_attr($token_color); ?>;">&#9679;</span>
            <h3>
                <?php
                printf(
                    /* translators: %s: token code (e.g., REAL8, XLM, USDC) */
                    esc_html__('Awaiting %s Payment', 'real8-gateway'),
                    esc_html($asset_code)
                );
                ?>
            </h3>
            <p class="real8-timer">
                <?php
                printf(
                    esc_html__('Time remaining: %s', 'real8-gateway'),
                    '<span id="real8-countdown">' . esc_html($minutes_remaining) . '</span> ' . esc_html__('minutes', 'real8-gateway')
                );
                ?>
            </p>
        </div>

        <div class="real8-payment-details">
            <h4><?php esc_html_e('Send Payment To:', 'real8-gateway'); ?></h4>

            <div class="real8-detail-row">
                <label><?php esc_html_e('Amount:', 'real8-gateway'); ?></label>
                <div class="real8-value real8-amount">
                    <strong style="color: <?php echo esc_attr($token_color); ?>;">
                        <?php echo esc_html(number_format($amount, 7)); ?> <?php echo esc_html($asset_code); ?>
                    </strong>
                    <button type="button" class="real8-copy-btn" data-copy="<?php echo esc_attr(number_format($amount, 7)); ?>" title="<?php esc_attr_e('Copy amount', 'real8-gateway'); ?>">
                        <span class="dashicons dashicons-admin-page"></span>
                    </button>
                </div>
            </div>

            <div class="real8-detail-row">
                <label><?php esc_html_e('Destination Address:', 'real8-gateway'); ?></label>
                <div class="real8-value real8-address">
                    <code><?php echo esc_html($merchant); ?></code>
                    <button type="button" class="real8-copy-btn" data-copy="<?php echo esc_attr($merchant); ?>" title="<?php esc_attr_e('Copy address', 'real8-gateway'); ?>">
                        <span class="dashicons dashicons-admin-page"></span>
                    </button>
                </div>
            </div>

            <div class="real8-detail-row real8-memo-row">
                <label><?php esc_html_e('Memo (TEXT - REQUIRED):', 'real8-gateway'); ?></label>
                <div class="real8-value real8-memo">
                    <code><?php echo esc_html($memo); ?></code>
                    <button type="button" class="real8-copy-btn" data-copy="<?php echo esc_attr($memo); ?>" title="<?php esc_attr_e('Copy memo', 'real8-gateway'); ?>">
                        <span class="dashicons dashicons-admin-page"></span>
                    </button>
                </div>
            </div>

            <div class="real8-warning">
                <strong>&#9888; <?php esc_html_e('IMPORTANT:', 'real8-gateway'); ?></strong>
                <?php esc_html_e('You MUST include the memo exactly as shown above. Without the correct memo, your payment cannot be matched to your order.', 'real8-gateway'); ?>
            </div>

            <div class="real8-asset-info">
                <div class="real8-asset-header" style="border-left: 4px solid <?php echo esc_attr($token_color); ?>;">
                    <strong><?php echo esc_html($token_name); ?> (<?php echo esc_html($asset_code); ?>)</strong>
                </div>
                <?php if ($is_native): ?>
                    <p class="real8-native-asset">
                        <?php esc_html_e('Native Stellar Asset', 'real8-gateway'); ?>
                    </p>
                <?php else: ?>
                    <p>
                        <?php esc_html_e('Asset Code:', 'real8-gateway'); ?> <code><?php echo esc_html($asset_code); ?></code><br>
                        <?php esc_html_e('Issuer:', 'real8-gateway'); ?> <code class="real8-issuer"><?php echo esc_html($asset_issuer); ?></code>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <div class="real8-payment-footer">
            <p class="real8-checking-status">
                <span class="real8-spinner"></span>
                <?php esc_html_e('Automatically checking for payment...', 'real8-gateway'); ?>
            </p>
            <div class="real8-manual-check-wrap">
                <button type="button" class="button real8-manual-check-btn"><?php esc_html_e('Comprobar pago ahora', 'real8-gateway'); ?></button>
                <p class="real8-manual-check-msg" style="display:none"></p>
                <small class="real8-manual-check-hint"><?php esc_html_e('Si ya pagaste, haz clic para forzar la verificación.', 'real8-gateway'); ?></small>
            </div>
            <p class="real8-order-total">
                <?php
                printf(
                    /* translators: 1: order total, 2: token amount, 3: token code */
                    esc_html__('Order Total: %1$s (approximately %2$s %3$s at current rate)', 'real8-gateway'),
                    wc_price($order->get_total()),
                    number_format($amount, 2),
                    esc_html($asset_code)
                );
                ?>
            </p>
        </div>
    <?php endif; ?>

</div>

<style>
.real8-payment-box {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 25px;
    margin: 20px 0;
    max-width: 600px;
}

.real8-payment-status {
    text-align: center;
    padding: 20px;
    border-radius: 6px;
    margin-bottom: 20px;
}

.real8-status-pending {
    background: #fff3cd;
    border: 1px solid #ffc107;
}

.real8-status-confirmed {
    background: #d4edda;
    border: 1px solid #28a745;
}

.real8-status-expired {
    background: #f8d7da;
    border: 1px solid #dc3545;
}

.real8-status-icon {
    font-size: 48px;
    display: block;
    margin-bottom: 10px;
}

.real8-pulse {
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

.real8-payment-status h3 {
    margin: 0 0 10px;
    font-size: 1.4em;
}

.real8-timer {
    font-size: 1.1em;
    font-weight: 600;
}

.real8-payment-details h4 {
    margin: 0 0 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}

.real8-detail-row {
    margin-bottom: 15px;
}

.real8-detail-row label {
    display: block;
    font-weight: 600;
    margin-bottom: 5px;
    color: #333;
}

.real8-value {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #f8f9fa;
    padding: 10px 15px;
    border-radius: 4px;
    border: 1px solid #e9ecef;
}

.real8-value code {
    flex: 1;
    background: transparent;
    padding: 0;
    word-break: break-all;
    font-size: 14px;
}

.real8-amount strong {
    font-size: 1.3em;
}

.real8-copy-btn {
    background: #007bff;
    color: #fff;
    border: none;
    border-radius: 4px;
    padding: 5px 10px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}

.real8-copy-btn:hover {
    background: #0056b3;
}

.real8-copy-btn .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
}

.real8-memo-row .real8-value {
    background: #e7f3ff;
    border-color: #b6d4fe;
}

.real8-warning {
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: 4px;
    padding: 12px 15px;
    margin: 20px 0;
    font-size: 0.9em;
}

.real8-asset-info {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 4px;
    font-size: 0.85em;
    margin-top: 20px;
}

.real8-asset-header {
    padding-left: 10px;
    margin-bottom: 10px;
}

.real8-native-asset {
    color: #666;
    font-style: italic;
    margin: 0;
}

.real8-asset-info code.real8-issuer {
    font-size: 0.75em;
    word-break: break-all;
}

.real8-payment-footer {
    margin-top: 20px;
    padding-top: 15px;
    border-top: 1px solid #eee;
    text-align: center;
}

.real8-checking-status {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    color: #666;
}

.real8-spinner {
    width: 16px;
    height: 16px;
    border: 2px solid #ddd;
    border-top-color: #007bff;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.real8-order-total {
    font-size: 0.9em;
    color: #666;
}

/* Copy success animation */
.real8-copy-btn.copied {
    background: #28a745;
}
</style>
