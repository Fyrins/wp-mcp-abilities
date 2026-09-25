<?php
/**
 * Contract of an ability registered by the plugin.
 *
 * @package WpMcpAbilities\Contracts
 */

namespace WpMcpAbilities\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * An ability handed to `wp_register_ability()`.
 */
interface AbilityInterface {
    /**
     * Fully qualified ability name, `namespace/name`.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Arguments passed to `wp_register_ability()`.
     *
     * @return array<string, mixed>
     */
    public function getProperties(): array;
}
