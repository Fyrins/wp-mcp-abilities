<?php
/**
 * Reads the SEOpress meta of a post.
 *
 * @package WpMcpAbilities\Abilities\Seopress
 */

namespace WpMcpAbilities\Abilities\Seopress;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Capabilities;
use WpMcpAbilities\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only access to the SEOpress meta of a post.
 */
class GetPostSeoAbility extends AbstractAbility {
    use SeopressMetaMap;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'get-post-seopress' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'Get post SEOpress meta', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __( 'Reads the SEOpress meta of a post.', 'wp-mcp-abilities' );
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
                'post_id' => [
                    'type'        => 'integer',
                    'description' => __( 'The post ID.', 'wp-mcp-abilities' ),
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
            'properties' => array_merge(
                [
                    'focus_keyword'    => [ 'type' => [ 'string', 'null' ] ],
                    'primary_category' => [ 'type' => [ 'string', 'null' ] ],
                    'breadcrumb_title' => [ 'type' => [ 'string', 'null' ] ],
                    'nosnippet'        => [ 'type' => 'boolean' ],
                    'noimageindex'     => [ 'type' => 'boolean' ],
                    'redirect_enabled' => [ 'type' => 'boolean' ],
                    'redirect_type'    => [ 'type' => [ 'integer', 'null' ] ],
                    'redirect_url'     => [ 'type' => [ 'string', 'null' ] ],
                ],
                $this->seoReadSchemaProperties()
            ),
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

        $result = $this->readSeoData(
            $post->ID,
            $this->postSeoFields(),
            static fn ( int $objectId, string $metaKey ): mixed => get_post_meta( $objectId, $metaKey, true )
        );

        /**
         * Filters the SEOpress payload returned by the get-post-seopress ability.
         *
         * @param array<string, mixed> $result Formatted payload.
         * @param \WP_Post              $post   Post the meta was read from.
         */
        return (array) apply_filters( 'wpmcpa_get_post_seo_result', $result, $post );
    }
}
