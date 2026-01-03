<?php
/**
 * Token Registry for Stellar Payment Gateway
 *
 * Defines all supported Stellar tokens with their asset codes, issuers, and metadata.
 *
 * @package REAL8_Gateway
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Token Registry Class
 */
class REAL8_Token_Registry {

    /**
     * All supported tokens with their configurations
     */
    private static $tokens = array(
        'XLM' => array(
            'code' => 'XLM',
            'name' => 'Stellar Lumens',
            'issuer' => null,
            'decimals' => 7,
            'is_native' => true,
            'asset_type' => 'native',
            'icon' => 'xlm.svg',
            'color' => '#000000',
        ),
        'REAL8' => array(
            'code' => 'REAL8',
            'name' => 'REAL8 Token',
            'issuer' => 'GBVYYQ7XXRZW6ZCNNCL2X2THNPQ6IM4O47HAA25JTAG7Z3CXJCQ3W4CD',
            'decimals' => 7,
            'is_native' => false,
            'asset_type' => 'credit_alphanum12',
            'icon' => 'real8.svg',
            'color' => '#0052FF',
        ),
        'wREAL8' => array(
            'code' => 'wREAL8',
            'name' => 'Wrapped REAL8',
            'issuer' => 'GADYIWMD5P75ZHTVIIF6ADU6GYE5T7WRZIHAU4LPAZ4F5IMPD7NRK7V7',
            'decimals' => 7,
            'is_native' => false,
            'asset_type' => 'credit_alphanum12',
            'icon' => 'wreal8.svg',
            'color' => '#00C2FF',
        ),
        'USDC' => array(
            'code' => 'USDC',
            'name' => 'USD Coin',
            'issuer' => 'GA5ZSEJYB37JRC5AVCIA5MOP4RHTM335X2KGX3IHOJAPP5RE34K4KZVN',
            'decimals' => 7,
            'is_native' => false,
            'asset_type' => 'credit_alphanum4',
            'icon' => 'usdc.svg',
            'color' => '#2775CA',
        ),
        'EURC' => array(
            'code' => 'EURC',
            'name' => 'Euro Coin',
            'issuer' => 'GDHU6WRG4IEQXM5NZ4BMPKOXHW76MZM4Y2IEMFDVXBSDP6SJY4ITNPP2',
            'decimals' => 7,
            'is_native' => false,
            'asset_type' => 'credit_alphanum4',
            'icon' => 'eurc.svg',
            'color' => '#003399',
        ),
        'SLVR' => array(
            'code' => 'SLVR',
            'name' => 'Silver Token',
            'issuer' => 'GBZVELEQD3WBN3R3VAG64HVBDOZ76ZL6QPLSFGKWPFED33Q3234NSLVR',
            'decimals' => 7,
            'is_native' => false,
            'asset_type' => 'credit_alphanum4',
            'icon' => 'slvr.svg',
            'color' => '#C0C0C0',
        ),
        'GOLD' => array(
            'code' => 'GOLD',
            'name' => 'Gold Token',
            'issuer' => 'GBC5ZGK6MQU3XG5Y72SXPA7P5R5NHYT2475SNEJB2U3EQ6J56QLVGOLD',
            'decimals' => 7,
            'is_native' => false,
            'asset_type' => 'credit_alphanum4',
            'icon' => 'gold.svg',
            'color' => '#FFD700',
        ),
    );

    /**
     * Tokens that get pricing from api.real8.org
     */
    private static $api_priced_tokens = array('XLM', 'REAL8', 'USDC');

    /**
     * Tokens that get pricing from Stellar Horizon orderbook
     */
    private static $horizon_priced_tokens = array('EURC', 'SLVR', 'GOLD', 'wREAL8');

    /**
     * Get a single token by code
     *
     * @param string $code Token code (e.g., 'REAL8', 'XLM')
     * @return array|null Token data or null if not found
     */
    public static function get_token($code) {
        $code = strtoupper($code);
        return isset(self::$tokens[$code]) ? self::$tokens[$code] : null;
    }

    /**
     * Get all supported tokens
     *
     * @return array All token configurations
     */
    public static function get_all_tokens() {
        return self::$tokens;
    }

