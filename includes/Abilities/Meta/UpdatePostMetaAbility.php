<?php
/**
 * Updates a post meta field.
 *
 * @package WpMcpAbilities\Abilities\Meta
 */

namespace WpMcpAbilities\Abilities\Meta;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Capabilities;
use WpMcpAbilities\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Writes a single post meta field, guarded by the protected-meta allowlist.
 */
class UpdatePostMetaAbility extends AbstractAbility {
    use MetaAccess;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'update-post-meta' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'Update post meta', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __(
            'Updates a post meta field. Protected meta keys are refused unless explicitly allowlisted.',
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
                'post_id'    => [
                    'type'        => 'integer',
                    'description' => __( 'Identifier of the post to update.', 'wp-mcp-abilities' ),
                ],
                'meta_key'   => [
                    'type'        => 'string',
                    'description' => __( 'Meta key to write.', 'wp-mcp-abilities' ),
                ],
                'meta_value' => $this->metaValueSchema(),
            ],
            'required'   => [ 'post_id', 'meta_key', 'meta_value' ],
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
                'post_id'    => [ 'type' => 'integer' ],
                'meta_key'   => [ 'type' => 'string' ],
                'meta_value' => $this->metaValueSchema(),
            ],
        ];
    }

    /**
     * Authorises against the very post being edited.
     *
     * @inheritDoc
     * @param mixed $input Raw input coming from the MCP adapter.
     */
    public function checkPermission( mixed $input = null ): bool {
        $input = Input::normalize( $input );
        $post  = $this->resolvePost( Input::int( $input, 'post_id' ) );

        if ( is_wp_error( $post ) ) {
            return false;
        }

        return Capabilities::canEditPost( $post );
    }

    /**
     * @inheritDoc
     * @param mixed $input Raw input coming from the MCP adapter.
     */
    public function execute( mixed $input = null ): array|\WP_Error {
        $input = Input::normalize( $input );
        $post  = $this->resolvePost( Input::int( $input, 'post_id' ) );

        if ( is_wp_error( $post ) ) {
            return $post;
        }

        if ( ! Capabilities::canEditPost( $post ) ) {
            return $this->forbidden();
        }

        $metaKey = $this->resolveMetaKey( 'post', $input['meta_key'] ?? null );
        if ( is_wp_error( $metaKey ) ) {
            return $metaKey;
        }

        $metaValue = $this->sanitizeMetaValue( $input['meta_value'] ?? null );
        if ( is_wp_error( $metaValue ) ) {
            return $metaValue;
        }

        update_post_meta( $post->ID, $metaKey, $metaValue );

        return [
            'success'    => true,
            'post_id'    => $post->ID,
            'meta_key'   => $metaKey,
            'meta_value' => get_post_meta( $post->ID, $metaKey, true ),
        ];
    }
}
