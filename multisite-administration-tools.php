<?php
/**
 * Plugin Name: MultiSite Administration Tools
 * Plugin URI:  https://wordpress.org/plugins/multisite-administration-tools/
 * Description: Adds information to the network admin sites, plugins and themes pages. Allows you to easily see what theme and plugins are enabled on a site.
 * Version:     1.20
 * Author:      Aaron Axelsen
 * Author URI:  http://aaron.axelsen.us
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Network:     true
 * Requires at least: 5.8
 * Tested up to: 6.9
 * Requires PHP: 7.2
 * Text Domain: multisite-administration-tools
 */

defined('ABSPATH') || exit;

if (!is_multisite()) {
        // This plugin only makes sense on multisite.
        return;
}

/**
 * Ensure admin helper functions are available when rendering plugin/theme info.
 */
function msadmintools_require_admin_includes(): void {
        if (!function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!function_exists('wp_get_theme')) {
                require_once ABSPATH . 'wp-admin/includes/theme.php';
        }
}

/**
 * Only run on network admin screens.
 */
function msadmintools_is_network_admin(): bool {
        return is_admin() && is_network_admin();
}

/**
 * Cached site IDs (per request).
 */
function msadmintools_get_site_ids(): array {
        static $site_ids = null;

        $batch_size = 200;

        if ($site_ids !== null) {
                return $site_ids;
        }

        $site_ids = [];

        $args = [
                'fields' => 'ids',
                'number' => $batch_size,
                'offset' => 0,
        ];

        while (true) {
                $batch = get_sites($args);

                if (!is_array($batch) || empty($batch)) {
                        break;
                }

                $site_ids = array_merge($site_ids, $batch);

                if (count($batch) < $batch_size) {
                        break;
                }

                $args['offset'] += $batch_size;
        }

        return $site_ids;
}

/**
 * Switch to a blog and ensure we always restore the previous context.
 */
function msadmintools_with_blog(int $blog_id, callable $callback)
{
        $switched = switch_to_blog($blog_id);

        try {
                return $callback();
        } finally {
                if ($switched) {
                        restore_current_blog();
                }
        }
}

/**
 * Cached site link HTML (per request) so we don’t keep querying blogname/siteurl repeatedly.
 */
function msadmintools_get_site_link_html(int $blog_id): string {
        static $cache = [];

        if (isset($cache[$blog_id])) {
                return $cache[$blog_id];
        }

        $site_url  = get_site_url($blog_id, '/');
        $blog_name = (string) get_blog_option($blog_id, 'blogname', '');

        $label = $blog_name !== '' ? $blog_name : $site_url;

        $cache[$blog_id] =
                '<a href="' . esc_url($site_url) . '" target="_blank" rel="noopener noreferrer">' .
                esc_html($label) .
                '</a>';

        return $cache[$blog_id];
}

/**
 * Build indexes once per request so the Plugins/Themes screens don’t do O(plugins * sites) queries.
 */
function msadmintools_get_indexes(): array {
        static $built = false;
        static $theme_to_sites = [];
        static $plugin_to_sites = [];

        if ($built) {
                return [$theme_to_sites, $plugin_to_sites];
        }

        $built = true;

        $site_ids = msadmintools_get_site_ids();
        foreach ($site_ids as $blog_id) {
                $blog_id = (int) $blog_id;

                // Theme index (by stylesheet).
                $stylesheet = (string) get_blog_option($blog_id, 'stylesheet', '');
                if ($stylesheet !== '') {
                        if (!isset($theme_to_sites[$stylesheet])) {
                                $theme_to_sites[$stylesheet] = [];
                        }
                        $theme_to_sites[$stylesheet][] = $blog_id;
                }

                // Plugin index (by plugin file path, e.g. hello-dolly/hello.php).
                $active_plugins = (array) get_blog_option($blog_id, 'active_plugins', []);
                foreach ($active_plugins as $plugin_file) {
                        $plugin_file = (string) $plugin_file;
                        if ($plugin_file === '') {
                                continue;
                        }
                        if (!isset($plugin_to_sites[$plugin_file])) {
                                $plugin_to_sites[$plugin_file] = [];
                        }
                        $plugin_to_sites[$plugin_file][] = $blog_id;
                }
        }

        return [$theme_to_sites, $plugin_to_sites];
}

/**
 * =========================
 * Sites screen: add columns
 * =========================
 */
function msadmintools_sites_add_columns(array $columns): array {
        if (!msadmintools_is_network_admin()) {
                return $columns;
        }

        $columns['msadmintools_viewthemes']  = esc_html__('Current Theme', 'multisite-administration-tools');
        $columns['msadmintools_viewplugins'] = esc_html__('Current Plugins', 'multisite-administration-tools');

        return $columns;
}
add_filter('manage_sites-network_columns', 'msadmintools_sites_add_columns');

/**
 * Sites screen: render column values.
 */
