<?php
/**
 * Updates an existing template or template part, or reverts it to its theme file.
 *
 * @package WpMcpAbilities\Abilities\Template
 */

namespace WpMcpAbilities\Abilities\Template;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Capabilities;
use WpMcpAbilities\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Edits a template's title, description, content or area.
 *
 * Mirrors `WP_REST_Templates_Controller::update_item()`, which branches on
 * three distinct situations depending on where the template currently comes
 * from:
 *
 * 1. `$template->source === 'custom'` - a post already exists (a previous
 *    edit created it, or it was created from scratch). Plain
 *    `wp_update_post()` against `$template->wp_id`.
 * 2. `$template->source !== 'custom'` (the template only exists as a file in
 *    the theme, or a plugin-registered template) - editing it for the first
 *    time must *create* a post that masks the file, not update anything.
 *    `wp_insert_post()` with `post_name` set to the template's slug,
 *    `tax_input['wp_theme']` set to the template's theme (not necessarily
 *    the active one, for a plugin-registered template) and
 *    `meta_input['origin']` set to the original `$template->source`, so core
 *    can still tell the customisation apart from a from-scratch template.
 * 3. `revert: true` - the caller wants to drop the customisation and let the
 *    theme file resurface. `wp_delete_post( $template->wp_id, true )`
 *    permanently deletes the masking post; `get_block_template()` then falls
 *    through to the file again. Only valid when the template is currently
 *    `custom` and does have a theme file to fall back to.
 */
class UpdateTemplateAbility extends AbstractAbility {
    use TemplateAccess;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'update-template' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'Update a template', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __(
            'Updates the title, description, content or area of a template, or reverts it to its theme file.',
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
                'id'          => [
                    'type'        => 'string',
                    'description' => __( 'Composite identifier, formatted as "{theme}//{slug}".', 'wp-mcp-abilities' ),
                ],
                'slug'        => [
                    'type'        => 'string',
                    'description' => __(
                        'Template slug. Used instead of "id", completed with the active theme.',
                        'wp-mcp-abilities'
                    ),
                ],
                'title'       => [
                    'type'        => 'string',
                    'description' => __( 'New title.', 'wp-mcp-abilities' ),
                ],
                'description' => [
                    'type'        => 'string',
                    'description' => __( 'New description.', 'wp-mcp-abilities' ),
                ],
                'content'     => [
                    'type'        => 'string',
                    'description' => __( 'New serialized block markup.', 'wp-mcp-abilities' ),
                ],
                'area'        => [
                    'type'        => 'string',
                    'description' => __(
                        'New template part area. Only used when "type" is "wp_template_part"; invalid values fall back to "uncategorized".',
                        'wp-mcp-abilities'
                    ),
                ],
                'revert'      => [
                    'type'        => 'boolean',
                    'description' => __(
                        'When true, discards the customisation and lets the theme file resurface instead of updating any field.',
                        'wp-mcp-abilities'
                    ),
                    'default'     => false,
                ],
            ],
            'required'   => [],
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
                'reverted'         => [ 'type' => 'boolean' ],
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
     * Applies one of the three update branches described in the class docblock.
     *
     * Like the create ability, the submitted `content` is only sanitised by
     * WordPress' own `wp_filter_post_kses()` inside `wp_update_post()` /
     * `wp_insert_post()`, gated by the `unfiltered_html` capability. The
     * response's `content_filtered` flag compares the submitted content
     * against what was actually persisted so a restricted caller can detect
     * silent stripping instead of assuming its markup was saved verbatim.
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

        $id = $this->resolveTemplateId( $input );
        if ( is_wp_error( $id ) ) {
            return $id;
        }

        $template = $this->resolveTemplate( $id, $type );
        if ( is_wp_error( $template ) ) {
            return $template;
        }

        if ( Input::bool( $input, 'revert', false ) ) {
            return $this->revert( $template, $id, $type );
        }

