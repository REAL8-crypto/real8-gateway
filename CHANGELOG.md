# Changelog

All notable changes to REAL8 Gateway for WooCommerce will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

### [2.1.0] - Planned
- QR code display for payment address
- Admin dashboard with payment statistics
- Manual payment check button in order admin

### [2.2.0] - Planned
- Support for additional currencies (EUR, GBP)
- Webhook notifications for payment events
- Partial payment handling
- Additional translations (French, German, Portuguese)

### [3.0.0] - Planned
- Multi-chain support (BSC, Base, Optimism wREAL8)
- Automatic refund processing
- Integration with WooCommerce Subscriptions
