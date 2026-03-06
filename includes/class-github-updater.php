<?php
/**
 * GitHub-based update checker for REAL8 Gateway
 *
 * Hooks into WordPress's native plugin update system to check for
 * new releases on GitHub and enable one-click updates.
 *
 * @package REAL8_Gateway
 */

if (!defined('ABSPATH')) {
    exit;
}

class REAL8_GitHub_Updater {

    const SLUG       = 'real8-gateway';
    const REPO       = 'REAL8-crypto/real8-gateway';
    const CACHE_KEY  = 'real8_gateway_update_data';
    const CACHE_EXPIRY = 43200; // 12 hours

    /**
     * Plugin basename (e.g. real8-gateway/real8-gateway.php)
     */
    private $plugin_basename;

    public function __construct() {
        $this->plugin_basename = plugin_basename(REAL8_GATEWAY_PLUGIN_FILE);

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
        add_action('upgrader_process_complete', [$this, 'clear_cache'], 10, 2);
    }

    /**
     * Check GitHub for a newer release
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
                'url'          => 'https://github.com/' . self::REPO,
                'package'      => $remote['package'],
                'tested'       => $remote['tested'] ?? '',
                'requires_php' => $remote['requires_php'] ?? '7.4',
                'requires'     => $remote['requires'] ?? '5.8',
            ];
        } else {
            // Tell WP we checked and it's up to date (prevents WP.org lookup)
            $transient->no_update[$this->plugin_basename] = (object) [
                'slug'        => self::SLUG,
                'plugin'      => $this->plugin_basename,
                'new_version' => $current_version,
                'url'         => 'https://github.com/' . self::REPO,
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

        return (object) [
            'name'          => 'REAL8 Gateway for WooCommerce',
            'slug'          => self::SLUG,
            'version'       => $remote['version'],
            'author'        => '<a href="https://real8.org">REAL8</a>',
            'homepage'      => 'https://github.com/' . self::REPO,
            'requires'      => $remote['requires'] ?? '5.8',
            'tested'        => $remote['tested'] ?? '',
            'requires_php'  => $remote['requires_php'] ?? '7.4',
            'last_updated'  => $remote['updated'] ?? '',
            'download_link' => $remote['package'] ?? '',
            'sections'      => [
                'description' => 'Accept REAL8 token payments on the Stellar blockchain for your WooCommerce store.',
                'changelog'   => $remote['changelog'] ?? '',
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
     * Fetch latest release data from GitHub API (cached 12h)
     *
     * @return array|false
     */
    private function get_remote_data() {
        $cached = get_transient(self::CACHE_KEY);
        if ($cached !== false) {
            return $cached;
        }

        $url = 'https://api.github.com/repos/' . self::REPO . '/releases/latest';

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'REAL8-Gateway/' . REAL8_GATEWAY_VERSION . ' WordPress/' . get_bloginfo('version'),
            ],
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        if (wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }

        $release = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($release) || empty($release['tag_name'])) {
            return false;
        }

        // Strip leading 'v' from tag (e.g. v3.1.0 → 3.1.0)
        $version = ltrim($release['tag_name'], 'vV');

        // Look for a .zip asset in release assets; fall back to source zipball
        $package = $release['zipball_url'] ?? '';
        if (!empty($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (
                    isset($asset['browser_download_url']) &&
                    str_ends_with($asset['name'], '.zip')
                ) {
                    $package = $asset['browser_download_url'];
                    break;
                }
            }
        }

        // Parse changelog from release body (markdown)
        $changelog = '';
        if (!empty($release['body'])) {
            $changelog = '<pre>' . esc_html($release['body']) . '</pre>';
        }

        $data = [
            'version'      => $version,
            'package'      => $package,
            'updated'      => $release['published_at'] ?? '',
            'changelog'    => $changelog,
            'requires'     => '5.8',
            'requires_php' => '7.4',
            'tested'       => '',
        ];

        set_transient(self::CACHE_KEY, $data, self::CACHE_EXPIRY);

        return $data;
    }
}
