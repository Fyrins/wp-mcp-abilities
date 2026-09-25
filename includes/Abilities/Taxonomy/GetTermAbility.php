<?php
/**
 * Retrieves a single term.
 *
 * @package WpMcpAbilities\Abilities\Taxonomy
 */

namespace WpMcpAbilities\Abilities\Taxonomy;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Capabilities;
use WpMcpAbilities\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Read access to a single term of any exposed taxonomy.
 */
class GetTermAbility extends AbstractAbility {
    use TaxonomyFormatting;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'get-term' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'Get a term', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __(
            'Retrieves a taxonomy term by ID (name, slug, description, parent, count).',
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
                'term_id'  => [
                    'type'        => 'integer',
                    'description' => __( 'Identifier of the term to retrieve.', 'wp-mcp-abilities' ),
                ],
                'taxonomy' => [
                    'type'        => 'string',
                    'description' => __(
                        'Taxonomy the term belongs to. Optional if the ID is unambiguous.',
                        'wp-mcp-abilities'
                    ),
                ],
            ],
            'required'   => [ 'term_id' ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function getOutputSchema(): array {
        return $this->termSchema();
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
        $input = Input::normalize( $input );
        $term  = $this->resolveTerm( Input::int( $input, 'term_id' ), Input::string( $input, 'taxonomy' ) );

        if ( is_wp_error( $term ) ) {
            return $term;
        }

        $valid = $this->validateTaxonomy( $term->taxonomy );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( ! Capabilities::canRead() ) {
            return $this->forbidden();
        }

        /**
         * Filters the payload returned by the get-term ability.
         *
         * @param array<string, mixed> $result Formatted term payload.
         * @param \WP_Term              $term   Raw term object.
         */
        return (array) apply_filters( 'wpmcpa_get_term_result', $this->formatTerm( $term ), $term );
    }
}
