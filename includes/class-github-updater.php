<?php
/**
 * Update checker for REAL8 Gateway
 *
 * Hooks into WordPress's native plugin update system to check for
 * new releases via api.real8.org and enable one-click updates.
 *
 * @package REAL8_Gateway
 */

if (!defined('ABSPATH')) {
    exit;
}

class REAL8_GitHub_Updater {

    const SLUG       = 'real8-gateway';
    const CACHE_KEY  = 'real8_gateway_update_data';
    const CACHE_EXPIRY = 43200; // 12 hours
    const UPDATE_URL = 'https://api.real8.org/gateway/update-check';
    const ASSETS_URL = 'https://raw.githubusercontent.com/REAL8-crypto/real8-gateway/main/assets/images/';

    /**
     * Plugin basename (e.g. real8-gateway/real8-gateway.php)
     */
    private $plugin_basename;

    public function __construct() {
        $this->plugin_basename = plugin_basename(REAL8_GATEWAY_PLUGIN_FILE);

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
        add_action('upgrader_process_complete', [$this, 'clear_cache'], 10, 2);

        // Clear our cache when WordPress does a force-check
        if (is_admin() && isset($_GET['force-check'])) {
            delete_transient(self::CACHE_KEY);
        }
    }

    /**
     * Check for a newer release
     */
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $remote = $this->get_remote_data();

        if (!$remote || empty($remote['version'])) {
            return $transient;
        }

        $current_version = $transient->checked[$this->plugin_basename] ?? REAL8_GATEWAY_VERSION;

        if (version_compare($remote['version'], $current_version, '>')) {
            $transient->response[$this->plugin_basename] = (object) [
                'slug'         => self::SLUG,
                'plugin'       => $this->plugin_basename,
                'new_version'  => $remote['version'],
                'url'          => 'https://github.com/REAL8-crypto/real8-gateway',
                'package'      => $remote['package'],
                'tested'       => $remote['tested'] ?? '',
                'requires_php' => $remote['requires_php'] ?? '7.4',
                'requires'     => $remote['requires'] ?? '5.8',
                'icons'        => [
                    '1x' => self::ASSETS_URL . 'icon-128x128.png',
                    '2x' => self::ASSETS_URL . 'icon-256x256.png',
                ],
                'banners'      => [
                    'low'  => self::ASSETS_URL . 'banner-772x250.png',
                    'high' => self::ASSETS_URL . 'banner-1544x500.png',
                ],
            ];
        } else {
            // Tell WP we checked and it's up to date (prevents WP.org lookup)
            $transient->no_update[$this->plugin_basename] = (object) [
                'slug'        => self::SLUG,
                'plugin'      => $this->plugin_basename,
                'new_version' => $current_version,
                'url'         => 'https://github.com/REAL8-crypto/real8-gateway',
            ];
        }

        return $transient;
    }

    /**
     * Provide plugin info for the "View Details" popup
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!isset($args->slug) || $args->slug !== self::SLUG) {
            return $result;
        }

        $remote = $this->get_remote_data();

        if (!$remote || empty($remote['version'])) {
            return $result;
        }

        // Convert markdown changelog to HTML
        $changelog = '';
        if (!empty($remote['changelog'])) {
            $body = esc_html($remote['changelog']);
            $body = preg_replace('/^### (.+)$/m', '<h4>$1</h4>', $body);
            $body = preg_replace('/^## (.+)$/m', '<h3>$1</h3>', $body);
            $body = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $body);
            $body = preg_replace('/^- (.+)$/m', '<li>$1</li>', $body);
            $body = preg_replace('/(<li>.*<\/li>\n?)+/', '<ul>$0</ul>', $body);
            $changelog = nl2br($body);
        }

        return (object) [
            'name'          => 'REAL8 Gateway for WooCommerce',
            'slug'          => self::SLUG,
            'version'       => $remote['version'],
            'author'        => '<a href="https://real8.org">REAL8</a>',
            'homepage'      => 'https://github.com/REAL8-crypto/real8-gateway',
            'requires'      => $remote['requires'] ?? '5.8',
            'tested'        => $remote['tested'] ?? '',
            'requires_php'  => $remote['requires_php'] ?? '7.4',
            'last_updated'  => $remote['updated'] ?? '',
            'download_link' => $remote['package'] ?? '',
            'sections'      => [
                'description' => 'Accept REAL8 token payments on the Stellar blockchain for your WooCommerce store.',
                'changelog'   => $changelog,
            ],
            'icons'         => [
                '1x' => self::ASSETS_URL . 'icon-128x128.png',
                '2x' => self::ASSETS_URL . 'icon-256x256.png',
            ],
            'banners'       => [
                'low'  => self::ASSETS_URL . 'banner-772x250.png',
                'high' => self::ASSETS_URL . 'banner-1544x500.png',
            ],
        ];
    }

    /**
     * Clear cache after plugin upgrades
     */
    public function clear_cache($upgrader, $hook_extra) {
        if (
            isset($hook_extra['action'], $hook_extra['type']) &&
            $hook_extra['action'] === 'update' &&
            $hook_extra['type'] === 'plugin'
        ) {
            delete_transient(self::CACHE_KEY);
        }
    }

    /**
     * Fetch latest release data from api.real8.org (cached 12h)
     *
     * @return array|false
     */
    private function get_remote_data() {
        $cached = get_transient(self::CACHE_KEY);
        if ($cached !== false) {
            return $cached;
        }

        $response = wp_remote_get(self::UPDATE_URL, [
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            error_log('[REAL8 Updater] Request error: ' . $response->get_error_message());
            return false;
        }

        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 200) {
            error_log('[REAL8 Updater] API returned HTTP ' . $http_code);
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($data) || empty($data['version'])) {
            return false;
        }

        set_transient(self::CACHE_KEY, $data, self::CACHE_EXPIRY);

        return $data;
    }
}