    /**
     * Get all token codes
     *
     * @return array Array of token codes
     */
    public static function get_all_token_codes() {
        return array_keys(self::$tokens);
    }

    /**
     * Get tokens for admin dropdown/multiselect
     *
     * @return array Associative array of code => display name
     */
    public static function get_token_options() {
        $options = array();
        foreach (self::$tokens as $code => $token) {
            $options[$code] = $token['name'] . ' (' . $code . ')';
        }
        return $options;
    }

    /**
     * Get issuer for a token
     *
     * @param string $code Token code
     * @return string|null Issuer address or null for native XLM
     */
    public static function get_issuer($code) {
        $token = self::get_token($code);
        return $token ? $token['issuer'] : null;
    }

    /**
     * Check if token is native (XLM)
     *
     * @param string $code Token code
     * @return bool True if native asset
     */
    public static function is_native($code) {
        $token = self::get_token($code);
        return $token && $token['is_native'];
    }

    /**
     * Get asset type for Stellar API queries
     *
     * @param string $code Token code
     * @return string Asset type (native, credit_alphanum4, credit_alphanum12)
     */
    public static function get_asset_type($code) {
        $token = self::get_token($code);
        return $token ? $token['asset_type'] : null;
    }

    /**
     * Check if a token code and issuer combination is valid
     *
     * @param string $code Asset code
     * @param string|null $issuer Asset issuer
     * @return bool True if valid combination
     */
    public static function validate_token($code, $issuer = null) {
        $token = self::get_token($code);

        if (!$token) {
            return false;
        }

        // Native asset (XLM) should have no issuer
        if ($token['is_native']) {
            return empty($issuer);
        }

        // Non-native assets must match exact issuer
        return $token['issuer'] === $issuer;
    }

    /**
     * Check if token pricing comes from api.real8.org
     *
     * @param string $code Token code
     * @return bool True if priced via API
     */
    public static function is_api_priced($code) {
        return in_array(strtoupper($code), self::$api_priced_tokens);
    }

    /**
     * Check if token pricing comes from Horizon orderbook
     *
     * @param string $code Token code
     * @return bool True if priced via Horizon
     */
    public static function is_horizon_priced($code) {
        return in_array(strtoupper($code), self::$horizon_priced_tokens);
    }

    /**
     * Get tokens that are priced via API
     *
     * @return array Token codes
     */
    public static function get_api_priced_tokens() {
        return self::$api_priced_tokens;
    }

    /**
     * Get tokens that are priced via Horizon
     *
     * @return array Token codes
     */
    public static function get_horizon_priced_tokens() {
        return self::$horizon_priced_tokens;
    }

    /**
     * Get Stellar Horizon API parameters for a token
     *
     * @param string $code Token code
     * @param string $side 'selling' or 'buying'
     * @return array Parameters for Horizon API query
     */
    public static function get_horizon_params($code, $side = 'selling') {
        $token = self::get_token($code);

        if (!$token) {
            return array();
        }

        $prefix = $side . '_asset_';

        if ($token['is_native']) {
            return array(
                $prefix . 'type' => 'native',
            );
        }

        return array(
            $prefix . 'type' => $token['asset_type'],
            $prefix . 'code' => $token['code'],
            $prefix . 'issuer' => $token['issuer'],
        );
    }

    /**
     * Get display name for a token
     *
     * @param string $code Token code
     * @return string Display name
     */
    public static function get_display_name($code) {
        $token = self::get_token($code);
        return $token ? $token['name'] : $code;
    }

    /**
     * Get token color for UI
     *
     * @param string $code Token code
     * @return string Hex color
     */
    public static function get_color($code) {
        $token = self::get_token($code);
        return $token ? $token['color'] : '#666666';
    }

    /**
     * Get fallback prices for when APIs are unavailable
     * These should be updated periodically
     *
     * @return array Token code => USD price
     */
    public static function get_fallback_prices() {
        return array(
            'XLM' => 0.45,
            'REAL8' => 0.0142,
            'wREAL8' => 0.0142,
            'USDC' => 1.00,
            'EURC' => 1.10,
            'SLVR' => 31.00,
            'GOLD' => 2700.00,
        );
    }
}
