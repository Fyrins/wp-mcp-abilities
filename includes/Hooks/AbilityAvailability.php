<?php
/**
 * Keeps disabled and unavailable abilities out of the registry.
 *
 * @package WpMcpAbilities\Hooks
 */

namespace WpMcpAbilities\Hooks;

use WpMcpAbilities\Contracts\HookInterface;
use WpMcpAbilities\Services\SettingsService;
use WpMcpAbilities\Support\AbstractAbility;

defined( 'ABSPATH' ) || exit;

/**
 * Filters the ability list the registry is about to register.
 *
 * Runs late so abilities contributed by other providers are filtered too.
 */
class AbilityAvailability implements HookInterface {
    /**
     * @param SettingsService $settings Reader of the ability switches.
     */
    public function __construct( private SettingsService $settings ) {
    }

    /**
     * @inheritDoc
     */
    public function hooks(): void {
        add_filter( 'wpmcpa_abilities', [ $this, 'rejectUnavailable' ], 20 );
    }

    /**
     * Drops abilities switched off in the settings or missing a dependency.
     *
     * @param array<string, mixed> $abilities Abilities keyed by name.
     * @return array<string, mixed>
     */
    public function rejectUnavailable( array $abilities ): array {
        return array_filter( $abilities, [ $this, 'isExposed' ] );
    }

    /**
     * Whether a given entry should stay in the registry.
     *
     * Entries that are not ours are left untouched: other callbacks on the
     * filter may add abilities of their own.
     *
     * @param mixed $ability Registry entry.
     * @return bool
     */
    private function isExposed( mixed $ability ): bool {
        if ( ! $ability instanceof AbstractAbility ) {
            return true;
        }

        if ( ! $ability->isAvailable() ) {
            return false;
        }

        return $this->settings->isEnabled( $ability->getName(), $ability->isEnabledByDefault() );
    }
}
