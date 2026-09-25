<?php
/**
 * Lists the attachments of the media library.
 *
 * @package WpMcpAbilities\Abilities\Media
 */

namespace WpMcpAbilities\Abilities\Media;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Capabilities;
use WpMcpAbilities\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Paginated read access to the media library.
 */
class ListMediaAbility extends AbstractAbility {
    use MediaFormatting;

    /**
     * Highest number of attachments a single call may return.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'list-media' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'List media', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __(
            'Lists the attachments of the media library, with MIME type filtering and pagination.',
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
                'mime_type' => [
                    'type'        => 'string',
                    'description' => __( 'Restricts results to this MIME type, e.g. "image" or "image/png".', 'wp-mcp-abilities' ),
                ],
                'search'    => [
                    'type'        => 'string',
                    'description' => __( 'Restricts results to attachments matching this string.', 'wp-mcp-abilities' ),
                ],
                'per_page'  => [
                    'type'        => 'integer',
                    'description' => __( 'Number of attachments per page.', 'wp-mcp-abilities' ),
                    'minimum'     => 1,
                    'maximum'     => self::MAX_PER_PAGE,
                    'default'     => 50,
                ],
                'page'      => [
                    'type'        => 'integer',
                    'description' => __( 'Page to return, starting at 1.', 'wp-mcp-abilities' ),
                    'minimum'     => 1,
                    'default'     => 1,
                ],
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function getOutputSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'items'    => [
                    'type'  => 'array',
                    'items' => $this->mediaSchema(),
                ],
                'total'    => [ 'type' => 'integer' ],
                'page'     => [ 'type' => 'integer' ],
                'per_page' => [ 'type' => 'integer' ],
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
        $input   = Input::normalize( $input );
        $perPage = min( max( Input::int( $input, 'per_page', 50 ), 1 ), self::MAX_PER_PAGE );
        $page    = max( Input::int( $input, 'page', 1 ), 1 );

        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => $perPage,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => false,
        ];

        if ( isset( $input['mime_type'] ) ) {
            $args['post_mime_type'] = Input::string( $input, 'mime_type' );
        }

        if ( isset( $input['search'] ) ) {
            $args['s'] = Input::string( $input, 'search' );
        }

        /**
         * Filters the `WP_Query` arguments used by the list-media ability.
         *
         * @param array<string, mixed> $args  Arguments passed to WP_Query.
         * @param array<string, mixed> $input Normalised MCP input.
         */
        $args = (array) apply_filters( 'wpmcpa_list_media_query_args', $args, $input );

        $query = new \WP_Query( $args );

        $result = [
            'items'    => array_map( [ $this, 'formatMedia' ], $query->posts ),
            'total'    => (int) $query->found_posts,
            'page'     => $page,
            'per_page' => $perPage,
        ];

        /**
         * Filters the payload returned by the list-media ability.
         *
         * @param array<string, mixed> $result Formatted payload.
         * @param \WP_Post[]           $items  Raw attachment objects.
         */
        return (array) apply_filters( 'wpmcpa_list_media_result', $result, $query->posts );
    }
}
