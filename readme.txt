=== REAL8 Gateway for WooCommerce ===
Tags: woocommerce, payments, stellar, real8
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 4.6.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept REAL8 token payments on Stellar for USD WooCommerce orders, with local payment instructions and automatic verification.

== Description ==

REAL8 Gateway adds a REAL8 payment method to WooCommerce's classic checkout and Checkout block. Customers send REAL8 directly to the merchant's public Stellar address with a unique text memo. WordPress verifies the transaction using Stellar Horizon and marks the WooCommerce order paid.

Requirements: WooCommerce 8.3 or later, a USD store, and a funded Stellar mainnet account with a REAL8 trustline. This plugin does not hold funds or ask for private keys. Refunds are handled manually in the merchant's wallet; recording a WooCommerce refund does not send tokens.

Features include a configurable payment window, price buffer, bounded underpayment tolerance, optional REAL8 price equivalents, local QR codes and payment instructions in order emails. High-Performance Order Storage is supported. New quotes require a valid price; an unavailable pricing service does not use a hardcoded or indefinitely stale rate.

Customers can pay with any Stellar wallet that supports REAL8 and text memos. The REAL8 Wallet link is optional for customers. Hosted payment-intent redirects are disabled by default and require a separately configured service credential.

Source and development documentation: https://github.com/REAL8-crypto/real8-gateway

= External services =

Enabling and configuring REAL8 Payments authorizes the price and blockchain requests described below. Activation alone does not request prices, validate a wallet or contact an update service. Network providers receive the sending server's IP address and HTTP request metadata, including the WordPress version and site URL in the default HTTP User-Agent. Wallet visits expose the customer's IP address and browser metadata to the wallet provider.

REAL8 pricing API (https://api.real8.org)
* Purpose: fetch current REAL8/USD prices from https://api.real8.org/prices.
* When: payment quotes and enabled shop price equivalents, cached for 60 seconds.
* Sent: a price request; no customer name, email, billing address or order access key is sent for pricing.
* No account or API key is needed for price requests. A network connection is required.
* Service documentation: https://api.real8.org/

Stellar Horizon (https://horizon.stellar.org)
* Purpose: validate the configured merchant wallet and verify incoming payments.
* When: after a public address has been saved in settings, every minute while payments await verification, and during customer payment status checks.
* Sent: merchant public Stellar address, transaction hashes and pagination parameters. Asset code and issuer may be used for orderbook requests by the legacy token API.
* No account or API key is required. Transaction data, addresses and text memos on Stellar are public and permanent.
* Documentation: https://developers.stellar.org/docs/data/apis/horizon
* Terms: https://stellar.org/terms-of-service
* Privacy: https://stellar.org/privacy-policy

REAL8 Wallet (https://app.real8.org)
* Purpose: an optional customer-facing wallet page with payment details prefilled.
* When: a customer clicks or scans the wallet link, or when the merchant separately enables hosted payment-intent redirects.
* A direct wallet link sends the public destination address, token amount, asset code and text memo in URL parameters. It does not send the WooCommerce order key.
* Hosted intents: only with the opt-in setting and REAL8_PAYMENT_INTENT_SECRET in wp-config.php, WordPress sends the order ID, token and USD amounts, asset code and issuer, public destination, memo, expiry and return URL INCLUDING the WooCommerce order access key to https://api.real8.org/payment-intents. It reports the intent ID and transaction hash to /payment-intents/{id}/paid after confirmation. These details are available to the hosted service; the return URL grants access to the order. No customer name, email or billing address is explicitly included.
* Customers need a funded Stellar wallet holding REAL8 to send payment. Merchants without a hosted-service credential can use the complete local instruction flow.

= Privacy and stored data =

The plugin stores payment records in the site's database: order ID, memo, expected amount, price, public address, expiry, status and transaction hash. Transaction claims prevent reuse and remain when a payment is retried. Order metadata and notes also contain payment amounts, public sender addresses and transaction details. Operational events are recorded in WooCommerce logs. Deactivation and removal retain financial records for reconciliation. Merchants manage their retention through their database and WooCommerce retention tools; the plugin does not erase blockchain records.

== Installation ==

1. Install and activate WooCommerce 8.3 or later. Set your store currency to USD.
2. Upload the plugin ZIP in Plugins > Add New and activate it.
3. Open WooCommerce > Settings > Payments > REAL8 Payments.
4. Save your public Stellar address. Add a REAL8 trustline for issuer GBVYYQ7XXRZW6ZCNNCL2X2THNPQ6IM4O47HAA25JTAG7Z3CXJCQ3W4CD using your wallet. Keep enough XLM to meet the network's account and trustline reserves.
5. Read the external-service disclosure and enable REAL8 Payments.
6. Confirm that WordPress cron runs regularly. On low-traffic sites, configure a server cron to run WordPress scheduled events.

== Frequently Asked Questions ==

= Does this work in currencies other than USD? =
No. The gateway and REAL8 price equivalents are disabled for other store currencies. The plugin does not provide foreign-exchange conversion.

= Do I need a REAL8 service credential? =
No for local payment instructions, prices and Stellar verification. The optional hosted wallet redirect requires REAL8_PAYMENT_INTENT_SECRET in wp-config.php and the hosted redirect checkbox. Obtain service access before configuring this credential; never put a secret in the plugin files.

= What if a payment is late or verification is unavailable? =
The server checks Horizon before expiring a payment. Network errors leave it pending for verification; if it still cannot be verified a day after its deadline, the order is put on hold with a note for the merchant. Payments sent after an order is expired require merchant reconciliation. A payment for an order whose total, currency or payment method changed after quoting is recorded and held for merchant review. Contact the store with the transaction hash if a payment is not credited. Check cron and WooCommerce > Status > Logs.

= Are subscriptions and automatic refunds supported? =
The gateway supports one-time product payments. It does not automatically debit renewals or issue on-chain refunds. Some subscription plugins may permit manual renewal payments; test your combination before use.

== Changelog ==

= 4.6.0 =
* WordPress.org package, dependency metadata, license notices and service disclosures.
* Native Checkout block integration and removal of the external plugin updater.
* Explicit opt-in for hosted payment-intent redirects.
* Hardened payment destinations, transaction claims, quote handling and final verification.
* Existing stores that used the hosted wallet redirect keep it after upgrading.

= 4.5.4 =
* Repair confirmed payments whose WooCommerce orders were not completed.
* Guard expiry writes against concurrent confirmations.
