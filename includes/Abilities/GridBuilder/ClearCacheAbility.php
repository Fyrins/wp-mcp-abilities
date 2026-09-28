<?php
/**
 * Clears the WP Grid Builder cache.
 *
 * @package WpMcpAbilities\Abilities\GridBuilder
 */

namespace WpMcpAbilities\Abilities\GridBuilder;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Capabilities;
use WP_Grid_Builder\Includes\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Drops the transients WP Grid Builder keeps per grid and facet.
 *
 * The plugin caches the choices of each facet for each grid. When a facet is
 * created or indexed while a stale entry is still there, the facet keeps
 * rendering what was cached, often nothing, until the cache is cleared. This
 * does what the "Clear cache" button of the plugin settings does.
 */
class ClearCacheAbility extends AbstractAbility {
    use GridBuilderAccess;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'clear-wpgb-cache' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'Clear WP Grid Builder cache', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __( 'Clears the WP Grid Builder cache, as the button of its settings screen does. Use it when a facet shows stale or empty choices after an import or an indexing.', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getGroup(): string {
        return __( 'WP Grid Builder', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function isAvailable(): bool {
        return $this->isGridBuilderActive();
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [],
        ];
    }

    /**
     * @inheritDoc
     */
    public function getOutputSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'cleared' => [ 'type' => 'boolean' ],
            ],
        ];
    }

    /**
     * @inheritDoc
     * @param mixed $input Raw input coming from the MCP adapter.
     */
    public function checkPermission( mixed $input = null ): bool {
        return Capabilities::canManageGridBuilder();
    }

    /**
     * @inheritDoc
     * @param mixed $input Raw input coming from the MCP adapter.
     */
    public function execute( mixed $input = null ): array|\WP_Error {
        Helpers::delete_transient();

        return [ 'cleared' => true ];
    }
}
