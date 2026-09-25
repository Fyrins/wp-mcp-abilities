<?php
/**
 * Permanently deletes a term.
 *
 * @package WpMcpAbilities\Abilities\Taxonomy
 */

namespace WpMcpAbilities\Abilities\Taxonomy;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Capabilities;
use WpMcpAbilities\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes a term from any exposed taxonomy. Destructive, disabled by default.
 */
class DeleteTermAbility extends AbstractAbility {
    use TaxonomyFormatting;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'delete-term' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'Delete a term', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __( 'Permanently deletes a taxonomy term.', 'wp-mcp-abilities' );
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
                    'description' => __( 'Identifier of the term to delete.', 'wp-mcp-abilities' ),
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
        return [
            'type'       => 'object',
            'properties' => [
                'success' => [ 'type' => 'boolean' ],
            ],
        ];
    }

    /**
     * Destructive ability, never exposed unless explicitly enabled.
     *
     * @inheritDoc
     */
    public function isEnabledByDefault(): bool {
        return false;
    }

    /**
     * Authorises against the very term being deleted, not a blanket capability.
     *
     * @inheritDoc
     * @param mixed $input Raw input coming from the MCP adapter.
     */
    public function checkPermission( mixed $input = null ): bool {
        $input = Input::normalize( $input );
        $term  = $this->resolveTerm( Input::int( $input, 'term_id' ), Input::string( $input, 'taxonomy' ) );

        if ( is_wp_error( $term ) ) {
            return false;
        }

        return Capabilities::canDeleteTerm( $term );
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

        if ( ! Capabilities::canDeleteTerm( $term ) ) {
            return $this->forbidden();
        }

        $result = wp_delete_term( $term->term_id, $term->taxonomy );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( false === $result ) {
            return new \WP_Error(
                'delete_failed',
                __( 'Unable to delete the term.', 'wp-mcp-abilities' ),
                [ 'status' => 500 ]
            );
        }

        return [ 'success' => true ];
    }
}
