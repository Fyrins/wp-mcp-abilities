<?php
/**
 * Deletes a term meta field.
 *
 * @package WpMcpAbilities\Abilities\Meta
 */

namespace WpMcpAbilities\Abilities\Meta;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Capabilities;
use WpMcpAbilities\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes a single term meta field, guarded by the protected-meta allowlist.
 *
 * Destructive by nature, this ability is disabled by default.
 */
class DeleteTermMetaAbility extends AbstractAbility {
    use MetaAccess;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'delete-term-meta' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'Delete term meta', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __(
            'Deletes a term meta field. Protected meta keys are refused unless explicitly allowlisted.',
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
                    'description' => __( 'Identifier of the term to update.', 'wp-mcp-abilities' ),
                ],
                'taxonomy' => [
                    'type'        => 'string',
                    'description' => __( 'Taxonomy the term belongs to, used to disambiguate the lookup.', 'wp-mcp-abilities' ),
                ],
                'meta_key' => [
                    'type'        => 'string',
                    'description' => __( 'Meta key to delete.', 'wp-mcp-abilities' ),
                ],
            ],
            'required'   => [ 'term_id', 'meta_key' ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function getOutputSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'success'  => [ 'type' => 'boolean' ],
                'term_id'  => [ 'type' => 'integer' ],
                'meta_key' => [ 'type' => 'string' ],
            ],
        ];
    }

    /**
     * Destructive ability: hidden until a site explicitly enables it.
     *
     * @inheritDoc
     */
    public function isEnabledByDefault(): bool {
        return false;
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

        delete_term_meta( $term->term_id, $metaKey );

        return [
            'success'  => true,
            'term_id'  => $term->term_id,
            'meta_key' => $metaKey,
        ];
    }
}
