<?php
/**
 * Removes the third-party abilities an administrator switched off.
 *
 * @package WpMcpAbilities\Hooks
 */

namespace WpMcpAbilities\Hooks;

use WpMcpAbilities\Contracts\HookInterface;
use WpMcpAbilities\Services\SettingsService;
use WpMcpAbilities\Services\ThirdPartyAbilityCatalog;

defined( 'ABSPATH' ) || exit;

/**
 * Unregisters what the settings screen has unchecked, on MCP requests.
 *
 * `mcp-adapter/execute-ability` reads the registry on every call, so filtering
 * the list once, when the MCP server is built, would change nothing. Taking the
 * ability out of the registry is the only lever the Abilities API offers.
 *
 * The removal runs on `rest_pre_dispatch`, and only for the routes the adapter
 * serves. Two reasons, neither cosmetic.
 *
 * Hooking `wp_abilities_api_init` would tie the whole thing to whoever resolves
 * the registry first. That action fires on the first resolution and never again,
 * and `REST_REQUEST` is only defined on `parse_request`: anything resolving the
 * registry during `init` would have the removal answer "not a REST request" once
 * and for all. On a site running the adapter's default server the order happens
 * to work out, since the adapter initialises on `rest_api_init` for REST
 * requests, but it is the adapter's own choice rather than a guarantee, and one
 * plugin calling `wp_get_abilities()` on `init` is enough to turn the switches
 * into decoration without a word. `wp_unregister_ability()` carries no ordering
 * constraint, unlike `wp_register_ability()`, so the work can wait for a moment
 * where the context is known rather than depend on that order.
 *
 * Scoping to the MCP routes rather than to REST as a whole matters just as much.
 * A plugin registering abilities is likely to call them from its own admin
 * screens, over REST: removing them from every REST request would break the
 * plugin the administrator meant to keep, and would go far beyond what the
 * settings screen claims to do.
 *
 * Asking the catalogue for its inventory resolves the registry if nothing else
 * has, so the third-party plugins have registered by the time anything is
 * removed.
 *
 * One limit, measured on the adapter 0.5: its default server freezes the
 * abilities it exposes as MCP resources and prompts when the server is built, on
 * `rest_api_init`, which is earlier than this. A third-party ability declaring
 * `meta.mcp.type` as `resource` or `prompt` therefore stays in that listing once
 * switched off, though running it still fails, the registry being what the
 * adapter reads at call time. Tools are unaffected: the default server exposes
 * the adapter's three generic ones, which read the registry on every call.
 */
class ThirdPartyAbilityAvailability implements HookInterface {
    /**
     * Route prefixes the MCP adapter serves, under the REST namespace `mcp`.
     *
     * @var array<int, string>
     */
    private const MCP_ROUTE_PREFIXES = [ '/mcp/' ];

    /**
     * @param ThirdPartyAbilityCatalog $catalog  Inventory of the third-party abilities.
     * @param SettingsService          $settings Reader of the switches.
     */
    public function __construct(
        private ThirdPartyAbilityCatalog $catalog,
        private SettingsService $settings
    ) {
    }

    /**
     * @inheritDoc
     */
    public function hooks(): void {
        add_filter( 'rest_pre_dispatch', [ $this, 'removeDisabled' ], 10, 3 );
    }

    /**
     * Unregisters every third-party ability switched off, on MCP routes only.
     *
     * The parameters are typed loosely on purpose: this runs on every REST
     * request of every site the plugin is installed on, and a fatal type error
     * on a core filter would take the whole response down. Whatever arrives that
     * is not a request is simply not our business.
     *
     * @param mixed $result  Response to send instead of dispatching, untouched here.
     * @param mixed $server  Server handling the request.
     * @param mixed $request Request about to be dispatched.
     * @return mixed
     */
    public function removeDisabled( mixed $result, mixed $server = null, mixed $request = null ): mixed {
        if ( ! $request instanceof \WP_REST_Request || ! function_exists( 'wp_unregister_ability' ) ) {
            return $result;
        }

        if ( ! $this->isMcpRoute( $request->get_route() ) ) {
            return $result;
        }

        foreach ( array_keys( $this->catalog->all() ) as $name ) {
            // Absent from the option means untouched, which means left alone.
            if ( $this->settings->isEnabled( $name, true ) ) {
                continue;
            }

            wp_unregister_ability( $name );
        }

        return $result;
    }

    /**
     * Whether a route is served by the MCP adapter.
     *
     * @param string $route Route about to be dispatched.
     */
    private function isMcpRoute( string $route ): bool {
        $route = '/' . ltrim( $route, '/' );

        foreach ( $this->routePrefixes() as $prefix ) {
            if ( str_starts_with( $route, $prefix ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Route prefixes treated as MCP traffic.
     *
     * The adapter serves its default server on `/mcp/mcp-adapter-default-server`,
     * under the REST namespace `mcp`. A site declaring its own server elsewhere
     * adds its prefix here rather than losing the switches without a word.
     *
     * @return array<int, string>
     */
    private function routePrefixes(): array {
        /**
         * Filters the route prefixes on which third-party abilities are removed.
         *
         * @param array<int, string> $prefixes Route prefixes, leading and trailing slash included.
         */
        $prefixes = (array) apply_filters( 'wpmcpa_mcp_route_prefixes', self::MCP_ROUTE_PREFIXES );

        $normalised = [];

        foreach ( $prefixes as $prefix ) {
            if ( ! is_string( $prefix ) || '' === trim( $prefix, '/' ) ) {
                continue;
            }

            $normalised[] = '/' . trim( $prefix, '/' ) . '/';
        }

        return $normalised;
    }
}
