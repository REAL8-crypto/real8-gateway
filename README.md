# REAL8 Gateway for WooCommerce

![WordPress Plugin](https://img.shields.io/badge/WordPress-Plugin-blue.svg)
![Version](https://img.shields.io/badge/version-3.0.9-green.svg)
![License](https://img.shields.io/badge/license-GPL--2.0%2B-blue.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)
![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-0073aa.svg)
![WooCommerce](https://img.shields.io/badge/WooCommerce-5.0%2B-96588a.svg)
![Stellar](https://img.shields.io/badge/Stellar-Blockchain-brightgreen.svg)

Accept REAL8 token payments on the Stellar blockchain for your WooCommerce store.

## Description

REAL8 Gateway enables WooCommerce merchants to accept REAL8 tokens as payment. Customers can pay for their orders using REAL8 on the Stellar network, with automatic payment detection and order confirmation.

### Features

- **Easy Setup**: Configure your Stellar address and start accepting payments
- **Real-time Pricing**: Fetches current REAL8/USD price from api.real8.org
- **Wallet Validation**: Admin panel shows wallet status (exists, funded, trustline)
- **Automatic Detection**: Monitors the Stellar blockchain for incoming payments
- **Unique Memos**: Each order gets a unique memo for accurate payment matching
- **Payment Timeout**: Configurable payment window (default: 30 minutes)
- **Price Buffer**: Adjustable buffer for price volatility protection
- **Copy-to-Clipboard**: Easy copying of payment address, amount, and memo
- **Live Countdown**: Shows remaining time for payment completion
- **Email Instructions**: Payment details included in order confirmation emails

## Requirements

- WordPress 5.8+
- WooCommerce 5.0+
- PHP 7.4+
- A Stellar wallet with REAL8 trustline

## Installation

1. Upload the `real8-gateway` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce > Settings > Payments > REAL8 Payment
4. Configure your merchant Stellar address
5. Enable the payment method

## Merchant Wallet Setup

Each merchant needs their own Stellar wallet to receive payments:

1. **Create a Stellar wallet** using [Lobstr](https://lobstr.co/), [Solar](https://solarwallet.io/), or any Stellar wallet
2. **Fund with XLM** - at least 1.5 XLM for account reserve
3. **Add REAL8 trustline**:
   - Asset Code: `REAL8`
   - Issuer: `GBVYYQ7XXRZW6ZCNNCL2X2THNPQ6IM4O47HAA25JTAG7Z3CXJCQ3W4CD`
4. **Enter your public key** (starts with G) in the plugin settings

The plugin will validate your wallet and show warnings if:
- The account doesn't exist
- The account is not funded
- The REAL8 trustline is missing

## Configuration

### Required Settings

| Setting | Description |
|---------|-------------|
| Merchant Stellar Address | Your Stellar public key (G...) where REAL8 payments will be received. |

### Optional Settings

| Setting | Default | Description |
|---------|---------|-------------|
| Title | Pay with REAL8 | Payment method name shown to customers |
| Description | Pay with REAL8 tokens... | Description shown during checkout |
| Payment Timeout | 30 minutes | How long customers have to complete payment |
| Price Buffer | 2% | Buffer to account for price volatility |

## How It Works

1. Customer selects "Pay with REAL8" at checkout
2. Plugin calculates required REAL8 amount based on current price
3. A unique payment memo is generated for the order
4. Customer sees payment instructions with address, amount, and memo
5. Plugin monitors the Stellar blockchain for incoming payments
6. When payment is detected and verified, order is marked as paid
7. If payment window expires, order is marked as failed

## Payment Flow

```
Customer Checkout → Calculate REAL8 Amount → Generate Memo
                              ↓
        Show Payment Instructions (Address + Amount + Memo)
                              ↓
              Customer sends REAL8 via their wallet
                              ↓
            Plugin detects payment on Stellar (cron)
                              ↓
        Verify memo matches + amount is sufficient
                              ↓
              Mark order as paid → Send confirmation
```

## REAL8 Token Details

- **Asset Code**: REAL8
- **Issuer**: `GBVYYQ7XXRZW6ZCNNCL2X2THNPQ6IM4O47HAA25JTAG7Z3CXJCQ3W4CD`
- **Network**: Stellar Mainnet
- **Decimals**: 7

## Pricing

The plugin fetches real-time REAL8 prices from `https://api.real8.org/prices`.

- Price is cached for 60 seconds to reduce API calls
- Falls back to last known good price if API is unavailable
- Price buffer (default 2%) protects against volatility

## Security Considerations

1. **Never share your secret key** - The plugin only needs your public address
2. **Use a dedicated wallet** - Consider using a separate wallet for merchant payments
3. **Monitor payments** - Regularly check your wallet for incoming transactions
4. **Backup your keys** - Ensure you have secure backups of your wallet

## Troubleshooting

### Payment not detected

1. Verify the customer included the correct memo
2. Check that the payment amount matches
3. Ensure your Stellar address has REAL8 trustline
4. Check WordPress cron is running (`wp cron event list`)

### Price shows $0 or error

1. Check api.real8.org is accessible
2. Clear the price cache via admin
3. Check WordPress debug log for errors

### Gateway not appearing at checkout

1. Verify merchant address is configured
2. Ensure the gateway is enabled
3. Check that your Stellar address has REAL8 trustline

### Admin shows "Wallet not found"

1. Ensure the Stellar account is funded with at least 1 XLM
2. Wait a few seconds and refresh - new accounts take time to propagate
3. Check the address format (56 characters, starts with G)

## Support

- Website: https://real8.org
- Documentation: https://api.real8.org
- Issues: https://github.com/REAL8-crypto/real8-gateway/issues

## License

GPL v2 or later

## Credits

Developed by [REAL8](https://real8.org)
