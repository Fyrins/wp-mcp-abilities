<?php
/**
 * Assigns an existing media as the featured image of a post.
 *
 * @package WpMcpAbilities\Abilities\Media
 */

namespace WpMcpAbilities\Abilities\Media;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Capabilities;
use WpMcpAbilities\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Sets the featured image of a post from an attachment already in the library.
 *
 * Authorisation is resolved against the target post, not the attachment: this
 * is what governs whether the caller may alter the post's featured image.
 */
class SetFeaturedImageAbility extends AbstractAbility {
    use MediaFormatting;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'set-featured-image' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'Set the featured image', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __(
            'Assigns an existing media as the featured image of a post.',
            'wp-mcp-abilities'
        );
    }

    /**
     * @inheritDoc
     */
    public function getGroup(): string {
        return __( 'Media', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'post_id'       => [
                    'type'        => 'integer',
                    'description' => __( 'Identifier of the post to update.', 'wp-mcp-abilities' ),
                ],
                'attachment_id' => [
                    'type'        => 'integer',
                    'description' => __( 'Identifier of the attachment to use.', 'wp-mcp-abilities' ),
                ],
            ],
            'required'   => [ 'post_id', 'attachment_id' ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function getOutputSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'success'       => [ 'type' => 'boolean' ],
                'post_id'       => [ 'type' => 'integer' ],
                'attachment_id' => [ 'type' => 'integer' ],
            ],
        ];
    }

    /**
     * Authorises against the post being edited, not the attachment.
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

        $attachment = $this->resolveAttachment( Input::int( $input, 'attachment_id' ) );

        if ( is_wp_error( $attachment ) ) {
            return $attachment;
        }

        $result = set_post_thumbnail( $post->ID, $attachment->ID );

        if ( ! $result ) {
            return new \WP_Error(
                'set_featured_image_failed',
                __( 'Unable to set the featured image.', 'wp-mcp-abilities' ),
                [ 'status' => 500 ]
            );
        }

        return [
            'success'       => true,
            'post_id'       => $post->ID,
            'attachment_id' => $attachment->ID,
        ];
    }
}
