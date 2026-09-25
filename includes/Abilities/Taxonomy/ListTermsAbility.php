<?php
/**
 * Lists the terms of a taxonomy.
 *
 * @package WpMcpAbilities\Abilities\Taxonomy
 */

namespace WpMcpAbilities\Abilities\Taxonomy;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Capabilities;
use WpMcpAbilities\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Paginated read access to the terms of any exposed taxonomy.
 */
class ListTermsAbility extends AbstractAbility {
    use TaxonomyFormatting;

    /**
     * Highest number of terms a single call may return.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'list-terms' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'List terms', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __(
            'Lists the terms of a taxonomy, with search, parent filtering and pagination.',
            'wp-mcp-abilities'
        );
    }

    /**
     * @inheritDoc
     */
    public function getGroup(): string {
        return __( 'Taxonomies', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'taxonomy'   => [
                    'type'        => 'string',
                    'description' => __( 'Taxonomy slug.', 'wp-mcp-abilities' ),
                ],
                'search'     => [
                    'type'        => 'string',
                    'description' => __( 'Restricts results to terms matching this string.', 'wp-mcp-abilities' ),
                ],
                'parent'     => [
                    'type'        => 'integer',
                    'description' => __( 'Restricts results to the children of this term.', 'wp-mcp-abilities' ),
                ],
                'hide_empty' => [
                    'type'        => 'boolean',
                    'description' => __( 'Excludes terms not assigned to any content.', 'wp-mcp-abilities' ),
                    'default'     => false,
                ],
                'per_page'   => [
                    'type'        => 'integer',
                    'description' => __( 'Number of terms per page.', 'wp-mcp-abilities' ),
                    'minimum'     => 1,
                    'maximum'     => self::MAX_PER_PAGE,
                    'default'     => 50,
                ],
                'page'       => [
                    'type'        => 'integer',
                    'description' => __( 'Page to return, starting at 1.', 'wp-mcp-abilities' ),
                    'minimum'     => 1,
                    'default'     => 1,
                ],
            ],
            'required'   => [ 'taxonomy' ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function getOutputSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'terms'    => [
                    'type'  => 'array',
                    'items' => $this->termSchema(),
                ],
                'total'    => [ 'type' => 'integer' ],
                'page'     => [ 'type' => 'integer' ],
                'per_page' => [ 'type' => 'integer' ],
            ],
        ];
    }

    /**
     * @inheritDoc
     * @param mixed $input Raw input coming from the MCP adapter.
     */
    public function checkPermission( mixed $input = null ): bool {
        return Capabilities::canRead();
    }

    /**
     * @inheritDoc
     * @param mixed $input Raw input coming from the MCP adapter.
     */
    public function execute( mixed $input = null ): array|\WP_Error {
        $input    = Input::normalize( $input );
        $taxonomy = Input::string( $input, 'taxonomy' );

        $valid = $this->validateTaxonomy( $taxonomy );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        $perPage = min( max( Input::int( $input, 'per_page', 50 ), 1 ), self::MAX_PER_PAGE );
        $page    = max( Input::int( $input, 'page', 1 ), 1 );

        $args = [
            'taxonomy'   => $taxonomy,
            'hide_empty' => Input::bool( $input, 'hide_empty' ),
            'orderby'    => 'name',
            'order'      => 'ASC',
            'number'     => $perPage,
            'offset'     => ( $page - 1 ) * $perPage,
        ];

        if ( isset( $input['search'] ) ) {
            $args['search'] = Input::string( $input, 'search' );
        }

        if ( isset( $input['parent'] ) ) {
            $args['parent'] = Input::int( $input, 'parent' );
        }

        /**
         * Filters the `get_terms()` arguments used by the list-terms ability.
         *
         * @param array<string, mixed> $args  Arguments passed to get_terms().
         * @param array<string, mixed> $input Normalised MCP input.
         */
        $args = (array) apply_filters( 'wpmcpa_list_terms_query_args', $args, $input );

        $terms = get_terms( $args );

        if ( is_wp_error( $terms ) ) {
            return $terms;
        }

        $total = wp_count_terms(
            [
                'taxonomy'   => $taxonomy,
                'hide_empty' => $args['hide_empty'],
            ]
        );

        $result = [
            'terms'    => array_map( [ $this, 'formatTerm' ], $terms ),
            'total'    => is_wp_error( $total ) ? count( $terms ) : (int) $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];

        /**
         * Filters the payload returned by the list-terms ability.
         *
         * @param array<string, mixed> $result Formatted payload.
         * @param \WP_Term[]           $terms  Raw term objects.
         */
        return (array) apply_filters( 'wpmcpa_list_terms_result', $result, $terms );
    }
}
