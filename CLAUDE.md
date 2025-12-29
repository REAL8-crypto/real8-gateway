# CLAUDE.md - REAL8 Gateway Plugin Development Guide

This file provides guidance to Claude Code when working with the REAL8 Gateway WordPress/WooCommerce plugin.

## Project Overview

REAL8 Gateway is a WooCommerce payment gateway plugin that enables merchants to accept REAL8 tokens (Stellar blockchain) as payment for orders. This is the **opposite** of the real8-bridge plugin - customers PAY WITH REAL8 rather than buying REAL8.

**Current Version**: 1.0.0
**Repository**: https://github.com/REAL8-crypto/real8-gateway (to be created)
**Deployment**: Automatic via GitHub Actions (same workflow as real8-bridge)

## Critical Information

### REAL8 Token Details
- **Asset Code**: REAL8
- **Asset Issuer**: `GBVYYQ7XXRZW6ZCNNCL2X2THNPQ6IM4O47HAA25JTAG7Z3CXJCQ3W4CD`
- **Network**: Stellar Mainnet (Public)
- **Decimals**: 7 (Stellar standard)

### Pricing System
- **Primary Source**: https://api.real8.org/prices
- **Response Format**: `{"REAL8_USDC": {"priceInUSD": 0.0072, ...}}`
- **Cache Duration**: 60 seconds
- **Price Buffer**: 2% (configurable) - customers pay slightly more to cover volatility

### Payment Settings (Industry Standards)
- **Payment Timeout**: 30 minutes (standard for crypto payments)
- **Price Buffer**: 2% (standard for volatility protection)
- **Check Interval**: Every minute via WordPress cron

## File Structure

```
real8-gateway/
├── real8-gateway.php              # Main plugin file, constants, bootstrap
├── README.md                      # User documentation
├── CLAUDE.md                      # This file (development guide)
├── CHANGELOG.md                   # Version history (to be added)
│
├── includes/
│   ├── class-stellar-payment-api.php  # Stellar API integration
│   ├── class-payment-gateway.php      # WooCommerce gateway class
│   ├── class-payment-monitor.php      # Cron payment monitoring
│   └── templates/
│       └── payment-instructions.php   # Thank-you page template
│
├── assets/
│   ├── css/checkout.css               # Checkout/payment page styles
│   └── js/checkout.js                 # Payment status checking, countdown
│
└── languages/                         # Translation files (to be added)
```

## Key Classes & Methods

### class-stellar-payment-api.php

**get_real8_price($force_refresh)**
- Fetches REAL8/USD price from api.real8.org
- Uses WordPress transients for caching (60 seconds)
- Falls back to last known good price

**calculate_real8_amount($usd_amount, $include_buffer)**
- Converts USD to REAL8 amount
- Applies price buffer for volatility protection
- Returns array with amount and price details

**check_payment($merchant_address, $memo, $expected_amount)**
- Queries Stellar Horizon for incoming payments
- Matches by memo (TEXT) and verifies amount
- Returns payment details or false

**generate_payment_memo($order_id)**
- Creates unique memo: `R8-{order_id}-{random}`
- Max 28 chars (Stellar TEXT memo limit)

### class-payment-gateway.php (WC_Gateway_REAL8)

**process_payment($order_id)**
- Calculates REAL8 amount for order total
- Generates unique memo
- Creates payment record in database
- Returns redirect to thank-you page

**thankyou_page($order_id)**
- Displays payment instructions
- Shows address, amount, memo with copy buttons
- Live countdown timer
- Auto-checks payment status via AJAX

**ajax_check_payment_status()**
- Called by JavaScript every 15 seconds
- Returns current payment status
- Updates order if payment confirmed/expired

### class-payment-monitor.php

**check_pending_payments()**
- Runs every minute via WordPress cron
- Queries all pending payments
- Checks Stellar blockchain for each
- Updates order status on confirmation

**mark_payment_confirmed($payment, $result)**
- Updates payment record in database
- Adds order note with TX details
- Calls `$order->payment_complete()`

## Database Schema

Table: `{prefix}real8_payments`

| Column | Type | Description |
|--------|------|-------------|
| id | bigint(20) | Auto-increment primary key |
| order_id | bigint(20) | WooCommerce order ID (unique) |
| memo | varchar(64) | Payment memo for matching |
| amount_real8 | decimal(20,7) | Expected REAL8 amount |
| amount_usd | decimal(10,2) | Order total in USD |
| real8_price | decimal(15,8) | REAL8 price at order time |
| merchant_address | varchar(56) | Stellar address for payment |
| status | varchar(20) | pending, confirmed, expired |
| stellar_tx_hash | varchar(64) | Stellar transaction hash |
| expires_at | datetime | Payment deadline |
| paid_at | datetime | When payment confirmed |
| created_at | datetime | Record creation time |

## Order Meta Fields

