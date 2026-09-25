<?php
/**
 * Reads the SEOpress meta of a taxonomy term.
 *
 * @package WpMcpAbilities\Abilities\Seopress
 */

namespace WpMcpAbilities\Abilities\Seopress;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Capabilities;
use WpMcpAbilities\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only access to the SEOpress meta of a taxonomy term.
 */
class GetTermSeoAbility extends AbstractAbility {
    use SeopressMetaMap;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'get-term-seopress' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'Get term SEOpress meta', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __( 'Reads the SEOpress meta of a taxonomy term.', 'wp-mcp-abilities' );
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
            'properties' => [
                'term_id'  => [
                    'type'        => 'integer',
                    'description' => __( 'The term ID.', 'wp-mcp-abilities' ),
                ],
                'taxonomy' => [
                    'type'        => 'string',
                    'description' => __( 'The taxonomy slug. Optional if term_id is unambiguous.', 'wp-mcp-abilities' ),
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
            'properties' => $this->seoReadSchemaProperties(),
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
        $input = Input::normalize( $input );
        $term  = $this->resolveTerm( Input::int( $input, 'term_id' ), Input::string( $input, 'taxonomy' ) );

        if ( is_wp_error( $term ) ) {
            return $term;
        }

        $result = $this->readSeoData(
            $term->term_id,
            $this->termSeoFields(),
            static fn ( int $objectId, string $metaKey ): mixed => get_term_meta( $objectId, $metaKey, true )
        );

        /**
         * Filters the SEOpress payload returned by the get-term-seopress ability.
         *
         * @param array<string, mixed> $result Formatted payload.
         * @param \WP_Term              $term   Term the meta was read from.
         */
        return (array) apply_filters( 'wpmcpa_get_term_seo_result', $result, $term );
    }
}
