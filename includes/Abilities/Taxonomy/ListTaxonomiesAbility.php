<?php
/**
 * Lists the public taxonomies exposed to MCP clients.
 *
 * @package WpMcpAbilities\Abilities\Taxonomy
 */

namespace WpMcpAbilities\Abilities\Taxonomy;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Read access to the taxonomies (category, post_tag, custom…) exposed via MCP.
 */
class ListTaxonomiesAbility extends AbstractAbility {
    use TaxonomyFormatting;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'list-taxonomies' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'List taxonomies', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __(
            'Lists all public taxonomies (category, post_tag, custom).',
            'wp-mcp-abilities'
        );
    }

    /**
     * @inheritDoc
     */
    public function getGroup(): string {
        return __( 'Taxonomies', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [],
        ];
    }

    /**
     * @inheritDoc
     */
    public function getOutputSchema(): array {
        return [
            'type'  => 'array',
            'items' => [
                'type'       => 'object',
                'properties' => [
                    'name'         => [ 'type' => 'string' ],
                    'label'        => [ 'type' => 'string' ],
                    'hierarchical' => [ 'type' => 'boolean' ],
                    'object_types' => [
                        'type'  => 'array',
                        'items' => [ 'type' => 'string' ],
                    ],
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
        if ( ! Capabilities::canRead() ) {
            return $this->forbidden();
        }

        $result = array_values(
            array_map(
                static function ( \WP_Taxonomy $taxonomy ): array {
                    return [
                        'name'         => $taxonomy->name,
                        'label'        => $taxonomy->labels->name ?? $taxonomy->label,
                        'hierarchical' => (bool) $taxonomy->hierarchical,
                        'object_types' => array_values( (array) $taxonomy->object_type ),
                    ];
                },
                $this->getTaxonomies()
            )
        );

        /**
         * Filters the payload returned by the list-taxonomies ability.
         *
         * @param array<int, array<string, mixed>> $result Formatted taxonomies.
         */
        return (array) apply_filters( 'wpmcpa_list_taxonomies_result', $result );
    }
}
