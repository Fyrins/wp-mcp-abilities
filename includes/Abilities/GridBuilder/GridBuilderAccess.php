<?php
/**
 * Shared access to WP Grid Builder facets for the grid builder abilities.
 *
 * @package WpMcpAbilities\Abilities\GridBuilder
 */

namespace WpMcpAbilities\Abilities\GridBuilder;

use WP_Grid_Builder\Includes\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Reads facets and their index through the plugin's own query builder.
 *
 * WP Grid Builder keeps facets and their index in tables of its own
 * (`wpgb_facets`, `wpgb_index`), out of reach of the post, term and meta
 * abilities, and has no public function listing them. `Database` is the
 * builder the plugin queries them with everywhere, `Helpers` included: it
 * resolves the table names and prepares every value, so nothing here writes
 * SQL. Its REST routes, the other way in, are admin-only and nonce-bound.
 */
trait GridBuilderAccess {
    /**
     * Whether WP Grid Builder is active, with the classes these abilities call.
     */
    protected function isGridBuilderActive(): bool {
        return defined( 'WPGB_VERSION' )
            && class_exists( Database::class )
            && class_exists( \WP_Grid_Builder\Includes\Helpers::class )
            && class_exists( \WP_Grid_Builder\Includes\Indexer::class );
    }

    /**
     * Facets as stored, optionally narrowed to a list of IDs.
     *
     * @param int[] $ids Facet IDs; empty for every facet.
     * @return array<int, array<string, mixed>>
     */
    protected function queryFacets( array $ids = [] ): array {
        $rows = Database::query_results(
            [
                'select'  => 'id, slug, name, type, source, settings, modified_date',
                'from'    => 'facets',
                'orderby' => 'id ASC',
                'id'      => $ids,
            ]
        );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Number of rows a facet holds in the index.
     *
     * @param string $slug Facet slug, which is what index rows are keyed by.
     */
    protected function countIndexRows( string $slug ): int {
        return (int) Database::count_items(
            [
                'from' => 'index',
                'slug' => $slug,
            ]
        );
    }

    /**
     * Shapes a facet row for MCP output.
     *
     * @param array<string, mixed> $row Row from the facets table.
     * @return array<string, mixed>
     */
    protected function formatFacet( array $row ): array {
        $settings = json_decode( (string) $row['settings'], true );
        $slug     = (string) $row['slug'];

        return [
            'id'            => (int) $row['id'],
            'slug'          => $slug,
            'name'          => (string) $row['name'],
            'type'          => (string) $row['type'],
            'source'        => (string) $row['source'],
            'index_rows'    => $this->countIndexRows( $slug ),
            'modified_date' => (string) $row['modified_date'],
            'settings'      => is_array( $settings ) ? $settings : [],
        ];
    }

    /**
     * Schema of a formatted facet.
     *
     * @return array<string, mixed>
     */
    protected function facetSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'id'            => [ 'type' => 'integer' ],
                'slug'          => [ 'type' => 'string' ],
                'name'          => [ 'type' => 'string' ],
                'type'          => [ 'type' => 'string' ],
                'source'        => [
                    'type'        => 'string',
                    'description' => __( 'What the facet indexes, e.g. "taxonomy/category" or "post_meta/start_date". Empty for facets that index nothing (search, sort, pagination…).', 'wp-mcp-abilities' ),
                ],
                'index_rows'    => [
                    'type'        => 'integer',
                    'description' => __( 'Rows stored in the index for this facet.', 'wp-mcp-abilities' ),
                ],
                'modified_date' => [ 'type' => 'string' ],
                'settings'      => [ 'type' => 'object' ],
            ],
        ];
    }
}
