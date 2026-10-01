<?php
/**
 * Payment Instructions Template
 *
 * Displayed on thank-you page after order placement
 * @package REAL8_Gateway
 * @version 4.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Variables available: $order, $memo, $amount, $expires, $merchant, $status, $asset_code, $asset_issuer, $real8_sent_tx
$real8_expires_timestamp = strtotime($expires);
$real8_time_remaining = max(0, $real8_expires_timestamp - time());
$real8_minutes_remaining = ceil($real8_time_remaining / 60);
$real8_sent_tx = isset($sent_tx) ? $sent_tx : '';

// Get token display info from registry
$real8_token_info = REAL8_Token_Registry::get_token($asset_code);
$real8_token_name = $real8_token_info ? $real8_token_info['name'] : $asset_code;
$real8_token_color = $real8_token_info ? $real8_token_info['color'] : '#666666';
$real8_is_native = $real8_token_info ? $real8_token_info['is_native'] : false;
?>

<div id="real8-payment-instructions" class="real8-payment-box" data-order-id="<?php echo esc_attr($order->get_id()); ?>" data-order-key="<?php echo esc_attr($order->get_order_key()); ?>" data-status="<?php echo esc_attr($status); ?>" data-sent-tx="<?php echo esc_attr($real8_sent_tx); ?>">

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

    <?php elseif ($real8_sent_tx): ?>
        <!-- Payment sent by the wallet — confirming on the network -->
        <div class="real8-payment-status real8-status-sent">
            <span class="real8-status-icon"><span class="real8-spinner real8-spinner-lg"></span></span>
            <h3><?php esc_html_e('Payment Sent', 'real8-gateway'); ?></h3>
            <p>
                <?php
                printf(
                    /* translators: %s: token code (e.g., REAL8, XLM, USDC) */
                    esc_html__('Your wallet reported the %s payment as sent. Confirming it on the Stellar network, this usually takes under a minute. This page updates automatically.', 'real8-gateway'),
                    esc_html($asset_code)
                );
                ?>
            </p>
            <p class="real8-sent-tx">
                <small><?php esc_html_e('Transaction:', 'real8-gateway'); ?> <code><?php echo esc_html(substr($real8_sent_tx, 0, 8) . '…' . substr($real8_sent_tx, -8)); ?></code></small>
            </p>
        </div>

        <div class="real8-payment-footer">
            <p class="real8-checking-status">
                <span class="real8-spinner"></span>
                <?php esc_html_e('Verifying payment on the network...', 'real8-gateway'); ?>
            </p>
            <div class="real8-manual-check-wrap">
                <button type="button" class="button real8-manual-check-btn"><?php esc_html_e('Check payment now', 'real8-gateway'); ?></button>
                <p class="real8-manual-check-msg" style="display:none"></p>
            </div>
        </div>

    <?php else: ?>
        <!-- Awaiting Payment -->
        <div class="real8-payment-status real8-status-pending">
            <span class="real8-status-icon real8-pulse" style="color: <?php echo esc_attr($real8_token_color); ?>;">&#9679;</span>
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
                    /* translators: %s: countdown in minutes. */
                    esc_html__('Time remaining: %s', 'real8-gateway'),
                    '<span id="real8-countdown">' . esc_html($real8_minutes_remaining) . '</span> ' . esc_html__('minutes', 'real8-gateway')
                );
                ?>
            </p>
        </div>

        <div class="real8-payment-details">
            <div class="real8-detail-row">
                <label><?php esc_html_e('Amount:', 'real8-gateway'); ?></label>
                <div class="real8-value real8-amount">
                    <strong style="color: <?php echo esc_attr($real8_token_color); ?>;">
                        <?php echo esc_html(number_format($amount, 7, '.', '')); ?> $<?php echo esc_html($asset_code); ?>
                    </strong>
                    <button type="button" class="real8-copy-btn" data-copy="<?php echo esc_attr(number_format($amount, 7, '.', '')); ?>" title="<?php esc_attr_e('Copy amount', 'real8-gateway'); ?>">
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

            <?php
            // Build deep link to REAL8 Wallet app
            $real8_wallet_url = add_query_arg(array(
                'wc_pay'    => $merchant,
                'wc_amount' => number_format($amount, 7, '.', ''),
                'wc_memo'   => $memo,
                'wc_asset'  => $asset_code,
            ), 'https://app.real8.org/');
            ?>

            <!-- Pay with REAL8 Wallet -->
            <div class="real8-wallet-action">
                <a href="<?php echo esc_url($real8_wallet_url); ?>" class="real8-wallet-btn" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e('Pay with REAL8 Wallet', 'real8-gateway'); ?>
                </a>
                <p class="real8-wallet-hint">
                    <?php esc_html_e('Opens the REAL8 Wallet with payment details pre-filled. Just confirm and send.', 'real8-gateway'); ?>
                </p>
                <div class="real8-qr-wrap">
                    <canvas id="real8-qr-canvas" data-wallet-url="<?php echo esc_url($real8_wallet_url); ?>"></canvas>
                    <p class="real8-qr-label"><?php esc_html_e('Scan with your phone to pay', 'real8-gateway'); ?></p>
                </div>
            </div>

            <details class="real8-manual-details">
                <summary><?php esc_html_e('Or pay manually from any Stellar wallet', 'real8-gateway'); ?></summary>
                <div class="real8-warning" style="margin-top: 12px;">
                    <strong>&#9888; <?php esc_html_e('IMPORTANT:', 'real8-gateway'); ?></strong>
                    <?php esc_html_e('You MUST include the memo exactly as shown above. Without the correct memo, your payment cannot be matched to your order.', 'real8-gateway'); ?>
                </div>
            </details>

            <div class="real8-asset-info">
                <div class="real8-asset-header" style="border-left: 4px solid <?php echo esc_attr($real8_token_color); ?>;">
                    <strong><?php echo esc_html($real8_token_name); ?> (<?php echo esc_html($asset_code); ?>)</strong>
                </div>
                <?php if ($real8_is_native): ?>
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
                <button type="button" class="button real8-manual-check-btn"><?php esc_html_e('Check payment now', 'real8-gateway'); ?></button>
                <p class="real8-manual-check-msg" style="display:none"></p>
                <small class="real8-manual-check-hint"><?php esc_html_e('If you already paid, click to force verification.', 'real8-gateway'); ?></small>
            </div>
            <p class="real8-order-total">
                <?php
                printf(
                    /* translators: 1: order total, 2: token amount, 3: token code */
                    esc_html__('Order Total: %1$s (approximately %2$s $%3$s at current rate)', 'real8-gateway'),
                    wp_kses_post(wc_price($order->get_total())),
                    esc_html(number_format($amount, 2)),
                    esc_html($asset_code)
                );
                ?>
            </p>
        </div>
    <?php endif; ?>

</div>
