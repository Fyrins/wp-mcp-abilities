<?php
/**
 * Updates a term meta field.
 *
 * @package WpMcpAbilities\Abilities\Meta
 */

namespace WpMcpAbilities\Abilities\Meta;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Capabilities;
use WpMcpAbilities\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Writes a single term meta field, guarded by the protected-meta allowlist.
 */
class UpdateTermMetaAbility extends AbstractAbility {
    use MetaAccess;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'update-term-meta' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'Update term meta', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __(
            'Updates a term meta field. Protected meta keys are refused unless explicitly allowlisted.',
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
                'term_id'    => [
                    'type'        => 'integer',
                    'description' => __( 'Identifier of the term to update.', 'wp-mcp-abilities' ),
                ],
                'taxonomy'   => [
                    'type'        => 'string',
                    'description' => __( 'Taxonomy the term belongs to, used to disambiguate the lookup.', 'wp-mcp-abilities' ),
                ],
                'meta_key'   => [
                    'type'        => 'string',
                    'description' => __( 'Meta key to write.', 'wp-mcp-abilities' ),
                ],
                'meta_value' => $this->metaValueSchema(),
            ],
            'required'   => [ 'term_id', 'meta_key', 'meta_value' ],
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
            ],
        ];
    }

    /**
     * Authorises against the very term being edited.
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

        $metaKey = $this->resolveMetaKey( 'term', $input['meta_key'] ?? null );
        if ( is_wp_error( $metaKey ) ) {
            return $metaKey;
        }

        $metaValue = $this->sanitizeMetaValue( $input['meta_value'] ?? null );
        if ( is_wp_error( $metaValue ) ) {
            return $metaValue;
        }

        update_term_meta( $term->term_id, $metaKey, $metaValue );

        return [
            'success'    => true,
            'term_id'    => $term->term_id,
            'meta_key'   => $metaKey,
            'meta_value' => get_term_meta( $term->term_id, $metaKey, true ),
        ];
    }
}
