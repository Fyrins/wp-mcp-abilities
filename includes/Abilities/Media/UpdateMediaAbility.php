<?php
/**
 * Updates the metadata of an existing attachment.
 *
 * @package WpMcpAbilities\Abilities\Media
 */

namespace WpMcpAbilities\Abilities\Media;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Capabilities;
use WpMcpAbilities\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Updates the title, alt text or caption of an existing attachment.
 */
class UpdateMediaAbility extends AbstractAbility {
    use MediaFormatting;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'update-media' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'Update a media', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __(
            'Updates the title, alt text or caption of an existing media.',
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
                'attachment_id' => [
                    'type'        => 'integer',
                    'description' => __( 'Identifier of the attachment to update.', 'wp-mcp-abilities' ),
                ],
                'title'          => [
                    'type'        => 'string',
                    'description' => __( 'New title.', 'wp-mcp-abilities' ),
                ],
                'alt_text'       => [
                    'type'        => 'string',
                    'description' => __( 'New alt text.', 'wp-mcp-abilities' ),
                ],
                'caption'        => [
                    'type'        => 'string',
                    'description' => __( 'New caption.', 'wp-mcp-abilities' ),
                ],
            ],
            'required'   => [ 'attachment_id' ],
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
                'media'   => $this->mediaSchema(),
            ],
        ];
    }

    /**
     * Authorises against the very attachment being edited, not a blanket capability.
     *
     * @inheritDoc
     * @param mixed $input Raw input coming from the MCP adapter.
     */
    public function checkPermission( mixed $input = null ): bool {
        $input      = Input::normalize( $input );
        $attachment = $this->resolveAttachment( Input::int( $input, 'attachment_id' ) );

        if ( is_wp_error( $attachment ) ) {
            return false;
        }

        return Capabilities::canEditPost( $attachment );
    }

    /**
     * @inheritDoc
     * @param mixed $input Raw input coming from the MCP adapter.
     */
    public function execute( mixed $input = null ): array|\WP_Error {
        $input      = Input::normalize( $input );
        $attachment = $this->resolveAttachment( Input::int( $input, 'attachment_id' ) );

        if ( is_wp_error( $attachment ) ) {
            return $attachment;
        }

        if ( ! Capabilities::canEditPost( $attachment ) ) {
            return $this->forbidden();
        }

        $updateArgs = [ 'ID' => $attachment->ID ];

        if ( isset( $input['title'] ) ) {
            $updateArgs['post_title'] = Input::string( $input, 'title' );
        }

        if ( isset( $input['caption'] ) ) {
            $updateArgs['post_excerpt'] = Input::string( $input, 'caption' );
        }

        /**
         * Filters the `wp_update_post()` arguments used by the update-media ability.
         *
         * @param array<string, mixed> $updateArgs Arguments passed to wp_update_post().
         * @param \WP_Post              $attachment Attachment being updated.
         * @param array<string, mixed>  $input      Normalised MCP input.
         */
        $updateArgs = (array) apply_filters( 'wpmcpa_update_media_args', $updateArgs, $attachment, $input );

        if ( count( $updateArgs ) > 1 ) {
            $result = wp_update_post( $updateArgs, true );

            if ( is_wp_error( $result ) ) {
                return $result;
            }
        }

        if ( isset( $input['alt_text'] ) ) {
            update_post_meta( $attachment->ID, '_wp_attachment_image_alt', Input::string( $input, 'alt_text' ) );
        }

        $updated = $this->resolveAttachment( $attachment->ID );

        if ( is_wp_error( $updated ) ) {
            return $updated;
        }

        return [
            'success' => true,
            'media'   => $this->formatMedia( $updated ),
        ];
    }
}
