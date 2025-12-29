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
            var $statusDiv = $('#real8-wallet-status');

            if (!address) {
                return;
            }

            // Basic format validation
            if (address.length !== 56 || address[0] !== 'G') {
                $statusDiv.html(
                    '<div class="real8-status-box real8-status-error">' +
                    '<span class="dashicons dashicons-no"></span>' +
                    '<span>Invalid address format. Stellar addresses are 56 characters starting with G.</span>' +
                    '</div>'
                );
                return;
            }

            // Show loading state
            $statusDiv.html(
                '<div class="real8-status-box" style="background: #f0f0f0;">' +
                '<span class="dashicons dashicons-update" style="animation: real8-spin 1s linear infinite;"></span>' +
                '<span>' + real8_admin.strings.checking + '</span>' +
                '</div>'
            );

            // The actual check happens on page reload after save
            // This is just visual feedback for the user
        }
    };

    $(document).ready(function() {
        REAL8Admin.init();
    });

})(jQuery);
