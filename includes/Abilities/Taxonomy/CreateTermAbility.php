<?php
/**
 * Creates a new term in a taxonomy.
 *
 * @package WpMcpAbilities\Abilities\Taxonomy
 */

namespace WpMcpAbilities\Abilities\Taxonomy;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Capabilities;
use WpMcpAbilities\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Inserts a term into any exposed taxonomy.
 */
class CreateTermAbility extends AbstractAbility {
    use TaxonomyFormatting;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'create-term' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'Create a term', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __( 'Creates a new term in a taxonomy.', 'wp-mcp-abilities' );
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
                'taxonomy'    => [
                    'type'        => 'string',
                    'description' => __( 'Taxonomy the term is created in.', 'wp-mcp-abilities' ),
                ],
                'name'        => [
                    'type'        => 'string',
                    'description' => __( 'Term display name.', 'wp-mcp-abilities' ),
                ],
                'slug'        => [
                    'type'        => 'string',
                    'description' => __(
                        'Term slug. Auto-generated from the name if omitted.',
                        'wp-mcp-abilities'
                    ),
                ],
                'description' => [
                    'type'        => 'string',
                    'description' => __( 'Term description.', 'wp-mcp-abilities' ),
                ],
                'parent_id'   => [
                    'type'        => 'integer',
                    'description' => __( 'Parent term, for hierarchical taxonomies.', 'wp-mcp-abilities' ),
                ],
            ],
            'required'   => [ 'taxonomy', 'name' ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function getOutputSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'success' => [ 'type' => 'boolean' ],
                'term'    => $this->termSchema(),
            ],
        ];
    }

    /**
     * Authorises against the taxonomy the term will be created in.
     *
     * @inheritDoc
     * @param mixed $input Raw input coming from the MCP adapter.
     */
    public function checkPermission( mixed $input = null ): bool {
        $input    = Input::normalize( $input );
        $taxonomy = Input::string( $input, 'taxonomy' );

        if ( '' === $taxonomy ) {
            return false;
        }

        return Capabilities::canManageTerms( $taxonomy );
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

        if ( ! Capabilities::canManageTerms( $taxonomy ) ) {
            return $this->forbidden();
        }

        $name = Input::string( $input, 'name' );

        if ( '' === $name ) {
            return new \WP_Error(
                'missing_name',
                __( 'The "name" parameter is required.', 'wp-mcp-abilities' ),
                [ 'status' => 400 ]
            );
        }

        $args = [];

        if ( isset( $input['slug'] ) ) {
            $args['slug'] = sanitize_title( Input::string( $input, 'slug' ) );
        }

        if ( isset( $input['description'] ) ) {
            $args['description'] = sanitize_textarea_field( (string) $input['description'] );
        }

        if ( isset( $input['parent_id'] ) ) {
            $args['parent'] = Input::int( $input, 'parent_id' );
        }

        /**
         * Filters the `wp_insert_term()` arguments used by the create-term ability.
         *
         * @param array<string, mixed> $args     Arguments passed to wp_insert_term().
         * @param string               $taxonomy Taxonomy the term is created in.
         * @param array<string, mixed> $input    Normalised MCP input.
         */
        $args = (array) apply_filters( 'wpmcpa_create_term_args', $args, $taxonomy, $input );

        $result = wp_insert_term( $name, $taxonomy, $args );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $term = $this->resolveTerm( (int) $result['term_id'], $taxonomy );

        if ( is_wp_error( $term ) ) {
            return $term;
        }

        return [
            'success' => true,
            'term'    => $this->formatTerm( $term ),
        ];
    }
}