| Key | Description |
|-----|-------------|
| `_real8_payment_memo` | Unique payment memo |
| `_real8_payment_amount` | Expected REAL8 amount |
| `_real8_payment_price` | REAL8/USD price at order time |
| `_real8_payment_expires` | Payment deadline |
| `_real8_merchant_address` | Merchant Stellar address |
| `_real8_tx_hash` | Stellar TX hash (after payment) |
| `_real8_paid_amount` | Actual amount received |
| `_real8_from_address` | Customer's Stellar address |
| `_real8_paid_at` | Payment confirmation time |

## Payment Flow

```
1. Customer checkout → process_payment()
   ├── Calculate REAL8 amount (with 2% buffer)
   ├── Generate unique memo (R8-{order_id}-{random})
   ├── Store in wp_real8_payments table
   ├── Store in order meta
   └── Redirect to thank-you page

2. Thank-you page → thankyou_page()
   ├── Display payment instructions
   ├── Show countdown timer
   └── Start AJAX status checking (15s interval)

3. Background (every minute) → check_pending_payments()
   ├── Query all pending payments
   ├── For each: check Stellar Horizon API
   ├── If payment found with correct memo + amount:
   │   └── mark_payment_confirmed() → order->payment_complete()
   └── If expired: mark as expired → order failed

4. AJAX status check → ajax_check_payment_status()
   ├── Return current status to frontend
   └── If confirmed: JS shows success, reloads page
```

## Development Workflow

### Making Changes

1. Edit files locally at `/mnt/data/WebDes/REAL8/real8.org/www/wp-content/plugins/real8-gateway/`
2. Test changes on local WordPress (if available)
3. Update CHANGELOG.md with changes
4. Bump version in real8-gateway.php (lines 6 and 21)
5. Commit and push to GitHub
6. GitHub Actions deploys to production

### Version Release

Update in 2 places:
- Line 6: `* Version: X.X.X`
- Line 21: `define('REAL8_GATEWAY_VERSION', 'X.X.X');`

### Testing Checklist

- [ ] Price fetching from api.real8.org works
- [ ] Gateway appears at checkout when configured
- [ ] Order creates payment record with correct amount
- [ ] Thank-you page shows payment instructions
- [ ] Copy buttons work for address/amount/memo
- [ ] Countdown timer works
- [ ] Cron checks pending payments
- [ ] Payment confirmation updates order status
- [ ] Expired payments marked correctly
- [ ] Email includes payment instructions

## API Integration

### api.real8.org/prices

**Endpoint**: `GET https://api.real8.org/prices`

**Response**:
```json
{
  "REAL8_USDC": {
    "asset": "REAL8",
    "price": 0.0072,
    "priceInUSD": 0.0072,
    "lastUpdate": "2025-12-29T...",
    "source": "trade"
  }
}
```

**Usage**: `$data['REAL8_USDC']['priceInUSD']`

### Stellar Horizon API

**Payments Endpoint**: `GET {HORIZON}/accounts/{ADDRESS}/payments`

**Transaction Endpoint**: `GET {HORIZON}/transactions/{TX_HASH}`

**Account Endpoint**: `GET {HORIZON}/accounts/{ADDRESS}` (for trustline check)

## Security Considerations

1. **No secret keys stored** - Only public addresses in WordPress
2. **Memo uniqueness** - Each order has unique memo to prevent cross-order matching
3. **Amount verification** - Must match expected amount (with small tolerance)
4. **Timeout protection** - Payments expire after 30 minutes
5. **Trustline validation** - Merchant address must have REAL8 trustline

## Known Limitations

1. **Stellar only** - Initial version supports only Stellar REAL8 (not EVM chains)
2. **Manual setup** - Merchant must create wallet and add trustline manually
3. **No refunds** - Plugin doesn't handle refunds (manual process)
4. **WordPress cron dependent** - Payment detection relies on WP-Cron

## Future Enhancements

- [ ] Multi-chain support (BSC, Base, Optimism, Solana)
- [ ] QR code for payment address
- [ ] Webhook notifications
- [ ] Admin dashboard with payment stats
- [ ] Partial payment handling
- [ ] Automatic refund processing
- [ ] Email notifications for pending payments

## Related Components

### real8-bridge Plugin
- **Purpose**: Sell REAL8 tokens (customers PAY fiat TO GET REAL8)
- **Location**: `/mnt/data/WebDes/REAL8/real8.org/www/wp-content/plugins/real8-bridge-main/`
- **Shares**: Same pricing API, same Stellar constants

### api.real8.org
- **Location**: `/mnt/data/WebDes/REAL8/api.real8.org/www/`
- **Provides**: Pricing API, bridge operations
- **Server**: `admin@45.136.71.131`

## Support Resources

- **REAL8 Documentation**: See /mnt/data/WebDes/REAL8/CLAUDE/
- **Stellar Docs**: https://developers.stellar.org/
- **Horizon API**: https://developers.stellar.org/api/horizon/
- **WooCommerce Gateway Docs**: https://woocommerce.com/document/payment-gateway-api/

---

**Last Updated**: 2025-12-29
**Current Version**: 1.0.0
**Maintainer**: REAL8 Development Team
