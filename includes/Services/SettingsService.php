<?php
/**
 * Reads and writes the per-ability on/off switches.
 *
 * @package WpMcpAbilities\Services
 */

namespace WpMcpAbilities\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the option backing the settings screen.
 */
class SettingsService {
    /**
     * Option holding the enabled state of every ability, keyed by ability name.
     *
     * @var string
     */
    public const OPTION_KEY = 'wpmcpa_enabled';

    /**
     * Option holding which third-party abilities are exposed to MCP.
     *
     * Kept apart from the switches on purpose. The two answer different
     * questions and carry opposite defaults: an ability absent from the switches
     * is on, an ability absent from this one is closed. Holding both in the same
     * array would make one of them unreadable.
     *
     * @var string
     */
    public const EXPOSED_OPTION_KEY = 'wpmcpa_exposed';

    /**
     * Slug of the settings page, also used as the settings group.
     *
     * Carried by the service rather than by one of the hooks: the page and the
     * fields are declared by two different hooks, and neither should have to
     * know about the other.
     *
     * @var string
     */
    public const PAGE_SLUG = 'wp-mcp-abilities';

    /**
     * Capability required to read and write these settings.
     *
     * @var string
     */
    public const CAPABILITY = 'manage_options';

    /**
     * Whether the given ability is enabled.
     *
     * An ability absent from the option falls back to its own default, so a
     * newly shipped ability does not silently stay off after an update.
     *
     * @param string $ability          Ability name.
     * @param bool   $enabledByDefault Default used when the option has no entry.
     * @return bool
     */
    public function isEnabled( string $ability, bool $enabledByDefault = true ): bool {
        $settings = $this->all();

        $enabled = array_key_exists( $ability, $settings )
            ? (bool) $settings[ $ability ]
            : $enabledByDefault;

        /**
         * Filters whether an ability is exposed to MCP clients.
         *
         * @param bool   $enabled Whether the ability is enabled.
         * @param string $ability Ability name.
         */
        return (bool) apply_filters( 'wpmcpa_is_enabled', $enabled, $ability );
    }

    /**
     * Whether a third-party ability is exposed to MCP by this plugin.
     *
     * Closed unless said otherwise: checking a box opens what another plugin
     * left shut, so an update of this plugin must never widen on its own what an
     * agent can reach.
     *
     * @param string $ability Ability name.
     * @return bool
     */
    public function isExposed( string $ability ): bool {
        $exposed = $this->allExposed();

        /**
         * Filters whether a third-party ability is exposed to MCP.
         *
         * @param bool   $exposed Whether the ability is exposed.
         * @param string $ability Ability name.
         */
        return (bool) apply_filters(
            'wpmcpa_is_exposed',
            ! empty( $exposed[ $ability ] ),
            $ability
        );
    }

    /**
     * Returns the raw exposure option.
     *
     * @return array<string, bool>
     */
    public function allExposed(): array {
        $exposed = get_option( self::EXPOSED_OPTION_KEY, [] );

        return is_array( $exposed ) ? $exposed : [];
    }

    /**
     * Returns the raw option.
     *
     * @return array<string, bool>
     */
    public function all(): array {
        $settings = get_option( self::OPTION_KEY, [] );

        return is_array( $settings ) ? $settings : [];
    }
}
