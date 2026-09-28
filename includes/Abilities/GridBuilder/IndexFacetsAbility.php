<?php
/**
 * Queues the WP Grid Builder index of one or more facets for rebuilding.
 *
 * @package WpMcpAbilities\Abilities\GridBuilder
 */

namespace WpMcpAbilities\Abilities\GridBuilder;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Capabilities;
use WpMcpAbilities\Support\Input;
use WP_Grid_Builder\Includes\Helpers;
use WP_Grid_Builder\Includes\Indexer;

defined( 'ABSPATH' ) || exit;

/**
 * Hands facets to the plugin's own indexing queue, as its "Index" button does.
 *
 * Indexing is not run within the request. The plugin's indexer only guards
 * time, memory and cancellation when it runs from its queue, and it empties a
 * facet's index before rebuilding it: run inline, a timeout on a large site
 * would leave the facet with no index at all. The queue works in batches in
 * the background, so the ability returns as soon as the facets are queued, and
 * `list-wpgb-facets` tells when their rows are back.
 */
class IndexFacetsAbility extends AbstractAbility {
    use GridBuilderAccess;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'index-wpgb-facets' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'Index WP Grid Builder facets', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __( 'Queues the given WP Grid Builder facets for indexing, as the "Index" button of the plugin does. Indexing runs in the background: call list-wpgb-facets to follow the index row counts, then clear-wpgb-cache once they are back so that facets stop serving cached choices.', 'wp-mcp-abilities' );
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
     * Rebuilding an index empties it first and loads the site while it runs:
     * an agent gets it only once someone turns it on.
     */
    public function isEnabledByDefault(): bool {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'ids' => [
                    'type'        => 'array',
                    'items'       => [ 'type' => 'integer' ],
                    'minItems'    => 1,
                    'description' => __( 'IDs of the facets to index.', 'wp-mcp-abilities' ),
                ],
            ],
            'required'   => [ 'ids' ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function getOutputSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'queued'  => [
                    'type'        => 'array',
                    'items'       => [ 'type' => 'integer' ],
                    'description' => __( 'IDs of the facets handed to the indexing queue.', 'wp-mcp-abilities' ),
                ],
                'skipped' => [
                    'type'        => 'array',
                    'items'       => [ 'type' => 'integer' ],
                    'description' => __( 'Requested IDs that match no facet, or a facet that indexes nothing (search, selection, sort…).', 'wp-mcp-abilities' ),
                ],
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
        $input = Input::normalize( $input );
        $ids   = array_values( array_unique( array_filter( array_map( 'absint', Input::list( $input, 'ids' ) ) ) ) );

        if ( [] === $ids ) {
            return new \WP_Error(
                'missing_facet_ids',
                __( 'Pass the IDs of the facets to index.', 'wp-mcp-abilities' ),
                [ 'status' => 400 ]
            );
        }

        // The facets the indexer will take, read the way it reads them.
        $queued = array_map(
            static fn ( array $facet ): int => (int) $facet['id'],
            Helpers::get_indexable_facets( $ids )
        );

        if ( [] !== $queued ) {
            ( new Indexer() )->index_facets( $queued );
        }

        return [
            'queued'  => array_values( $queued ),
            'skipped' => array_values( array_diff( $ids, $queued ) ),
        ];
    }
}
