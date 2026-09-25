<?php
/**
 * Shared resolution, shaping and validation for template abilities.
 *
 * @package WpMcpAbilities\Abilities\Template
 */

namespace WpMcpAbilities\Abilities\Template;

use WpMcpAbilities\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves `WP_Block_Template` objects and shapes them for MCP output.
 *
 * Every ability of this namespace accepts a `type` parameter valued either
 * `wp_template` or `wp_template_part` instead of being duplicated per post
 * type, mirroring `WP_REST_Templates_Controller`, which is instantiated once
 * per post type by core.
 */
trait TemplateAccess {
    /**
     * Post types this trait is allowed to operate on. A method rather than a
     * constant: traits only accept constants from PHP 8.2.
     *
     * @return string[]
     */
    private static function templateTypes(): array {
        return [ 'wp_template', 'wp_template_part' ];
    }

    /**
     * Reads the `type` parameter, defaulting to `wp_template`.
     *
     * @param array<string, mixed> $input Normalised input.
     */
    protected function resolveType( array $input ): string {
        $type = Input::string( $input, 'type', 'wp_template' );

        return '' === $type ? 'wp_template' : $type;
    }

    /**
     * Makes sure the requested type is one of the two template post types.
     *
     * @param string $type Post type to validate.
     * @return true|\WP_Error
     */
    protected function validateType( string $type ): bool|\WP_Error {
        if ( ! in_array( $type, self::templateTypes(), true ) ) {
            return new \WP_Error(
                'invalid_template_type',
                __( 'The "type" parameter must be "wp_template" or "wp_template_part".', 'wp-mcp-abilities' ),
                [ 'status' => 400 ]
            );
        }

        return true;
    }

    /**
     * Validates a slug against the pattern used by the core templates schema.
     *
     * @param string $slug Slug to validate.
     * @return true|\WP_Error
     */
    protected function validateSlug( string $slug ): bool|\WP_Error {
        if ( '' === $slug || 1 !== preg_match( '/^[a-zA-Z0-9_%-]+$/', $slug ) ) {
            return new \WP_Error(
                'invalid_slug',
                __( 'The "slug" parameter must only contain letters, numbers, underscores, percent signs or hyphens.', 'wp-mcp-abilities' ),
                [ 'status' => 400 ]
            );
        }

        return true;
    }

    /**
     * Resolves the `{theme}//{slug}` composite identifier expected by
     * `get_block_template()`.
     *
     * Accepts either a full `id` or a bare `slug`, in which case the current
     * theme (`get_stylesheet()`) completes the identifier.
     *
     * @param array<string, mixed> $input Normalised input.
     * @return string|\WP_Error
     */
    protected function resolveTemplateId( array $input ): string|\WP_Error {
        $id = Input::string( $input, 'id' );

        if ( '' !== $id ) {
            $separatorPosition = strrpos( $id, '//' );

            if ( false === $separatorPosition ) {
                return new \WP_Error(
                    'invalid_template_id',
                    __( 'The "id" parameter must be formatted as "{theme}//{slug}".', 'wp-mcp-abilities' ),
                    [ 'status' => 400 ]
                );
            }

            $theme = substr( $id, 0, $separatorPosition );
            $slug  = substr( $id, $separatorPosition + 2 );

            if ( '' === $theme ) {
                return new \WP_Error(
                    'invalid_template_id',
                    __( 'The "id" parameter must be formatted as "{theme}//{slug}".', 'wp-mcp-abilities' ),
                    [ 'status' => 400 ]
                );
            }

            $valid = $this->validateSlug( $slug );
            if ( is_wp_error( $valid ) ) {
                return $valid;
            }

            return $id;
        }

        $slug = Input::string( $input, 'slug' );

        if ( '' === $slug ) {
            return new \WP_Error(
                'missing_identifier',
                __( 'Provide either "id" or "slug".', 'wp-mcp-abilities' ),
                [ 'status' => 400 ]
            );
        }

        $valid = $this->validateSlug( $slug );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        return get_stylesheet() . '//' . $slug;
    }

