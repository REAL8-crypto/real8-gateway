/**
 * REAL8 Gateway Admin JavaScript
 *
 * @package REAL8_Gateway
 */

(function($) {
    'use strict';

    var REAL8Admin = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Re-check wallet status when address changes
            $('#woocommerce_real8_payment_merchant_address').on('blur', this.checkWalletStatus);
        },

        /**
         * Check wallet status via AJAX (for real-time validation)
         * Note: The initial status is rendered server-side
         * This is for live updates when the user changes the address
         */
        checkWalletStatus: function() {
            var address = $(this).val().trim().toUpperCase();
            var $statusDiv = $('#stellar-wallet-status');

            if (!address) {
                return;
            }

            // Basic format validation
            if (address.length !== 56 || address[0] !== 'G') {
                $statusDiv.html(
                    '<div class="stellar-status-box stellar-status-error">' +
                    '<span class="dashicons dashicons-no"></span>' +
                    '<span class="stellar-address-message"></span>' +
                    '</div>'
                );
                $statusDiv.find('.stellar-address-message').text(real8_admin.strings.invalid);
                return;
            }

            // Show loading state
            $statusDiv.html(
                '<div class="stellar-status-box" style="background: #f0f0f0;">' +
                '<span class="dashicons dashicons-update" style="animation: real8-spin 1s linear infinite;"></span>' +
                '<span class="stellar-address-message"></span>' +
                '</div>'
            );

            $statusDiv.find('.stellar-address-message').text(real8_admin.strings.save);

            // The actual check happens on page reload after save
            // This is just visual feedback for the user
        }
    };

    $(document).ready(function() {
        REAL8Admin.init();
    });

})(jQuery);
