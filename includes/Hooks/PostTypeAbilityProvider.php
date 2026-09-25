<?php
/**
 * Registers the dynamic CRUD abilities of every exposed post type.
 *
 * @package WpMcpAbilities\Hooks
 */

namespace WpMcpAbilities\Hooks;

use WpMcpAbilities\Contracts\HookInterface;
use WpMcpAbilities\Services\AbilityCatalogService;
use WpMcpAbilities\Services\SettingsService;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the post type abilities into the core Abilities API.
 *
 * They are registered here directly rather than through the registry: they
 * are built on the fly from the post types, whereas the registry and its
 * `wpmcpa_abilities` filter only carry the static abilities.
 */
class PostTypeAbilityProvider implements HookInterface {
    /**
     * @param AbilityCatalogService $catalog  Builder of the post type abilities.
     * @param SettingsService       $settings Reader of the ability switches.
     */
    public function __construct(
        private AbilityCatalogService $catalog,
        private SettingsService $settings
    ) {
    }

    /**
     * @inheritDoc
     */
    public function hooks(): void {
        add_action( 'wp_abilities_api_init', [ $this, 'registerPostTypeAbilities' ] );
    }

    /**
     * Registers every enabled post type ability.
     *
     * @return void
     */
    public function registerPostTypeAbilities(): void {
        foreach ( $this->catalog->postTypeAbilities() as $name => $ability ) {
            if ( ! $this->settings->isEnabled( $name, $ability->isEnabledByDefault() ) ) {
                continue;
            }

            // Another plugin may legitimately own that name; never override it.
            if ( wp_has_ability( $name ) ) {
                continue;
            }

            wp_register_ability( $name, $ability->getProperties() );
        }
    }
}