        return $this->update( $template, $id, $type, $input );
    }

    /**
     * Branch 3: drops the customisation, letting the theme file resurface.
     *
     * @param \WP_Block_Template $template Template being reverted.
     * @param string             $id       Composite identifier.
     * @param string             $type     Template post type.
     * @return array<string, mixed>|\WP_Error
     */
    private function revert( \WP_Block_Template $template, string $id, string $type ): array|\WP_Error {
        if ( 'custom' !== $template->source ) {
            return new \WP_Error(
                'template_not_customized',
                __( 'This template has no customisation to revert; it already resolves to its theme file.', 'wp-mcp-abilities' ),
                [ 'status' => 400 ]
            );
        }

        if ( ! $template->has_theme_file ) {
            return new \WP_Error(
                'template_no_theme_file',
                __( 'This template only exists as a custom post; reverting would delete it entirely. Use the delete ability instead.', 'wp-mcp-abilities' ),
                [ 'status' => 400 ]
            );
        }

        $deleted = wp_delete_post( $template->wp_id, true );

        if ( ! $deleted ) {
            return new \WP_Error(
                'revert_failed',
                __( 'Unable to revert the template.', 'wp-mcp-abilities' ),
                [ 'status' => 500 ]
            );
        }

        $reverted = $this->resolveTemplate( $id, $type );
        if ( is_wp_error( $reverted ) ) {
            return $reverted;
        }

        return [
            'success'          => true,
            'reverted'         => true,
            'content_filtered' => false,
            'template'         => $this->formatTemplate( $reverted ),
        ];
    }

    /**
     * Branches 1 and 2: edits the template, creating the masking post first
     * when it does not exist yet.
     *
     * @param \WP_Block_Template   $template Template being updated.
     * @param string               $id       Composite identifier.
     * @param string               $type     Template post type.
     * @param array<string, mixed> $input    Normalised MCP input.
     * @return array<string, mixed>|\WP_Error
     */
    private function update( \WP_Block_Template $template, string $id, string $type, array $input ): array|\WP_Error {
        $hasContent     = array_key_exists( 'content', $input );
        $hasTitle       = array_key_exists( 'title', $input );
        $hasDescription = array_key_exists( 'description', $input );
        $hasArea        = array_key_exists( 'area', $input );

        if ( ! $hasContent && ! $hasTitle && ! $hasDescription && ! $hasArea ) {
            return new \WP_Error(
                'nothing_to_update',
                __( 'Provide at least one field to update, or set "revert" to true.', 'wp-mcp-abilities' ),
                [ 'status' => 400 ]
            );
        }

        $content     = $hasContent ? $this->rawContent( $input, 'content' ) : $template->content;
        $title       = $hasTitle ? Input::string( $input, 'title' ) : $template->title;
        $description = $hasDescription
            ? sanitize_textarea_field( (string) $input['description'] )
            : $template->description;

        $isCustom = 'custom' === $template->source;

        $prepared = [
            'post_status'  => 'publish',
            'post_name'    => $template->slug,
            'post_title'   => $title,
            'post_excerpt' => $description,
            'post_content' => $content,
        ];

        if ( $isCustom ) {
            $prepared['ID'] = $template->wp_id;
        } else {
            // Branch 2: no post yet, insert one to mask the theme file.
            $prepared['post_type']  = $type;
            $prepared['tax_input']  = [ 'wp_theme' => $template->theme ];
            $prepared['meta_input'] = [ 'origin' => $template->source ];
        }

        if ( 'wp_template_part' === $type ) {
            $area                  = $hasArea ? Input::string( $input, 'area' ) : (string) ( $template->area ? $template->area : 'uncategorized' );
            $prepared['tax_input'] = $prepared['tax_input'] ?? [];
            $prepared['tax_input']['wp_template_part_area'] = $this->validateArea( $area );
        }

        /**
         * Filters the `wp_update_post()`/`wp_insert_post()` arguments used by
         * the update-template ability.
         *
         * @param array<string, mixed> $prepared Arguments passed to wp_update_post() or wp_insert_post().
         * @param \WP_Block_Template    $template Template being updated.
         * @param array<string, mixed>  $input    Normalised MCP input.
         */
        $prepared = (array) apply_filters( 'wpmcpa_update_template_args', $prepared, $template, $input );

        $result = $isCustom
            ? wp_update_post( wp_slash( $prepared ), true )
            : wp_insert_post( wp_slash( $prepared ), true );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $postId          = $isCustom ? $template->wp_id : (int) $result;
        $storedContent   = (string) get_post_field( 'post_content', $postId, 'raw' );
        $contentFiltered = $hasContent && $storedContent !== $content;

        $updated = $this->resolveTemplate( $id, $type );
        if ( is_wp_error( $updated ) ) {
            return $updated;
        }

        return [
            'success'          => true,
            'reverted'         => false,
            'content_filtered' => $contentFiltered,
            'template'         => $this->formatTemplate( $updated ),
        ];
    }
}
