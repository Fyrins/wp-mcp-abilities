<?php
/**
 * Updates the SEOpress meta of a taxonomy term.
 *
 * @package WpMcpAbilities\Abilities\Seopress
 */

namespace WpMcpAbilities\Abilities\Seopress;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Capabilities;
use WpMcpAbilities\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the SEOpress title, robots, Open Graph and Twitter meta of a term.
 */
class UpdateTermSeoAbility extends AbstractAbility {
    use SeopressMetaMap;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'update-term-seopress' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'Update term SEOpress meta', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __( 'Updates the SEOpress meta of a taxonomy term.', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getGroup(): string {
        return __( 'SEOpress', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function isAvailable(): bool {
        return $this->isSeopressActive();
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array {
        return [
            'type'       => 'object',
            'properties' => array_merge(
                [
                    'term_id'  => [ 'type' => 'integer', 'description' => __( 'The term ID.', 'wp-mcp-abilities' ) ],
                    'taxonomy' => [ 'type' => 'string', 'description' => __( 'The taxonomy slug. Optional if term_id is unambiguous.', 'wp-mcp-abilities' ) ],
                ],
                $this->seoWriteSchemaProperties()
            ),
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
     * Authorises against the very term being edited, not a blanket capability.
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

        return Capabilities::canEditTerm( $term );
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

        if ( ! Capabilities::canEditTerm( $term ) ) {
            return $this->forbidden();
        }

        $fields  = $this->termSeoFields();
        $seoData = $this->buildSeoWriteData( $input, $fields );

        /**
         * Filters the SEOpress term meta about to be written.
         *
         * @param array<string, string|int> $seoData Cast values, keyed by MCP input key.
         * @param \WP_Term                   $term    Term being updated.
         * @param array<string, mixed>       $input   Normalised MCP input.
         */
        $seoData = (array) apply_filters( 'wpmcpa_update_term_seo_data', $seoData, $term, $input );

        $this->persistSeoData( $term->term_id, $seoData, $fields, 'update_term_meta' );

        return [ 'success' => true ];
    }
}
