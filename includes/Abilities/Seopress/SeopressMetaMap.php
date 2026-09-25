<?php
/**
 * Shared SEOpress meta map, casting and schemas for post and term abilities.
 *
 * @package WpMcpAbilities\Abilities\Seopress
 */

namespace WpMcpAbilities\Abilities\Seopress;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for the SEOpress meta keys touched by abilities.
 *
 * Post and term abilities share most of the same fields, the same "yes"/""
 * boolean encoding and the same URL sanitisation, so both the map and the
 * cast logic live here once instead of being duplicated across four methods.
 */
trait SeopressMetaMap {
    /**
     * SEOpress post meta fields, keyed by MCP input key.
     *
     * Each entry describes the underlying meta key and how the value must be
     * cast on write / read.
     *
     * @return array<string, array{meta: string, type: string}>
     */
    protected function postSeoFields(): array {
        return [
            'meta_title'           => [ 'meta' => '_seopress_titles_title', 'type' => 'text' ],
            'meta_description'     => [ 'meta' => '_seopress_titles_desc', 'type' => 'text' ],
            'focus_keyword'        => [ 'meta' => '_seopress_analysis_target_kw', 'type' => 'text' ],
            'canonical_url'        => [ 'meta' => '_seopress_robots_canonical', 'type' => 'url' ],
            'noindex'              => [ 'meta' => '_seopress_robots_index', 'type' => 'bool' ],
            'nofollow'             => [ 'meta' => '_seopress_robots_follow', 'type' => 'bool' ],
            'nosnippet'            => [ 'meta' => '_seopress_robots_snippet', 'type' => 'bool' ],
            'noimageindex'         => [ 'meta' => '_seopress_robots_imageindex', 'type' => 'bool' ],
            'primary_category'     => [ 'meta' => '_seopress_robots_primary_cat', 'type' => 'primary_category' ],
            'breadcrumb_title'     => [ 'meta' => '_seopress_robots_breadcrumbs', 'type' => 'text' ],
            'og_title'             => [ 'meta' => '_seopress_social_fb_title', 'type' => 'text' ],
            'og_description'       => [ 'meta' => '_seopress_social_fb_desc', 'type' => 'text' ],
            'og_image'             => [ 'meta' => '_seopress_social_fb_img', 'type' => 'url' ],
            'twitter_title'        => [ 'meta' => '_seopress_social_twitter_title', 'type' => 'text' ],
            'twitter_description'  => [ 'meta' => '_seopress_social_twitter_desc', 'type' => 'text' ],
            'twitter_image'        => [ 'meta' => '_seopress_social_twitter_img', 'type' => 'url' ],
            'redirect_enabled'     => [ 'meta' => '_seopress_redirections_enabled', 'type' => 'bool' ],
            'redirect_type'        => [ 'meta' => '_seopress_redirections_type', 'type' => 'redirect_type' ],
            'redirect_url'         => [ 'meta' => '_seopress_redirections_value', 'type' => 'url' ],
        ];
    }

    /**
     * SEOpress term meta fields, keyed by MCP input key.
     *
     * Subset of {@see self::postSeoFields()}: terms have no focus keyword,
     * primary category, breadcrumb title or redirection settings.
     *
     * @return array<string, array{meta: string, type: string}>
     */
    protected function termSeoFields(): array {
        return [
            'meta_title'          => [ 'meta' => '_seopress_titles_title', 'type' => 'text' ],
            'meta_description'    => [ 'meta' => '_seopress_titles_desc', 'type' => 'text' ],
            'canonical_url'       => [ 'meta' => '_seopress_robots_canonical', 'type' => 'url' ],
            'noindex'             => [ 'meta' => '_seopress_robots_index', 'type' => 'bool' ],
            'nofollow'            => [ 'meta' => '_seopress_robots_follow', 'type' => 'bool' ],
            'og_title'            => [ 'meta' => '_seopress_social_fb_title', 'type' => 'text' ],
            'og_description'      => [ 'meta' => '_seopress_social_fb_desc', 'type' => 'text' ],
            'og_image'            => [ 'meta' => '_seopress_social_fb_img', 'type' => 'url' ],
            'twitter_title'       => [ 'meta' => '_seopress_social_twitter_title', 'type' => 'text' ],
            'twitter_description' => [ 'meta' => '_seopress_social_twitter_desc', 'type' => 'text' ],
            'twitter_image'       => [ 'meta' => '_seopress_social_twitter_img', 'type' => 'url' ],
        ];
    }

    /**
     * Builds the meta values to persist from the normalised MCP input.
     *
     * Only keys actually present in the input are returned, so a partial
     * update never overwrites fields the caller did not mention. The
     * returned array is flat (input key => cast value) so it can be handed
     * to the `wpmcpa_*_seo_data` filters as-is, matching the
     * legacy shape integrators already rely on.
     *
     * @param array<string, mixed>                             $input  Normalised MCP input.
     * @param array<string, array{meta: string, type: string}> $fields Field map from
     *                                                                 {@see self::postSeoFields()} or
     *                                                                 {@see self::termSeoFields()}.
     * @return array<string, string|int>
     */
    protected function buildSeoWriteData( array $input, array $fields ): array {
        $data = [];

        foreach ( $fields as $key => $definition ) {
            if ( ! isset( $input[ $key ] ) ) {
                continue;
            }

            $data[ $key ] = $this->castSeoValueForWrite( $definition['type'], $input[ $key ] );
        }

        return $data;
    }

