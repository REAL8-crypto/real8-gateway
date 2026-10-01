/** Behavioral checks for the browser assets; run with node tools/test-frontend.mjs. */
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import {fileURLToPath} from 'node:url';

const asset = name => readFileSync(fileURLToPath(new URL('../assets/js/' + name, import.meta.url)), 'utf8');
const texts = new Map();
const cleared = [];
const selection = selector => ({
    length: 1,
    ready() {},
    on() { return this; },
    text(value) { if (value !== undefined) texts.set(selector, value); return this; },
    html() { return this; },
    find(child) { return selection(selector + ' ' + child); },
    prop() { return this; },
    hide() { return this; },
    removeClass() { return this; },
    addClass() { return this; },
    fadeOut() { return this; }
});
const context = {
    window: {}, document: {}, jQuery: selection,
    real8_gateway: {strings: {paid: 'Received', expired: 'Expired', transaction: 'Transaction:', contact_support: 'Contact support', verification_pending: 'Verification pending'}},
    clearInterval: id => cleared.push(id), setTimeout() {}, console
};
vm.runInNewContext(asset('checkout.js'), context);
const gateway = context.window.REAL8Gateway;
gateway.checkInterval = 123;
gateway.countdownInterval = 456;
gateway.handleExpired();
assert.equal(gateway.checkInterval, 123, 'Countdown expiry must retain payment polling');
assert.deepEqual(cleared, [456], 'Only the local countdown stops');
assert.equal(texts.get('.real8-checking-status'), 'Verification pending');
gateway.handleStatusResponse({status: 'confirmed', tx_hash: '<img src=x onerror=alert(1)>'});
assert.equal(gateway.checkInterval, null, 'Server confirmation stops payment polling');
assert.equal(texts.get('.real8-payment-status p'), 'Transaction: <img src=x onerror=alert(1)>', 'Transaction data goes to text(), never HTML markup');

let registered;
vm.runInNewContext(asset('checkout-blocks.js'), {
    window: {
        wc: {wcSettings: {getSetting: () => ({title: 'REAL8', description: 'Send tokens', supports: ['products']})}, wcBlocksRegistry: {registerPaymentMethod: method => {registered = method;}}},
        wp: {element: {createElement: (...args) => args}, htmlEntities: {decodeEntities: value => value}}
    }
});
assert.equal(registered.name, 'real8_payment');
assert.equal(registered.canMakePayment(), true);
assert.deepEqual(Array.from(registered.supports.features), ['products']);

const qr = {window: {}, TextEncoder};
vm.runInNewContext(asset('qrcode-1.5.4.js'), qr);
const code = qr.window.QRCode.create('https://app.real8.org/?wc_pay=fixture&wc_amount=3.3333333&wc_memo=audit');
assert.ok(code.modules.size >= 21 && code.modules.data.length === code.modules.size ** 2, 'Bundled QR library encodes a payment link');
console.log('PASS 9 frontend behavior checks.');
