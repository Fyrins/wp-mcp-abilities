<?php
/**
 * Reads a term meta field.
 *
 * @package WpMcpAbilities\Abilities\Meta
 */

namespace WpMcpAbilities\Abilities\Meta;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Capabilities;
use WpMcpAbilities\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a single term meta field, guarded by the protected-meta allowlist.
 */
class GetTermMetaAbility extends AbstractAbility {
    use MetaAccess;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'get-term-meta' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'Get term meta', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __(
            'Reads a term meta field. Protected meta keys are refused unless explicitly allowlisted.',
            'wp-mcp-abilities'
        );
    }

    /**
     * @inheritDoc
     */
    public function getGroup(): string {
        return __( 'Meta', 'wp-mcp-abilities' );
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
                    'description' => __( 'Identifier of the term to read.', 'wp-mcp-abilities' ),
                ],
                'taxonomy' => [
                    'type'        => 'string',
                    'description' => __( 'Taxonomy the term belongs to, used to disambiguate the lookup.', 'wp-mcp-abilities' ),
                ],
                'meta_key' => [
                    'type'        => 'string',
                    'description' => __(
                        'Meta key to read. Omit it to list every readable meta of the term.',
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
                'success'    => [ 'type' => 'boolean' ],
                'term_id'    => [ 'type' => 'integer' ],
                'meta_key'   => [ 'type' => 'string' ],
                'meta_value' => $this->metaValueSchema(),
                'meta'       => [
                    'type'        => 'object',
                    'description' => __(
                        'Every readable meta of the term, returned when no meta key was given.',
                        'wp-mcp-abilities'
                    ),
                ],
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
        $input = Input::normalize( $input );
        $term  = $this->resolveTerm( Input::int( $input, 'term_id' ), Input::string( $input, 'taxonomy' ) );

        if ( is_wp_error( $term ) ) {
            return $term;
        }

        $rawMetaKey = $input['meta_key'] ?? null;

        if ( null === $rawMetaKey || '' === $rawMetaKey ) {
            return [
                'success' => true,
                'term_id' => $term->term_id,
                'meta'    => $this->filterReadableMeta( 'term', (array) get_term_meta( $term->term_id ) ),
            ];
        }

        $metaKey = $this->resolveMetaKey( 'term', $rawMetaKey );
        if ( is_wp_error( $metaKey ) ) {
            return $metaKey;
        }

        return [
            'success'    => true,
            'term_id'    => $term->term_id,
            'meta_key'   => $metaKey,
            'meta_value' => get_term_meta( $term->term_id, $metaKey, true ),
        ];
    }
}
