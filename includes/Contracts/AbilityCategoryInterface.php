<?php
/**
 * Contract of an ability category registered by the plugin.
 *
 * @package WpMcpAbilities\Contracts
 */

namespace WpMcpAbilities\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * A category handed to `wp_register_ability_category()`.
 */
interface AbilityCategoryInterface {
    /**
     * Category slug.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Arguments passed to `wp_register_ability_category()`.
     *
     * @return array<string, mixed>
     */
    public function getProperties(): array;
}