function msadmintools_sites_render_column(string $column_name, int $blog_id): void {
        if (!msadmintools_is_network_admin()) {
                return;
        }

        msadmintools_require_admin_includes();

        if ($column_name === 'msadmintools_viewthemes') {
                $theme_details = msadmintools_with_blog($blog_id, static function () {
                        $theme = wp_get_theme();

                        return [
                                'name' => $theme ? $theme->get('Name') : '',
                                'stylesheet' => $theme ? $theme->get_stylesheet() : '',
                                'template' => $theme ? $theme->get_template() : '',
                        ];
                });

                if (!is_array($theme_details)) {
                        return;
                }

                $name       = (string) ($theme_details['name'] ?? '');
                $stylesheet = (string) ($theme_details['stylesheet'] ?? '');
                $template   = (string) ($theme_details['template'] ?? '');

                if ($name !== '') {
                        echo '<div><strong>' . esc_html__('Name:', 'multisite-administration-tools') . '</strong> ' . esc_html($name) . '</div>';
                }
                if ($template !== '') {
                        echo '<div><strong>' . esc_html__('Template:', 'multisite-administration-tools') . '</strong> ' . esc_html($template) . '</div>';
                }
                if ($stylesheet !== '') {
                        echo '<div><strong>' . esc_html__('Stylesheet:', 'multisite-administration-tools') . '</strong> ' . esc_html($stylesheet) . '</div>';
                }

                return;
        }

        if ($column_name === 'msadmintools_viewplugins') {
                $active_plugins = (array) get_blog_option($blog_id, 'active_plugins', []);

                // Load all plugin headers once.
                $all_plugins = get_plugins();

                if (empty($active_plugins)) {
                        echo '<em>' . esc_html__('None', 'multisite-administration-tools') . '</em>';
                        return;
                }

                // Use <details> so big lists don’t destroy the Sites table row height.
                echo '<details>';
                echo '<summary>' . esc_html(sprintf(_n('%d plugin', '%d plugins', count($active_plugins), 'multisite-administration-tools'), count($active_plugins))) . '</summary>';
                echo '<div style="margin-top:6px;">';

                foreach ($active_plugins as $plugin_file) {
                        $plugin_file = (string) $plugin_file;

                        if (isset($all_plugins[$plugin_file])) {
                                $plugin_name = (string) ($all_plugins[$plugin_file]['Name'] ?? $plugin_file);
                                echo esc_html($plugin_name) . '<br>';
                                continue;
                        }

                        // Plugin missing/removed.
                        echo '<span style="color:#b32d2e;">' . esc_html($plugin_file . ' (removed)') . '</span><br>';
                }

                echo '</div>';
                echo '</details>';

                return;
        }
}
add_action('manage_sites_custom_column', 'msadmintools_sites_render_column', 10, 2);

/**
 * =========================
 * Themes screen: add column
 * =========================
 */
function msadmintools_themes_add_column(array $columns): array {
        if (!msadmintools_is_network_admin()) {
                return $columns;
        }

        $columns['msadmintools_viewsites'] = esc_html__('Sites', 'multisite-administration-tools');
        return $columns;
}
add_filter('manage_themes-network_columns', 'msadmintools_themes_add_column');

/**
 * Themes screen: render column value.
 * Signature is: ($column_name, $stylesheet, $theme)
 */
function msadmintools_themes_render_column(string $column_name, string $stylesheet, $theme): void {
        if (!msadmintools_is_network_admin()) {
                return;
        }

        if ($column_name !== 'msadmintools_viewsites') {
                return;
        }

        [$theme_to_sites, ] = msadmintools_get_indexes();

        $sites = $theme_to_sites[$stylesheet] ?? [];
        if (empty($sites)) {
                echo '<em>' . esc_html__('None', 'multisite-administration-tools') . '</em>';
                return;
        }

        foreach ($sites as $blog_id) {
                echo msadmintools_get_site_link_html((int) $blog_id) . '<br>';
        }
}
add_action('manage_themes_custom_column', 'msadmintools_themes_render_column', 10, 3);

/**
 * ==========================
 * Plugins screen: add column
 * ==========================
 */
function msadmintools_plugins_add_column(array $columns): array {
        if (!msadmintools_is_network_admin()) {
                return $columns;
        }

        $columns['msadmintools_viewsites'] = esc_html__('Sites', 'multisite-administration-tools');
        return $columns;
}
add_filter('manage_plugins-network_columns', 'msadmintools_plugins_add_column');

/**
 * Plugins screen: render column value.
 * Signature is: ($column_name, $plugin_file, $plugin_data)
 */
function msadmintools_plugins_render_column(string $column_name, string $plugin_file, array $plugin_data): void {
        if (!msadmintools_is_network_admin()) {
                return;
        }

        if ($column_name !== 'msadmintools_viewsites') {
                return;
        }

        [, $plugin_to_sites] = msadmintools_get_indexes();

        $sites = $plugin_to_sites[$plugin_file] ?? [];
        if (empty($sites)) {
                echo '<em>' . esc_html__('None', 'multisite-administration-tools') . '</em>';
                return;
        }

        foreach ($sites as $blog_id) {
                echo msadmintools_get_site_link_html((int) $blog_id) . '<br>';
        }
}
add_action('manage_plugins_custom_column', 'msadmintools_plugins_render_column', 10, 3);
