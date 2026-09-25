<?php
/**
 * Shared shaping and validation for taxonomy abilities.
 *
 * @package WpMcpAbilities\Abilities\Taxonomy
 */

namespace WpMcpAbilities\Abilities\Taxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Formats terms and guards the taxonomies abilities are allowed to touch.
 */
trait TaxonomyFormatting {
    /**
     * Taxonomies exposed to MCP clients.
     *
     * Selected on `show_in_rest`, the same rule the post type abilities apply
     * to decide which taxonomies a caller may assign terms in. The two used to
     * disagree — terms were listed for `public` taxonomies, assigned for
     * `show_in_rest` ones — which left a taxonomy like a private-but-REST event
     * venue assignable while its terms stayed invisible: a client could attach
     * term 12 to an event without any way to learn that term 12 existed or what
     * it was called.
     *
     * `show_in_rest` is also how core itself states that a taxonomy is meant to
     * be driven by a client. Nothing is excluded beyond that: a site narrows the
     * list through the filter below, and the settings screen switches any single
     * ability off, `delete-term` being off to begin with.
     *
     * @return array<string, \WP_Taxonomy>
     */
    protected function getTaxonomies(): array {
        $taxonomies = get_taxonomies( [ 'show_in_rest' => true ], 'objects' );

        /**
         * Filters the taxonomies exposed through abilities.
         *
         * @param array<string, \WP_Taxonomy> $taxonomies Taxonomies open to MCP, keyed by slug.
         */
        return (array) apply_filters( 'wpmcpa_taxonomies', $taxonomies );
    }

    /**
     * Makes sure the requested taxonomy exists and is exposed.
     *
     * @param string $taxonomy Taxonomy slug.
     * @return true|\WP_Error
     */
    protected function validateTaxonomy( string $taxonomy ): bool|\WP_Error {
        if ( '' === $taxonomy ) {
            return new \WP_Error(
                'missing_taxonomy',
                __( 'The "taxonomy" parameter is required.', 'wp-mcp-abilities' ),
                [ 'status' => 400 ]
            );
        }

        if ( ! array_key_exists( $taxonomy, $this->getTaxonomies() ) ) {
            return new \WP_Error(
                'taxonomy_not_allowed',
                __( 'This taxonomy is not managed by MCP abilities.', 'wp-mcp-abilities' ),
                [ 'status' => 403 ]
            );
        }

        return true;
    }

    /**
     * Shapes a term for MCP output.
     *
     * @param \WP_Term $term Term to format.
     * @return array<string, mixed>
     */
    protected function formatTerm( \WP_Term $term ): array {
        return [
            'id'          => $term->term_id,
            'name'        => $term->name,
            'slug'        => $term->slug,
            'description' => $term->description,
            'parent'      => $term->parent,
            'count'       => $term->count,
            'taxonomy'    => $term->taxonomy,
        ];
    }

    /**
     * JSON schema of a formatted term.
     *
     * @return array<string, mixed>
     */
    protected function termSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'id'          => [ 'type' => 'integer' ],
                'name'        => [ 'type' => 'string' ],
                'slug'        => [ 'type' => 'string' ],
                'description' => [ 'type' => 'string' ],
                'parent'      => [ 'type' => 'integer' ],
                'count'       => [ 'type' => 'integer' ],
                'taxonomy'    => [ 'type' => 'string' ],
            ],
        ];
    }
}
