<?php
/**
 * Creates a new template or template part.
 *
 * @package WpMcpAbilities\Abilities\Template
 */

namespace WpMcpAbilities\Abilities\Template;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Capabilities;
use WpMcpAbilities\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Inserts a custom block template or template part.
 *
 * Reproduces the `$template === null` branch of
 * `WP_REST_Templates_Controller::prepare_item_for_database()`: a fresh
 * `publish` post tagged with the active theme, inserted through
 * `wp_insert_post( wp_slash( (array) $prepared ), true )`. Calling
 * `wp_insert_post()` with anything less structured than that (missing
 * `tax_input['wp_theme']`, wrong `post_status`…) produces a post the block
 * template APIs will not recognise as belonging to the theme.
 */
class CreateTemplateAbility extends AbstractAbility {
    use TemplateAccess;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'create-template' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'Create a template', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __(
            'Creates a new block template or template part, tagged with the active theme.',
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
                'type'        => $this->typeProperty(),
                'slug'        => [
                    'type'        => 'string',
                    'description' => __( 'Unique slug identifying the template.', 'wp-mcp-abilities' ),
                ],
                'title'       => [
                    'type'        => 'string',
                    'description' => __( 'Template title.', 'wp-mcp-abilities' ),
                ],
                'description' => [
                    'type'        => 'string',
                    'description' => __( 'Template description.', 'wp-mcp-abilities' ),
                ],
                'content'     => [
                    'type'        => 'string',
                    'description' => __( 'Serialized block markup making up the template.', 'wp-mcp-abilities' ),
                ],
                'area'        => [
                    'type'        => 'string',
                    'description' => __(
                        'Template part area (uncategorized, header, footer, navigation-overlay). Only used when "type" is "wp_template_part"; invalid values fall back to "uncategorized".',
                        'wp-mcp-abilities'
                    ),
                    'default'     => 'uncategorized',
                ],
            ],
            'required'   => [ 'slug' ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function getOutputSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'success'          => [ 'type' => 'boolean' ],
                'content_filtered' => [
                    'type'        => 'boolean',
                    'description' => __(
                        'True when the stored content differs from the submitted content, meaning wp_filter_post_kses() stripped disallowed markup (the caller lacks the unfiltered_html capability).',
                        'wp-mcp-abilities'
                    ),
                ],
                'template'         => $this->templateSchema(),
            ],
        ];
    }

    /**
     * @inheritDoc
     * @param mixed $input Raw input coming from the MCP adapter.
     */
    public function checkPermission( mixed $input = null ): bool {
        return Capabilities::canEditTemplates();
    }

    /**
     * Inserts the template.
     *
     * The block markup submitted in `content` is passed to `wp_insert_post()`
     * as-is. WordPress runs it through `wp_filter_post_kses()` during
     * `sanitize_post()` unless the current user has the `unfiltered_html`
     * capability (granted to administrators on non-multisite installs only).
     * A restricted service account would therefore see disallowed tags or
     * attributes silently stripped from its Gutenberg markup. The response
     * exposes `content_filtered`, computed by comparing the submitted content
     * against what actually landed in the database, so callers can detect it
     * instead of being surprised by a mismatched template later.
     *
     * @inheritDoc
     * @param mixed $input Raw input coming from the MCP adapter.
     */
    public function execute( mixed $input = null ): array|\WP_Error {
        if ( ! Capabilities::canEditTemplates() ) {
            return $this->forbidden();
        }

        $input = Input::normalize( $input );
        $type  = $this->resolveType( $input );

        $valid = $this->validateType( $type );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        $slug = Input::string( $input, 'slug' );

        if ( '' === $slug ) {
            return new \WP_Error(
                'missing_slug',
                __( 'The "slug" parameter is required.', 'wp-mcp-abilities' ),
                [ 'status' => 400 ]
            );
        }

        $valid = $this->validateSlug( $slug );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        $id = get_stylesheet() . '//' . $slug;

        $existing = get_block_template( $id, $type );
        if ( $existing instanceof \WP_Block_Template && 'custom' === $existing->source ) {
            return new \WP_Error(
                'template_exists',
                __( 'A custom template already exists with this slug. Use the update ability instead.', 'wp-mcp-abilities' ),
                [ 'status' => 409 ]
            );
        }

        $content = $this->rawContent( $input, 'content' );

        $prepared = [
            'post_type'    => $type,
            'post_status'  => 'publish',
            'post_name'    => $slug,
            'post_title'   => Input::string( $input, 'title' ),
            'post_excerpt' => sanitize_textarea_field( (string) ( $input['description'] ?? '' ) ),
            'post_content' => $content,
            'tax_input'    => [ 'wp_theme' => get_stylesheet() ],
        ];

        if ( 'wp_template_part' === $type ) {
            $prepared['tax_input']['wp_template_part_area'] = $this->validateArea(
                Input::string( $input, 'area', 'uncategorized' )
            );
        }

        /**
         * Filters the `wp_insert_post()` arguments used by the create-template ability.
         *
         * @param array<string, mixed> $prepared Arguments passed to wp_insert_post().
         * @param string                $type     Template post type being created.
         * @param array<string, mixed>  $input    Normalised MCP input.
         */
        $prepared = (array) apply_filters( 'wpmcpa_create_template_args', $prepared, $type, $input );

        $postId = wp_insert_post( wp_slash( $prepared ), true );

        if ( is_wp_error( $postId ) ) {
            return $postId;
        }

        $storedContent   = (string) get_post_field( 'post_content', $postId, 'raw' );
        $contentFiltered = $storedContent !== $content;

        $posts = get_block_templates( [ 'wp_id' => $postId ], $type );

        if ( [] === $posts ) {
            return new \WP_Error(
                'template_not_found',
                __( 'The template was created but could not be resolved.', 'wp-mcp-abilities' ),
                [ 'status' => 500 ]
            );
        }

        $template = $this->resolveTemplate( $posts[0]->id, $type );
        if ( is_wp_error( $template ) ) {
            return $template;
        }

        return [
            'success'          => true,
            'content_filtered' => $contentFiltered,
            'template'         => $this->formatTemplate( $template ),
        ];
    }
}
