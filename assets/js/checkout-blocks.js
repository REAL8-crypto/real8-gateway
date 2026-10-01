/** REAL8 Payments for the WooCommerce Checkout block. */
(function () {
    'use strict';
    var settings = window.wc.wcSettings.getSetting('real8_payment_data', {});
    var createElement = window.wp.element.createElement;
    var decode = window.wp.htmlEntities.decodeEntities;
    var content = createElement('p', null, decode(settings.description || ''));
    window.wc.wcBlocksRegistry.registerPaymentMethod({
        name: 'real8_payment',
        label: createElement('span', null, decode(settings.title || 'REAL8')),
        content: content,
        edit: content,
        canMakePayment: function () { return true; },
        ariaLabel: decode(settings.title || 'REAL8'),
        supports: {features: settings.supports || ['products']}
    });
})();
