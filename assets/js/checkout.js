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

            if (status === 'pending') {
                this.startPaymentCheck(orderId);
                this.startCountdown();
            }
        },

        /**
         * Start periodic payment status checks
         */
        startPaymentCheck: function(orderId) {
            var self = this;
            var interval = real8_gateway.check_interval || 15000;

            // Initial check after 5 seconds
            setTimeout(function() {
                self.checkPaymentStatus(orderId);
            }, 5000);

            // Then check every X seconds
            this.checkInterval = setInterval(function() {
                self.checkPaymentStatus(orderId);
            }, interval);
        },

        /**
         * Check payment status via AJAX
         */
        checkPaymentStatus: function(orderId) {
            var self = this;

            $.ajax({
                url: real8_gateway.ajax_url,
                type: 'POST',
                data: {
                    action: 'real8_check_payment_status',
                    nonce: real8_gateway.nonce,
                    order_id: orderId
                },
                success: function(response) {
                    if (response.success) {
                        self.handleStatusResponse(response.data);
                    }
                },
                error: function() {
                    console.log('REAL8 Gateway: Error checking payment status');
                }
            });
        },

        /**
         * Handle status check response
         */
        handleStatusResponse: function(data) {
            var $statusBox = $('.real8-payment-status');

            if (data.status === 'confirmed' || data.status === 'completed') {
                // Payment received!
                this.stopChecks();

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
