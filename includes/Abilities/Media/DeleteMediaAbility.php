<?php
/**
 * Permanently deletes an attachment.
 *
 * @package WpMcpAbilities\Abilities\Media
 */

namespace WpMcpAbilities\Abilities\Media;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Capabilities;
use WpMcpAbilities\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Permanently deletes a media from the library.
 *
 * Destructive: disabled by default, sites must opt in explicitly.
 */
class DeleteMediaAbility extends AbstractAbility {
    use MediaFormatting;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'delete-media' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'Delete a media', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __(
            'Permanently deletes a media from the library.',
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
                    'description' => __( 'Identifier of the attachment to delete.', 'wp-mcp-abilities' ),
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
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function isEnabledByDefault(): bool {
        return false;
    }

    /**
     * Authorises against the very attachment being deleted, not a blanket capability.
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

        return Capabilities::canDeletePost( $attachment );
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

        if ( ! Capabilities::canDeletePost( $attachment ) ) {
            return $this->forbidden();
        }

        $result = wp_delete_attachment( $attachment->ID, true );

        if ( ! $result ) {
            return new \WP_Error(
                'delete_failed',
                __( 'Unable to delete the attachment.', 'wp-mcp-abilities' ),
                [ 'status' => 500 ]
            );
        }

        return [
            'success' => true,
        ];
    }
}
