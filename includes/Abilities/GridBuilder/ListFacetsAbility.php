<?php
/**
 * Lists the WP Grid Builder facets with the size of their index.
 *
 * @package WpMcpAbilities\Abilities\GridBuilder
 */

namespace WpMcpAbilities\Abilities\GridBuilder;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Capabilities;
use WpMcpAbilities\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only listing of the facets, their settings and index row counts.
 *
 * Facet IDs are what block markup references (`wp-grid-builder/facet` carries
 * an `id`), and an import can renumber them; the row count tells an empty
 * index from a facet that has nothing to offer on a given page.
 */
class ListFacetsAbility extends AbstractAbility {
    use GridBuilderAccess;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'list-wpgb-facets' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'List WP Grid Builder facets', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __( 'Lists the WP Grid Builder facets with their ID, slug, type, source, settings and number of index rows.', 'wp-mcp-abilities' );
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
            'properties' => [
                'ids' => [
                    'type'        => 'array',
                    'items'       => [ 'type' => 'integer' ],
                    'description' => __( 'Facet IDs to return. Omit to list every facet.', 'wp-mcp-abilities' ),
                ],
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function getOutputSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'facets' => [
                    'type'  => 'array',
                    'items' => $this->facetSchema(),
                ],
                'total'  => [ 'type' => 'integer' ],
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
        $input  = Input::normalize( $input );
        $ids    = array_values( array_filter( array_map( 'absint', Input::list( $input, 'ids' ) ) ) );
        $facets = array_map(
            fn ( array $row ): array => $this->formatFacet( $row ),
            $this->queryFacets( $ids )
        );

        return [
            'facets' => $facets,
            'total'  => count( $facets ),
        ];
    }
}
