<?php
/**
 * Updates the SEOpress meta of a post.
 *
 * @package WpMcpAbilities\Abilities\Seopress
 */

namespace WpMcpAbilities\Abilities\Seopress;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Capabilities;
use WpMcpAbilities\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the SEOpress title, robots, Open Graph, Twitter and redirection meta of a post.
 */
class UpdatePostSeoAbility extends AbstractAbility {
    use SeopressMetaMap;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'update-post-seopress' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'Update post SEOpress meta', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __( 'Updates the SEOpress meta of a post.', 'wp-mcp-abilities' );
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
                    'post_id'          => [ 'type' => 'integer', 'description' => __( 'The post ID.', 'wp-mcp-abilities' ) ],
                    'focus_keyword'    => [ 'type' => 'string', 'description' => __( 'Target keywords, comma-separated.', 'wp-mcp-abilities' ) ],
                    'primary_category' => [ 'type' => 'string', 'description' => __( 'Primary category ID for breadcrumbs, or "none".', 'wp-mcp-abilities' ) ],
                    'breadcrumb_title' => [ 'type' => 'string', 'description' => __( 'Custom breadcrumb title.', 'wp-mcp-abilities' ) ],
                    'nosnippet'        => [ 'type' => 'boolean', 'description' => __( 'Set nosnippet on this post.', 'wp-mcp-abilities' ) ],
                    'noimageindex'     => [ 'type' => 'boolean', 'description' => __( 'Set noimageindex on this post.', 'wp-mcp-abilities' ) ],
                    'redirect_enabled' => [ 'type' => 'boolean', 'description' => __( 'Enable a redirection for this post.', 'wp-mcp-abilities' ) ],
                    'redirect_type'    => [ 'type' => 'integer', 'description' => __( 'Redirect HTTP status (301, 302, or 307).', 'wp-mcp-abilities' ), 'enum' => [ 301, 302, 307 ] ],
                    'redirect_url'     => [ 'type' => 'string', 'description' => __( 'Redirect destination URL.', 'wp-mcp-abilities' ) ],
                ],
                $this->seoWriteSchemaProperties()
            ),
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
                'success' => [ 'type' => 'boolean' ],
            ],
        ];
    }

    /**
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

        $fields  = $this->postSeoFields();
        $seoData = $this->buildSeoWriteData( $input, $fields );

        /**
         * Filters the SEOpress post meta about to be written.
         *
         * @param array<string, string|int> $seoData Cast values, keyed by MCP input key.
         * @param \WP_Post                   $post    Post being updated.
         * @param array<string, mixed>       $input   Normalised MCP input.
         */
        $seoData = (array) apply_filters( 'wpmcpa_update_seo_data', $seoData, $post, $input );

        $this->persistSeoData( $post->ID, $seoData, $fields, 'update_post_meta' );

        return [ 'success' => true ];
    }
}
