/**
 * REAL8 Gateway Checkout JavaScript
 *
 * Handles payment status checking and countdown timer
 *
 * @package REAL8_Gateway
 */

(function($) {
    'use strict';

    var REAL8Gateway = {
        checkInterval: null,
        countdownInterval: null,

        init: function() {
            this.bindEvents();
            this.initPaymentPage();
        },

        bindEvents: function() {
            // Copy buttons
            $(document).on('click', '.real8-copy-btn', this.handleCopy);


            // Manual check button (forces a Stellar check)
            $(document).on('click', '.real8-manual-check-btn', function(e) {
                e.preventDefault();

                var $box = $('#real8-payment-instructions');
                if ($box.length === 0) return;

                var orderId = $box.data('order-id');
                var orderKey = $box.data('order-key') || real8_gateway.order_key || (new URLSearchParams(window.location.search).get('key') || '');

                // UI loading state
                var $btn = $(this);
                $btn.prop('disabled', true).addClass('real8-loading');
                $btn.data('old-text', $btn.text());
                $btn.text(real8_gateway.strings.manual_checking || 'Checking now...');

                // Force check
                REAL8Gateway.checkPaymentStatus(orderId, orderKey, 1);
            });

            // Payment method selection
            $('body').on('change', 'input[name="payment_method"]', this.handleMethodChange);
        },

        initPaymentPage: function() {
            var $paymentBox = $('#real8-payment-instructions');
            if ($paymentBox.length === 0) {
                return;
            }

            var status = $paymentBox.data('status');
            var orderId = $paymentBox.data('order-id');
            var orderKey = $paymentBox.data('order-key') || real8_gateway.order_key || (new URLSearchParams(window.location.search).get('key') || '');

            if (status === 'pending') {
                this.startPaymentCheck(orderId, orderKey);
                this.startCountdown();
            }
        },

        /**
         * Start periodic payment status checks
         */
        startPaymentCheck: function(orderId, orderKey) {
            var self = this;
            var interval = real8_gateway.check_interval || 15000;

            // Initial check after 5 seconds
            setTimeout(function() {
                self.checkPaymentStatus(orderId, orderKey, 0);
            }, 5000);

            // Then check every X seconds
            this.checkInterval = setInterval(function() {
                self.checkPaymentStatus(orderId, orderKey, 0);
            }, interval);
        },

        /**
         * Check payment status via AJAX
         */
        checkPaymentStatus: function(orderId, orderKey, force) {
            var self = this;

            // WC-AJAX ONLY (no admin ajax)
            var wcUrl = real8_gateway.wc_ajax_url || '';
            if (!wcUrl && real8_gateway.home_url) {
                wcUrl = String(real8_gateway.home_url).replace(/\/?$/, '/') + '?wc-ajax=real8_check_payment_status';
            }

            if (!wcUrl) {
                console.log('REAL8 Gateway: Missing WC-AJAX URL');
                self.showManualMessage(real8_gateway.strings.error);
                return;
            }

            var data = {
                order_id: orderId,
                order_key: orderKey || '',
                force: force ? 1 : 0
            };

            // Nonce is optional; backend validates it when present
            if (real8_gateway.nonce) {
                data.nonce = real8_gateway.nonce;
            }

            // Normalize endpoint placeholder and bypass aggressive HTML caches
            var ajaxUrl = wcUrl;
            if (ajaxUrl.indexOf('%%endpoint%%') !== -1) {
                ajaxUrl = ajaxUrl.replace('%%endpoint%%', 'real8_check_payment_status');
            }
            ajaxUrl += (ajaxUrl.indexOf('?') !== -1 ? '&' : '?') + '_ts=' + Date.now();

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: data,
                timeout: 20000,
                success: function(response) {
                    if (!response || typeof response.success === 'undefined') {
                        self.showManualMessage(real8_gateway.strings.error);
                        return;
                    }

                    if (response.success) {
                        self.handleStatusResponse(response.data || {});
                        if (force) {
                            var st = (response.data && response.data.status) ? response.data.status : '';
                            if (response.data && response.data.check_error) {
                                self.showManualMessage(response.data.check_error, 'error');
                            } else if (st && st !== 'confirmed') {
                                self.showManualMessage(real8_gateway.strings.not_found || 'Pago aún no encontrado en la red. Intenta de nuevo en unos segundos.', 'error');
                            } else {
                                self.showManualMessage((response.data && response.data.status === 'confirmed') ? real8_gateway.strings.paid : real8_gateway.strings.checking, 'ok');
                            }
                        }
                        return;
                    }

                    // API returned JSON error
                    var msg = (response.data && response.data.message) ? response.data.message : real8_gateway.strings.error;
                    self.showManualMessage(msg, 'error');
                },
                error: function(xhr, status) {
                    var msg = real8_gateway.strings.error;
                    var parsed = null;
                    var parsedOk = false;

                    try {
                        if (xhr && xhr.responseText) {
                            parsed = JSON.parse(xhr.responseText);
                            parsedOk = true;
                            if (parsed && parsed.data && parsed.data.message) {
                                msg = parsed.data.message;
                            }
                        }
                    } catch(e) {}

                    // If the response wasn't JSON (common when an HTML cache / WAF returns a page),
                    // try REST fallback that uses /wp-json/ (usually bypassed from HTML caches).
                    if (!parsedOk && real8_gateway.rest_check_url) {
                        console.warn('REAL8 Gateway: WC-AJAX failed/non-JSON, trying REST fallback');
                        return self.checkPaymentStatusRest(orderId, orderKey, force);
                    }

                    self.showManualMessage(msg, 'error');
                    console.log('REAL8 Gateway: Error checking payment status', status || '', (xhr && xhr.status) ? xhr.status : '');
                }
            });
        },



        /**
         * REST fallback check (bypasses caches that serve HTML for ?wc-ajax=...)
         */
        checkPaymentStatusRest: function(orderId, orderKey, force) {
            var self = this;

            if (!real8_gateway.rest_check_url) {
                self.showManualMessage(real8_gateway.strings.error, 'error');
                return;
            }

            var data = {
                order_id: orderId,
                order_key: orderKey || '',
                force: force ? 1 : 0
            };

            var restUrl = String(real8_gateway.rest_check_url);
            restUrl += (restUrl.indexOf('?') !== -1 ? '&' : '?') + '_ts=' + Date.now();

            $.ajax({
                url: restUrl,
                type: 'POST',
                dataType: 'json',
                data: data,
                timeout: 20000,
                success: function(response) {
                    if (response && response.success && response.data) {
                        self.handleStatusResponse(response.data);
                        return;
                    }

                    var msg = (response && response.data && response.data.message) ? response.data.message : real8_gateway.strings.error;
                    self.showManualMessage(msg, 'error');
                },
                error: function(xhr, status) {
                    var msg = real8_gateway.strings.error;
                    try {
                        if (xhr && xhr.responseText) {
                            var parsed = JSON.parse(xhr.responseText);
                            if (parsed && parsed.data && parsed.data.message) msg = parsed.data.message;
                        }
                    } catch(e) {}
                    self.showManualMessage(msg, 'error');
                    console.log('REAL8 Gateway: REST check failed', status || '', (xhr && xhr.status) ? xhr.status : '');
                }
            });
        },



        /**
         * Show message under manual check button
         */
        showManualMessage: function(message, type) {
            var $box = $('#real8-payment-instructions');
            if ($box.length === 0) return;

            var $btn = $box.find('.real8-manual-check-btn');
            if ($btn.length) {
                var oldText = $btn.data('old-text');
                $btn.removeClass('real8-loading').prop('disabled', false);
                $btn.text(oldText || (real8_gateway.strings.manual_check || 'Check payment now'));
            }

            var $msg = $box.find('.real8-manual-check-msg');
            if ($msg.length === 0) return;

            if (!message) {
                $msg.hide();
                return;
            }

            $msg
                .removeClass('is-error is-ok')
                .addClass(type === 'error' ? 'is-error' : 'is-ok')
                .text(String(message))
                .show();
        },

        /**
         * Handle status check response
         */

        handleStatusResponse: function(data) {
            var $statusBox = $('.real8-payment-status');

            if (data.status === 'confirmed' || data.status === 'completed') {
                // Payment received!
                this.stopChecks();
                $('.real8-manual-check-btn').prop('disabled', true).hide();
                $('.real8-manual-check-msg').hide();

                $statusBox
                    .removeClass('real8-status-pending')
                    .addClass('real8-status-confirmed')
                    .html(
                        '<span class="real8-status-icon">&#10004;</span>' +
                        '<h3>' + real8_gateway.strings.paid + '</h3>' +
                        '<p>Transaction: ' + (data.tx_hash || '') + '</p>'
                    );

                // Hide payment details
                $('.real8-payment-details, .real8-payment-footer').fadeOut();

                // Reload page after 3 seconds to show updated order status
                setTimeout(function() {
                    location.reload();
                }, 3000);

            } else if (data.status === 'expired') {
                // Payment expired
                this.stopChecks();
                $('.real8-manual-check-btn').prop('disabled', true).hide();
                $('.real8-manual-check-msg').hide();

                $statusBox
                    .removeClass('real8-status-pending')
                    .addClass('real8-status-expired')
                    .html(
                        '<span class="real8-status-icon">&#10060;</span>' +
                        '<h3>' + real8_gateway.strings.expired + '</h3>' +
                        '<p>Please contact support if you made a payment.</p>'
                    );

                $('.real8-payment-details, .real8-checking-status').fadeOut();

            } else {
                // Still pending - update countdown
                if (data.expires_in !== undefined) {
                    this.updateCountdown(data.expires_in);
                }
            }
        },

        /**
         * Start countdown timer
         */
        startCountdown: function() {
            var self = this;
            var $countdown = $('#real8-countdown');
            if ($countdown.length === 0) {
                return;
            }

            // Get initial minutes
            var minutes = parseInt($countdown.text(), 10);
            var seconds = minutes * 60;

            this.countdownInterval = setInterval(function() {
                seconds--;

                if (seconds <= 0) {
                    self.handleExpired();
                    return;
                }

                var mins = Math.floor(seconds / 60);
                var secs = seconds % 60;

                if (secs > 0) {
                    $countdown.text(mins + ':' + (secs < 10 ? '0' : '') + secs);
                } else {
                    $countdown.text(mins);
                }

                // Show urgency when less than 5 minutes
                if (mins < 5) {
                    $countdown.css('color', '#dc3545');
                }
            }, 1000);
        },

        /**
         * Update countdown from server response
         */
        updateCountdown: function(secondsRemaining) {
            var mins = Math.floor(secondsRemaining / 60);
            var secs = secondsRemaining % 60;

            var $countdown = $('#real8-countdown');
            if ($countdown.length) {
                if (secs > 0) {
                    $countdown.text(mins + ':' + (secs < 10 ? '0' : '') + secs);
                } else {
                    $countdown.text(mins);
                }
            }
        },

        /**
         * Handle payment expiration
         */
        handleExpired: function() {
            this.stopChecks();

            var $statusBox = $('.real8-payment-status');
            $statusBox
                .removeClass('real8-status-pending')
                .addClass('real8-status-expired')
                .html(
                    '<span class="real8-status-icon">&#10060;</span>' +
                    '<h3>' + real8_gateway.strings.expired + '</h3>' +
                    '<p>The payment window has expired.</p>'
                );

            $('.real8-payment-details, .real8-checking-status').fadeOut();
        },

        /**
         * Stop all interval checks
         */
        stopChecks: function() {
            if (this.checkInterval) {
                clearInterval(this.checkInterval);
                this.checkInterval = null;
            }
            if (this.countdownInterval) {
                clearInterval(this.countdownInterval);
                this.countdownInterval = null;
            }
        },

        /**
         * Handle copy button click
         */
        handleCopy: function(e) {
            e.preventDefault();
            var $btn = $(this);
            var textToCopy = $btn.data('copy');

            // Copy to clipboard
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(textToCopy).then(function() {
                    REAL8Gateway.showCopySuccess($btn);
                });
            } else {
                // Fallback for older browsers
                var $temp = $('<input>');
                $('body').append($temp);
                $temp.val(textToCopy).select();
                document.execCommand('copy');
                $temp.remove();
                REAL8Gateway.showCopySuccess($btn);
            }
        },

        /**
         * Show copy success feedback
         */
        showCopySuccess: function($btn) {
            $btn.addClass('copied');
            var originalHtml = $btn.html();
            $btn.html('<span class="dashicons dashicons-yes"></span>');

            setTimeout(function() {
                $btn.removeClass('copied');
                $btn.html(originalHtml);
            }, 1500);
        },

        /**
         * Handle payment method change on checkout
         */
        handleMethodChange: function() {
            var method = $('input[name="payment_method"]:checked').val();

            if (method === 'real8_payment') {
                // Could show estimated REAL8 amount here
                REAL8Gateway.showEstimate();
            }
        },

        /**
         * Show estimated REAL8 amount on checkout
         */
        showEstimate: function() {
            // This could be enhanced to show real-time estimate
            // For now, the estimate is calculated server-side during checkout
        }
    };

    // Initialize when DOM is ready
    $(document).ready(function() {
        REAL8Gateway.init();
    });

    // Make available globally
    window.REAL8Gateway = REAL8Gateway;

})(jQuery);
