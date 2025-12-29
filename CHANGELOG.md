# Changelog

All notable changes to REAL8 Gateway for WooCommerce will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
- Merchant address validation (format and trustline check)
- Order notes with transaction details on payment confirmation
- Support for WooCommerce HPOS (High-Performance Order Storage)
- Internationalization ready (text domain: real8-gateway)

### Technical Details
- Database table: `{prefix}real8_payments` for payment tracking
- Order meta fields for payment details storage
- Stellar Horizon API integration for payment verification
- WordPress transients for price caching (60 seconds)
- Compatible with WordPress 5.8+ and WooCommerce 5.0+
- Requires PHP 7.4+

---

## Roadmap

### [1.1.0] - Planned
- QR code display for payment address
- Admin dashboard with payment statistics
- Manual payment check button in order admin
- Support for additional currencies (EUR, GBP)

### [1.2.0] - Planned
- Multi-chain support (BSC wREAL8)
- Webhook notifications for payment events
- Partial payment handling

### [2.0.0] - Planned
- Full multi-chain support (Base, Optimism, Solana)
- Automatic refund processing
- Integration with WooCommerce Subscriptions
