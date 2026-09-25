<?php
/**
 * Reads a post meta field.
 *
 * @package WpMcpAbilities\Abilities\Meta
 */

namespace WpMcpAbilities\Abilities\Meta;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Capabilities;
use WpMcpAbilities\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a single post meta field, guarded by the protected-meta allowlist.
 */
class GetPostMetaAbility extends AbstractAbility {
    use MetaAccess;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'get-post-meta' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'Get post meta', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __(
            'Reads a post meta field. Protected meta keys are refused unless explicitly allowlisted.',
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
                'post_id'  => [
                    'type'        => 'integer',
                    'description' => __( 'Identifier of the post to read.', 'wp-mcp-abilities' ),
                ],
                'meta_key' => [
                    'type'        => 'string',
                    'description' => __(
                        'Meta key to read. Omit it to list every readable meta of the post.',
                        'wp-mcp-abilities'
                    ),
                ],
            ],
            'required'   => [ 'post_id' ],
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
                'meta'       => [
                    'type'        => 'object',
                    'description' => __(
                        'Every readable meta of the post, returned when no meta key was given.',
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
        $post  = $this->resolvePost( Input::int( $input, 'post_id' ) );

        if ( is_wp_error( $post ) ) {
            return $post;
        }

        $rawMetaKey = $input['meta_key'] ?? null;

        if ( null === $rawMetaKey || '' === $rawMetaKey ) {
            return [
                'success' => true,
                'post_id' => $post->ID,
                'meta'    => $this->filterReadableMeta( 'post', (array) get_post_meta( $post->ID ) ),
            ];
        }

        $metaKey = $this->resolveMetaKey( 'post', $rawMetaKey );
        if ( is_wp_error( $metaKey ) ) {
            return $metaKey;
        }

        return [
            'success'    => true,
            'post_id'    => $post->ID,
            'meta_key'   => $metaKey,
            'meta_value' => get_post_meta( $post->ID, $metaKey, true ),
        ];
    }
}