    /**
     * Persists a flat set of cast SEO values, produced by {@see self::buildSeoWriteData()}
     * and possibly filtered, using the meta keys from the field map.
     *
     * @param int                                              $objectId    Post or term ID.
     * @param array<string, string|int>                        $seoData     Cast values, keyed by MCP input key.
     * @param array<string, array{meta: string, type: string}> $fields      Field map.
     * @param callable(int, string, string|int): void          $metaWriter Meta writer, e.g. `update_post_meta( $id, $key, $value )`.
     */
    protected function persistSeoData( int $objectId, array $seoData, array $fields, callable $metaWriter ): void {
        foreach ( $seoData as $key => $value ) {
            if ( isset( $fields[ $key ] ) ) {
                $metaWriter( $objectId, $fields[ $key ]['meta'], $value );
            }
        }
    }

    /**
     * Casts a single input value according to its SEOpress field type.
     *
     * @param string $type  One of 'bool', 'url', 'redirect_type', 'primary_category', 'text'.
     * @param mixed  $value Raw value coming from the normalised input.
     * @return string|int
     */
    private function castSeoValueForWrite( string $type, mixed $value ): string|int {
        return match ( $type ) {
            'bool'             => ( $value ? 'yes' : '' ),
            'url'              => sanitize_url( (string) $value ),
            'redirect_type'    => ( in_array( (int) $value, [ 301, 302, 307 ], true ) ? (int) $value : 301 ),
            'primary_category' => ( 'none' === $value ? 'none' : (string) (int) $value ),
            default            => sanitize_text_field( (string) $value ),
        };
    }

    /**
     * Reads and casts the SEOpress meta of an object back into MCP output shape.
     *
     * @param int                                              $objectId   Post or term ID.
     * @param array<string, array{meta: string, type: string}> $fields     Field map.
     * @param callable(int, string): mixed                     $metaReader Meta reader, e.g. `get_post_meta( $id, $key, true )`.
     * @return array<string, mixed>
     */
    protected function readSeoData( int $objectId, array $fields, callable $metaReader ): array {
        $result = [];

        foreach ( $fields as $key => $definition ) {
            $raw = $metaReader( $objectId, $definition['meta'] );

            $result[ $key ] = match ( $definition['type'] ) {
                'bool'          => 'yes' === $raw,
                'redirect_type' => $raw ? (int) $raw : null,
                default         => '' === $raw ? null : $raw,
            };
        }

        return $result;
    }

    /**
     * Whether SEOpress (free) is active.
     */
    protected function isSeopressActive(): bool {
        return defined( 'SEOPRESS_VERSION' );
    }

    /**
     * Whether SEOpress Pro is active, required for manual JSON-LD schemas.
     */
    protected function isSeopressProActive(): bool {
        return defined( 'SEOPRESS_PRO_VERSION' );
    }

    /**
     * Input schema properties shared by the post and term SEO write abilities.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function seoWriteSchemaProperties(): array {
        return [
            'meta_title'           => [ 'type' => 'string', 'description' => __( 'SEOpress meta title.', 'wp-mcp-abilities' ) ],
            'meta_description'     => [ 'type' => 'string', 'description' => __( 'SEOpress meta description.', 'wp-mcp-abilities' ) ],
            'canonical_url'        => [ 'type' => 'string', 'description' => __( 'The canonical URL.', 'wp-mcp-abilities' ) ],
            'noindex'              => [ 'type' => 'boolean', 'description' => __( 'Set noindex.', 'wp-mcp-abilities' ) ],
            'nofollow'             => [ 'type' => 'boolean', 'description' => __( 'Set nofollow.', 'wp-mcp-abilities' ) ],
            'og_title'             => [ 'type' => 'string', 'description' => __( 'Open Graph title (Facebook/social).', 'wp-mcp-abilities' ) ],
            'og_description'       => [ 'type' => 'string', 'description' => __( 'Open Graph description (Facebook/social).', 'wp-mcp-abilities' ) ],
            'og_image'             => [ 'type' => 'string', 'description' => __( 'Open Graph image URL (Facebook/social).', 'wp-mcp-abilities' ) ],
            'twitter_title'        => [ 'type' => 'string', 'description' => __( 'Twitter/X card title.', 'wp-mcp-abilities' ) ],
            'twitter_description'  => [ 'type' => 'string', 'description' => __( 'Twitter/X card description.', 'wp-mcp-abilities' ) ],
            'twitter_image'        => [ 'type' => 'string', 'description' => __( 'Twitter/X card image URL.', 'wp-mcp-abilities' ) ],
        ];
    }

    /**
     * Output schema properties shared by the post and term SEO read abilities.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function seoReadSchemaProperties(): array {
        return [
            'meta_title'          => [ 'type' => [ 'string', 'null' ] ],
            'meta_description'    => [ 'type' => [ 'string', 'null' ] ],
            'canonical_url'       => [ 'type' => [ 'string', 'null' ] ],
            'noindex'             => [ 'type' => 'boolean' ],
            'nofollow'            => [ 'type' => 'boolean' ],
            'og_title'            => [ 'type' => [ 'string', 'null' ] ],
            'og_description'      => [ 'type' => [ 'string', 'null' ] ],
            'og_image'            => [ 'type' => [ 'string', 'null' ] ],
            'twitter_title'       => [ 'type' => [ 'string', 'null' ] ],
            'twitter_description' => [ 'type' => [ 'string', 'null' ] ],
            'twitter_image'       => [ 'type' => [ 'string', 'null' ] ],
        ];
    }
}
