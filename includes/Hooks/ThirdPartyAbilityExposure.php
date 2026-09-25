<?php
/**
 * Hands the MCP server the third-party abilities an administrator opened.
 *
 * @package WpMcpAbilities\Hooks
 */

namespace WpMcpAbilities\Hooks;

use WpMcpAbilities\Contracts\HookInterface;
use WpMcpAbilities\Services\SettingsService;
use WpMcpAbilities\Services\ThirdPartyAbilityCatalog;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the checked abilities as tools of the adapter's default server.
 *
 * A plugin may register abilities without ever meaning them for an agent.
 * SEOPress does exactly that: twenty-one of them on a current site, written for
 * the core REST API, none carrying `meta.mcp.public`. The adapter reads a
 * missing flag as a refusal, so they are neither listed nor callable.
 *
 * That flag cannot be added from the outside: `WP_Ability::get_meta()` returns
 * its metadata as stored, with no filter, and re-registering an ability would
 * mean rebuilding callbacks the object does not hand out.
 *
 * It does not have to be. The flag only governs the adapter's three generic
 * abilities, `discover-abilities` filtering on it and `execute-ability`
 * checking it. An ability named in a server's `tools` list becomes an MCP tool
 * without it, `McpComponentRegistry::register_ability_tool()` taking it from the
 * registry as it stands. So the plugin declares what an administrator checked,
 * and touches neither the registry nor anybody's metadata.
 */
class ThirdPartyAbilityExposure implements HookInterface {
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
        add_filter( 'mcp_adapter_default_server_config', [ $this, 'addExposedAbilities' ] );
    }

    /**
     * Adds the checked abilities to the tools the default server exposes.
     *
     * @param mixed $config Configuration of the default server.
     * @return mixed
     */
    public function addExposedAbilities( mixed $config ): mixed {
        if ( ! is_array( $config ) ) {
            return $config;
        }

        $tools = isset( $config['tools'] ) && is_array( $config['tools'] ) ? $config['tools'] : [];

        foreach ( $this->exposed() as $name ) {
            if ( ! in_array( $name, $tools, true ) ) {
                $tools[] = $name;
            }
        }

        $config['tools'] = $tools;

        return $config;
    }

    /**
     * Names of the abilities to hand over.
     *
     * @return array<int, string>
     */
    private function exposed(): array {
        $names = [];

        foreach ( $this->catalog->all() as $name => $ability ) {
            /*
             * An ability the plugin already reaches needs nothing from here, and
             * one the switches turned off must stay out: the removal wins over
             * the exposure, otherwise the two settings would contradict each
             * other on the same name.
             */
            if ( $this->catalog->isExposedToMcp( $ability ) || ! $this->settings->isEnabled( $name, true ) ) {
                continue;
            }

            if ( ! $this->settings->isExposed( $name ) ) {
                continue;
            }

            $names[] = $name;
        }

        return $names;
    }
}
