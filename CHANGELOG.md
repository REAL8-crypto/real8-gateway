# Changelog

All notable changes to REAL8 Gateway for WooCommerce will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.0.9] - 2026-01-09

### Added
- **REAL8 prices throughout the shop** - Product prices now display REAL8 token equivalents below USD prices on shop pages, product pages, and cart
- **Admin toggle** - New setting "Mostrar precios REAL8 en tienda" to enable/disable shop price display
- **New class `REAL8_Price_Display`** - Handles dual-currency price display using existing pricing API

### Fixed
- **Undefined `$order_key` bug** in `process_payment()` - Added proper variable definition and validation for order-pay page security

---

## [3.0.8.1] - 2026-01-04

### Fixed
- Payment verification now paginates Horizon `/transactions` (up to 5 pages × 200) before inspecting operations, matching the proven v3.0.7 flow.

---

## [3.0.8] - 2026-01-04

### Added
- **Underpayment tolerance settings** (percent + minimum) to reduce false negatives caused by rounding/fees.

### Fixed
- **Restored full multi-asset checkout experience** (token selector + live prices) and **copy buttons** (amount/address/memo) while keeping **WC-AJAX** (no admin-ajax).
- **More robust on-chain verification** using Horizon transaction + operations scan by memo.

### Changed
- Payment verification now uses a configurable *minimum acceptable amount* (expected minus tolerance) while still accepting any overpayment.

---

## [3.0.1] - 2026-01-04

### Fixed
- **Critical JS error: "real8_gateway is not defined"** - Fixed variable name mismatch between PHP localization (`stellar_gateway`) and JavaScript (`real8_gateway`)
- **Memo persistence for returning customers** - When a customer returns to pay an existing pending order, the original memo is now preserved instead of generating a new one. This prevents payment matching failures.
- **Script loading on order-pay page** - Scripts now load correctly when customers pay from My Account > Orders > Pay

### Added
- **Comprehensive order notes** - Payment details are now logged to WooCommerce order notes:
  - Payment initiated: amount, token, price, memo, address, expiry time
  - Customer returns: note when reusing existing payment details
  - Payment expired: detailed note with expected amount and memo
  - Payment confirmed: (already existed) transaction details

### Changed
- Improved payment flow: if customer returns before expiry with same token, uses existing payment details
- Improved payment flow: if token changed or expired, generates new payment but reuses original memo

---

## [3.0.0] - 2026-01-03

### Added
- **Multi-token payment support** - Accept 7 Stellar tokens: XLM, REAL8, wREAL8, USDC, EURC, SLVR, GOLD
- **Token Registry** - New `class-token-registry.php` with centralized token definitions
- **Dual-source pricing** - Real-time prices from api.real8.org (REAL8, XLM, USDC) and Stellar Horizon orderbook (EURC, SLVR, GOLD, wREAL8)
- **Token selector at checkout** - Customers choose their preferred payment token with live pricing
- **Admin token multiselect** - Merchants enable/disable individual tokens
- **Per-token wallet validation** - Admin panel shows trustline status for all 7 tokens
- **Per-token statistics** - Payment stats broken down by token type

### Changed
- **Database schema** - Added `asset_code` and `asset_issuer` columns, renamed `amount_real8` to `amount_token`, renamed `real8_price` to `token_price`
- **Payment instructions** - Dynamic display based on selected token (shows native XLM differently from credit assets)
- **Payment monitor** - Now checks payments for any supported token with correct asset matching
- **Order meta keys** - Changed from `_real8_*` to `_stellar_*` for generic token support

### Technical Details
- Triangular pricing for Horizon tokens: TOKEN/XLM × XLM/USD = TOKEN/USD
- 5-minute cache per token using WordPress transients
- Fallback prices for API failures
- Backward compatible database migration (existing REAL8 orders preserved)

---

## [2.1.0] - 2026-01-02

### Changed
- **Updated merchant wallet setup instructions** - Simplified setup flow pointing users to app.real8.org
  - Removed multi-step wallet creation instructions
  - Added direct link to REAL8/Stellar Wallet creation at https://app.real8.org/
  - Added prominent warning about Secret Key backup
- **Improved Spanish translations** - Using formal "usted" form throughout
  - "Pago con REAL8" instead of "Pagar con REAL8" for title
  - "Pague con REAL8" instead of "Paga con tokens REAL8" for description
  - "Su Clave Pública Stellar" instead of "Dirección Stellar del comerciante"
- **Field label change** - "Merchant Stellar Address" → "Your Stellar Public Key"
- **Description update** - "Pay with REAL8 through the Stellar network. Fast, secure, and with very low fees."
- **Trustline error message** - Now includes helpful link to app.real8.org for creating trustlines

---

## [2.0.0] - 2025-12-29

### Added
- **Spanish translation (es_ES)** - Full translation of all plugin strings
  - Admin panel settings and messages
  - Checkout and payment instructions
  - Order status messages and notifications
  - Wallet validation messages
- **Translation template (.pot file)** - For creating additional translations

### Technical Details
- Translation files in `/languages/` directory
- POT template for translators
- Compiled .mo file for Spanish (es_ES)

---

## [1.1.0] - 2025-12-29

### Added
- **Wallet validation in admin panel** - Real-time status display showing:
  - Account existence on Stellar network
  - XLM funding status with balance display
  - REAL8 trustline status with balance display
  - Clear warnings for unfunded or misconfigured wallets
- **Setup instructions** - Step-by-step guide in admin panel for creating merchant wallet
- **Admin styles** - Professional styling for wallet status indicators
- **README badges** - Version, license, PHP, WordPress, WooCommerce, and Stellar badges

### Changed
- Enhanced merchant address field with custom HTML rendering
- Improved error messages for wallet configuration issues
- Updated documentation with merchant wallet setup section

## [1.0.0] - 2025-12-29

### Added
- Initial release of REAL8 Gateway for WooCommerce
- Stellar REAL8 payment acceptance
- Real-time pricing from api.real8.org/prices
- Automatic payment detection via WordPress cron (every minute)
- Unique memo generation for each order (R8-{order_id}-{random})
- Payment timeout with configurable duration (default: 30 minutes)
- Price buffer for volatility protection (default: 2%)
- Thank-you page with payment instructions
- Copy-to-clipboard buttons for address, amount, and memo
- Live countdown timer for payment window
- AJAX payment status checking (every 15 seconds)
- Email payment instructions in order confirmation
- Admin settings page under WooCommerce > Settings > Payments
- Merchant address validation (format check)
- Order notes with transaction details on payment confirmation
- Support for WooCommerce HPOS (High-Performance Order Storage)
- Internationalization ready (text domain: real8-gateway)
- GitHub Actions auto-deployment workflow

### Technical Details
- Database table: `{prefix}real8_payments` for payment tracking
- Order meta fields for payment details storage
- Stellar Horizon API integration for payment verification
- WordPress transients for price caching (60 seconds)
- Compatible with WordPress 5.8+ and WooCommerce 5.0+
- Requires PHP 7.4+

---

## Roadmap

### [3.1.0] - Planned
- QR code display for payment address
- Admin dashboard with payment statistics
- Manual payment check button in order admin

### [3.2.0] - Planned
- Webhook notifications for payment events
- Partial payment handling
- Additional translations (French, German, Portuguese)

### [4.0.0] - Planned
- Multi-chain support (BSC, Base, Optimism wREAL8)
- Automatic refund processing
- Integration with WooCommerce Subscriptions
