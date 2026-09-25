<?php
/**
 * Lists block templates or template parts.
 *
 * @package WpMcpAbilities\Abilities\Template
 */

namespace WpMcpAbilities\Abilities\Template;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Capabilities;
use WpMcpAbilities\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Paginated read access to the templates/template parts of the active theme.
 *
 * Reads through `get_block_templates()`, which merges theme file templates
 * with the posts that customise them, rather than a raw `WP_Query`.
 */
class ListTemplatesAbility extends AbstractAbility {
    use TemplateAccess;

    /**
     * Highest number of templates a single call may return.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'list-templates' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'List templates', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __(
            'Lists block templates or template parts of the active theme, with pagination.',
            'wp-mcp-abilities'
        );
    }

    /**
     * @inheritDoc
     */
    public function getGroup(): string {
        return __( 'Templates', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'type'     => $this->typeProperty(),
                'area'     => [
                    'type'        => 'string',
                    'description' => __(
                        'Restricts results to this template part area. Only used when "type" is "wp_template_part".',
                        'wp-mcp-abilities'
                    ),
                ],
                'per_page' => [
                    'type'        => 'integer',
                    'description' => __( 'Number of templates per page.', 'wp-mcp-abilities' ),
                    'minimum'     => 1,
                    'maximum'     => self::MAX_PER_PAGE,
                    'default'     => 50,
                ],
                'page'     => [
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
                    'items' => $this->templateSchema(),
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
        if ( ! Capabilities::canRead() ) {
            return $this->forbidden();
        }

        $input = Input::normalize( $input );
        $type  = $this->resolveType( $input );

        $valid = $this->validateType( $type );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        $perPage = min( max( Input::int( $input, 'per_page', 50 ), 1 ), self::MAX_PER_PAGE );
        $page    = max( Input::int( $input, 'page', 1 ), 1 );

        $query = [];

        if ( 'wp_template_part' === $type && isset( $input['area'] ) ) {
            $area = Input::string( $input, 'area' );

            if ( '' !== $area ) {
                $query['area'] = $area;
            }
        }

        /**
         * Filters the `get_block_templates()` arguments used by the list-templates ability.
         *
         * @param array<string, mixed> $query Arguments passed to get_block_templates().
         * @param string                $type  Template post type being queried.
         * @param array<string, mixed>  $input Normalised MCP input.
         */
        $query = (array) apply_filters( 'wpmcpa_list_templates_query_args', $query, $type, $input );

        $templates = get_block_templates( $query, $type );
        $total     = count( $templates );
        $offset    = ( $page - 1 ) * $perPage;
        $items     = array_slice( $templates, $offset, $perPage );

        $result = [
            'items'    => array_map( [ $this, 'formatTemplate' ], $items ),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];

        /**
         * Filters the payload returned by the list-templates ability.
         *
         * @param array<string, mixed>  $result    Formatted payload.
         * @param \WP_Block_Template[]  $templates Raw template objects.
         */
        return (array) apply_filters( 'wpmcpa_list_templates_result', $result, $templates );
    }
}