    /**
     * Resolves a template through the block template APIs.
     *
     * Deliberately never falls back to a raw `WP_Query` on
     * `post_type => wp_template`: templates that only exist as a file in the
     * theme (never edited, so never inserted as a post) would be invisible to
     * such a query. `get_block_template()` merges the file-based hierarchy
     * with the customised posts the same way core's REST controller does.
     *
     * @param string $id   Composite `{theme}//{slug}` identifier.
     * @param string $type Either `wp_template` or `wp_template_part`.
     * @return \WP_Block_Template|\WP_Error
     */
    protected function resolveTemplate( string $id, string $type ): \WP_Block_Template|\WP_Error {
        $template = get_block_template( $id, $type );

        if ( ! $template instanceof \WP_Block_Template ) {
            return new \WP_Error(
                'template_not_found',
                __( 'No template exists with that id.', 'wp-mcp-abilities' ),
                [ 'status' => 404 ]
            );
        }

        return $template;
    }

    /**
     * Reduces a template part area to one of the values WordPress accepts,
     * falling back to "uncategorized" otherwise (same behaviour as
     * `_filter_block_template_part_area()`, which this method wraps).
     *
     * @param string $area Requested area.
     */
    protected function validateArea( string $area ): string {
        return _filter_block_template_part_area( $area );
    }

    /**
     * Reads a value meant to be stored as-is, without the `sanitize_text_field()`
     * mangling `Input::string()` applies (which strips tags and collapses
     * whitespace, and would corrupt Gutenberg block markup).
     *
     * The value still goes through `wp_filter_post_kses()` inside
     * `wp_insert_post()`/`wp_update_post()` unless the current user has the
     * `unfiltered_html` capability - see the write abilities for how that is
     * surfaced to the caller.
     *
     * @param array<string, mixed> $input   Normalised input.
     * @param string               $key     Key to read.
     * @param string               $default Value returned when the key is missing.
     */
    protected function rawContent( array $input, string $key, string $default = '' ): string {
        if ( ! isset( $input[ $key ] ) || ! is_scalar( $input[ $key ] ) ) {
            return $default;
        }

        return (string) $input[ $key ];
    }

    /**
     * Shapes a `WP_Block_Template` for MCP output.
     *
     * @param \WP_Block_Template $template Template to format.
     * @return array<string, mixed>
     */
    protected function formatTemplate( \WP_Block_Template $template ): array {
        return [
            'id'             => $template->id,
            'slug'           => $template->slug,
            'theme'          => $template->theme,
            'type'           => $template->type,
            'title'          => $template->title,
            'description'    => $template->description,
            'content'        => $template->content,
            'source'         => $template->source,
            'origin'         => (string) ( $template->origin ?? '' ),
            'has_theme_file' => (bool) $template->has_theme_file,
            'wp_id'          => $template->wp_id ? (int) $template->wp_id : null,
            'is_custom'      => 'wp_template' === $template->type ? (bool) ( $template->is_custom ?? false ) : null,
            'area'           => 'wp_template_part' === $template->type ? ( $template->area ? $template->area : null ) : null,
            'status'         => $template->status,
        ];
    }

    /**
     * JSON schema of a formatted template.
     *
     * @return array<string, mixed>
     */
    protected function templateSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'id'             => [ 'type' => 'string' ],
                'slug'           => [ 'type' => 'string' ],
                'theme'          => [ 'type' => 'string' ],
                'type'           => [ 'type' => 'string' ],
                'title'          => [ 'type' => 'string' ],
                'description'    => [ 'type' => 'string' ],
                'content'        => [ 'type' => 'string' ],
                'source'         => [ 'type' => 'string' ],
                'origin'         => [ 'type' => 'string' ],
                'has_theme_file' => [ 'type' => 'boolean' ],
                'wp_id'          => [ 'type' => [ 'integer', 'null' ] ],
                'is_custom'      => [ 'type' => [ 'boolean', 'null' ] ],
                'area'           => [ 'type' => [ 'string', 'null' ] ],
                'status'         => [ 'type' => 'string' ],
            ],
        ];
    }

    /**
     * Input schema fragment shared by every ability of this namespace.
     *
     * @return array<string, mixed>
     */
    protected function typeProperty(): array {
        return [
            'type'        => 'string',
            'enum'        => self::templateTypes(),
            'default'     => 'wp_template',
            'description' => __(
                'Which kind of template to target: "wp_template" for a full page template or "wp_template_part" for a reusable part (header, footer, navigation overlay…).',
                'wp-mcp-abilities'
            ),
        ];
    }
}
