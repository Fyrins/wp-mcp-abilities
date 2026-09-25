<?php
/**
 * Shared shaping and validation for media abilities.
 *
 * @package WpMcpAbilities\Abilities\Media
 */

namespace WpMcpAbilities\Abilities\Media;

defined( 'ABSPATH' ) || exit;

/**
 * Formats attachments and guards the object abilities are allowed to touch.
 */
trait MediaFormatting {
    /**
     * Resolves an attachment, rejecting any other post type.
     *
     * @param int $attachmentId Attachment ID.
     * @return \WP_Post|\WP_Error
     */
    protected function resolveAttachment( int $attachmentId ): \WP_Post|\WP_Error {
        $attachment = $this->resolvePost( $attachmentId );

        if ( is_wp_error( $attachment ) ) {
            return $attachment;
        }

        if ( 'attachment' !== $attachment->post_type ) {
            return new \WP_Error(
                'attachment_not_found',
                __( 'Attachment not found.', 'wp-mcp-abilities' ),
                [ 'status' => 404 ]
            );
        }

        return $attachment;
    }

    /**
     * Shapes an attachment for MCP output.
     *
     * @param \WP_Post $attachment Attachment to format.
     * @return array<string, mixed>
     */
    protected function formatMedia( \WP_Post $attachment ): array {
        $attachedFile = get_attached_file( $attachment->ID );

        return [
            'id'        => $attachment->ID,
            'title'     => $attachment->post_title,
            'filename'  => basename( false !== $attachedFile ? $attachedFile : '' ),
            'url'       => (string) wp_get_attachment_url( $attachment->ID ),
            'mime_type' => $attachment->post_mime_type,
            'alt_text'  => (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
            'caption'   => $attachment->post_excerpt,
        ];
    }

    /**
     * JSON schema of a formatted attachment.
     *
     * @return array<string, mixed>
     */
    protected function mediaSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'id'        => [ 'type' => 'integer' ],
                'title'     => [ 'type' => 'string' ],
                'filename'  => [ 'type' => 'string' ],
                'url'       => [ 'type' => 'string' ],
                'mime_type' => [ 'type' => 'string' ],
                'alt_text'  => [ 'type' => 'string' ],
                'caption'   => [ 'type' => 'string' ],
            ],
        ];
    }
}
