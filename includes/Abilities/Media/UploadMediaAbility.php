<?php
/**
 * Downloads a media file from a URL and adds it to the library.
 *
 * @package WpMcpAbilities\Abilities\Media
 */

namespace WpMcpAbilities\Abilities\Media;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Capabilities;
use WpMcpAbilities\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Side-loads a remote file into the media library.
 *
 * MIME type validation is delegated to `media_handle_sideload()`, which runs
 * the uploaded file through `wp_check_filetype_and_ext()` before it is
 * attached. This class only guards the transport (URL scheme, response size).
 */
class UploadMediaAbility extends AbstractAbility {
    use MediaFormatting;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'upload-media' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'Upload a media', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __(
            'Downloads a media file from a URL and adds it to the library.',
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
                'url'      => [
                    'type'        => 'string',
                    'description' => __( 'The URL of the file to download.', 'wp-mcp-abilities' ),
                ],
                'filename' => [
                    'type'        => 'string',
                    'description' => __( 'The desired filename (optional, inferred from the URL by default).', 'wp-mcp-abilities' ),
                ],
                'alt_text' => [
                    'type'        => 'string',
                    'description' => __( 'The image alt text.', 'wp-mcp-abilities' ),
                ],
            ],
            'required'   => [ 'url' ],
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
     * @inheritDoc
     * @param mixed $input Raw input coming from the MCP adapter.
     */
    public function checkPermission( mixed $input = null ): bool {
        return Capabilities::canUploadFiles();
    }

    /**
     * @inheritDoc
     * @param mixed $input Raw input coming from the MCP adapter.
     */
    public function execute( mixed $input = null ): array|\WP_Error {
        if ( ! Capabilities::canUploadFiles() ) {
            return $this->forbidden();
        }

        $input  = Input::normalize( $input );
        $url    = Input::string( $input, 'url' );
        $scheme = wp_parse_url( $url, PHP_URL_SCHEME );

        if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
            return new \WP_Error(
                'invalid_url_scheme',
                __( 'The URL must use http or https.', 'wp-mcp-abilities' ),
                [ 'status' => 400 ]
            );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmpFile = download_url( $url );

        if ( is_wp_error( $tmpFile ) ) {
            return $tmpFile;
        }

        /**
         * Filters the highest number of bytes accepted for an upload-media download.
         *
         * @param int $maxBytes Maximum accepted size, in bytes. Defaults to 10 MB.
         */
        $maxBytes = (int) apply_filters( 'wpmcpa_upload_media_max_bytes', 10 * MB_IN_BYTES );
        $fileSize = filesize( $tmpFile );

        if ( false !== $fileSize && $fileSize > $maxBytes ) {
            wp_delete_file( $tmpFile );

            return new \WP_Error(
                'file_too_large',
                __( 'The downloaded file exceeds the allowed size.', 'wp-mcp-abilities' ),
                [ 'status' => 400 ]
            );
        }

        $filename = Input::string( $input, 'filename' );
        if ( '' === $filename ) {
            $filename = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
        }

        $fileArray = [
            'name'     => sanitize_file_name( $filename ),
            'tmp_name' => $tmpFile,
        ];

        /**
         * Filters the file array passed to `media_handle_sideload()`.
         *
         * @param array{name: string, tmp_name: string} $fileArray File descriptor.
         * @param array<string, mixed>                   $input     Normalised MCP input.
         */
        $fileArray = (array) apply_filters( 'wpmcpa_upload_media_file', $fileArray, $input );

        $attachmentId = media_handle_sideload( $fileArray, 0 );

        if ( is_wp_error( $attachmentId ) ) {
            wp_delete_file( $tmpFile );

            return $attachmentId;
        }

        if ( isset( $input['alt_text'] ) ) {
            update_post_meta( $attachmentId, '_wp_attachment_image_alt', Input::string( $input, 'alt_text' ) );
        }

        $attachment = $this->resolveAttachment( (int) $attachmentId );

        if ( is_wp_error( $attachment ) ) {
            return $attachment;
        }

        return [
            'success' => true,
            'media'   => $this->formatMedia( $attachment ),
        ];
    }
}
