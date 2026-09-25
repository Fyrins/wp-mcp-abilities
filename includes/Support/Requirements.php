<?php
/**
 * Runtime requirements of the plugin.
 *
 * @package WpMcpAbilities\Support
 */

namespace WpMcpAbilities\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Tells whether the two pieces the plugin builds upon are present.
 *
 * The Abilities API landed in WordPress 6.9. Below that version it is only
 * available through the feature plugin, so availability is decided on the
 * function actually being there rather than on a version comparison: a 6.8
 * running the feature plugin is perfectly supported, and a version check alone
 * would warn about it for nothing.
 *
 * MCP itself is not part of WordPress at all, in 6.9 nor in 7.0. Exposing the
 * abilities over MCP still requires the MCP Adapter plugin.
 */
class Requirements {
    /**
     * First WordPress version shipping the Abilities API in core.
     */
    public const ABILITIES_API_IN_CORE = '6.9';

    /**
     * Where the MCP Adapter plugin can be downloaded.
     */
    public const MCP_ADAPTER_URL = 'https://github.com/WordPress/mcp-adapter/releases';

    /**
     * Whether the Abilities API is callable, from core or from the feature plugin.
     *
     * @return bool
     */
    public static function hasAbilitiesApi(): bool {
        return function_exists( 'wp_register_ability' );
    }

    /**
     * Whether the MCP Adapter is running.
     *
     * Deliberately not based on its classes existing: the adapter is also a
     * Composer package, and a Bedrock project pulling it as a transitive
     * dependency, as WP Rocket does through wp-media/mcp-oauth, autoloads its
     * classes whether or not the plugin itself is active.
     *
     * Two ways of running are accepted. The constant covers the plugin being
     * activated the usual way. The action covers the adapter being booted by
     * somebody else calling `McpAdapter::instance()`, which mcp-oauth does as
     * soon as the class is reachable: MCP would then be perfectly functional
     * with the plugin left inactive.
     *
     * Checked late, on an admin hook, so both signals have had a chance to
     * fire: the adapter loads after this plugin in alphabetical order, and the
     * action is fired on `init`.
     *
     * @return bool
     */
    public static function hasMcpAdapter(): bool {
        return defined( 'WP_MCP_VERSION' ) || did_action( 'mcp_adapter_init' ) > 0;
    }

    /**
     * Describes what is missing, ready to be printed.
     *
     * `command` is only set when there is a single command that fixes it: the
     * adapter is not on WordPress.org, so it can only be suggested once its
     * folder is already in `wp-content/plugins/`.
     *
     * @return list<array{title: string, detail: string, severity: 'error'|'warning', command?: string, url?: string}>
     */
    public static function missing(): array {
        $missing = [];

        if ( ! self::hasAbilitiesApi() ) {
            $missing[] = [
                'title'    => __( 'The Abilities API is missing.', 'wp-mcp-abilities' ),
                'detail'   => sprintf(
                    /* translators: 1: current WordPress version, 2: first version shipping the Abilities API. */
                    __(
                        'This site runs WordPress %1$s, and the Abilities API only ships with core from %2$s onwards. Updating WordPress is the way out: the feature plugin that used to provide it is now abandoned.',
                        'wp-mcp-abilities'
                    ),
                    get_bloginfo( 'version' ),
                    self::ABILITIES_API_IN_CORE
                ),
                'command'  => 'wp core update',
                'severity' => 'error',
            ];
        }

        if ( ! self::hasMcpAdapter() ) {
            $adapter = [
                'title'    => __( 'The MCP Adapter plugin is not running.', 'wp-mcp-abilities' ),
                'detail'   => __(
                    'The abilities are registered and available through the Abilities API, but no MCP client can reach them until the MCP Adapter is installed and active.',
                    'wp-mcp-abilities'
                ),
                'url'      => self::MCP_ADAPTER_URL,
                'severity' => 'warning',
            ];

            if ( is_dir( WP_PLUGIN_DIR . '/mcp-adapter' ) ) {
                $adapter['command'] = 'wp plugin activate mcp-adapter';
            }

            $missing[] = $adapter;
        }

        return $missing;
    }
}
