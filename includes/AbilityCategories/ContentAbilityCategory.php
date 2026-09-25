<?php
/**
 * Ability category holding every content management ability of the plugin.
 *
 * @package WpMcpAbilities\AbilityCategories
 */

namespace WpMcpAbilities\AbilityCategories;

use WpMcpAbilities\Contracts\AbilityCategoryInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `wp-mcp-abilities` ability category.
 */
class ContentAbilityCategory implements AbilityCategoryInterface {
    /**
     * Category slug, also used as the ability name namespace.
     */
    public const SLUG = 'wp-mcp-abilities';

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::SLUG;
    }

    /**
     * @inheritDoc
     */
    public function getProperties(): array {
        return [
            'label'       => __( 'WP MCP Abilities', 'wp-mcp-abilities' ),
            'description' => __(
                'Abilities for WordPress content management.',
                'wp-mcp-abilities'
            ),
        ];
    }
}
