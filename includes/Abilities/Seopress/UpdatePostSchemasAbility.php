<?php
/**
 * Replaces the SEOpress Pro Custom JSON-LD schemas of a post.
 *
 * @package WpMcpAbilities\Abilities\Seopress
 */

namespace WpMcpAbilities\Abilities\Seopress;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Capabilities;
use WpMcpAbilities\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Writes manual JSON-LD schemas to `_seopress_pro_schemas_manual`.
 *
 * The list passed in fully replaces the existing manual schemas; an empty
 * array clears them.
 */
class UpdatePostSchemasAbility extends AbstractAbility {
    use SeopressMetaMap;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'update-post-schemas-seopress' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'Update post SEOpress schemas', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __(
            'Replaces the SEOpress Pro Custom JSON-LD schemas of a post. Sending an empty array clears all manual schemas.',
            'wp-mcp-abilities'
        );
    }

    /**
     * @inheritDoc
     */
    public function getGroup(): string {
        return __( 'SEOpress', 'wp-mcp-abilities' );
    }

    /**
     * Custom JSON-LD schemas require SEOpress Pro, unlike the rest of the meta.
     *
     * @inheritDoc
     */
    public function isAvailable(): bool {
        return $this->isSeopressActive() && $this->isSeopressProActive();
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'post_id' => [
                    'type'        => 'integer',
                    'description' => __( 'The post ID.', 'wp-mcp-abilities' ),
                ],
                'schemas' => [
                    'type'        => 'array',
                    'description' => __(
                        'List of JSON-LD strings. Each entry is either a raw JSON object or a full <script type="application/ld+json"> tag. The full list replaces any existing manual schemas.',
                        'wp-mcp-abilities'
                    ),
                    'items'       => [ 'type' => 'string' ],
                ],
            ],
            'required'   => [ 'post_id', 'schemas' ],
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
                'count'   => [ 'type' => 'integer' ],
            ],
        ];
    }

    /**
     * @inheritDoc
     * @param mixed $input Raw input coming from the MCP adapter.
     */
    public function checkPermission( mixed $input = null ): bool {
        $input = Input::normalize( $input );
        $post  = $this->resolvePost( Input::int( $input, 'post_id' ) );

        if ( is_wp_error( $post ) ) {
            return false;
        }

        return Capabilities::canEditPost( $post );
    }

    /**
     * @inheritDoc
     * @param mixed $input Raw input coming from the MCP adapter.
     */
    public function execute( mixed $input = null ): array|\WP_Error {
        $input = Input::normalize( $input );
        $post  = $this->resolvePost( Input::int( $input, 'post_id' ) );

        if ( is_wp_error( $post ) ) {
            return $post;
        }

        if ( ! Capabilities::canEditPost( $post ) ) {
            return $this->forbidden();
        }

        if ( ! array_key_exists( 'schemas', $input ) || ! is_array( $input['schemas'] ) ) {
            return new \WP_Error(
                'invalid_schemas',
                __( 'Parameter "schemas" must be an array of JSON-LD strings.', 'wp-mcp-abilities' ),
                [ 'status' => 400 ]
            );
        }

        $schemas = Input::list( $input, 'schemas' );

        $entries = [];

        foreach ( $schemas as $index => $raw ) {
            if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
                return new \WP_Error(
                    'invalid_schema_entry',
                    sprintf(
                        /* translators: %d: schema index */
                        __( 'Schema #%d must be a non-empty string.', 'wp-mcp-abilities' ),
                        $index
                    ),
                    [ 'status' => 400 ]
                );
            }

            $jsonLd = $this->extractJsonLd( $raw );

            // A JSON-LD payload has no legitimate reason to contain a closing
            // </script> tag; reject it outright rather than let it reach wp_kses().
            if ( false !== stripos( $jsonLd, '</script' ) ) {
                return new \WP_Error(
                    'invalid_json_ld',
                    sprintf(
                        /* translators: %d: schema index */
                        __( 'Schema #%d must not contain a closing script tag.', 'wp-mcp-abilities' ),
                        $index
                    ),
                    [ 'status' => 400 ]
                );
            }

            $decoded = json_decode( $jsonLd, true );

            if ( JSON_ERROR_NONE !== json_last_error() ) {
                return new \WP_Error(
                    'invalid_json_ld',
                    sprintf(
                        /* translators: 1: schema index, 2: json error */
                        __( 'Schema #%1$d is not valid JSON: %2$s.', 'wp-mcp-abilities' ),
                        $index,
                        json_last_error_msg()
                    ),
                    [ 'status' => 400 ]
                );
            }

            // Re-encoding (rather than reusing the raw string) is the actual
            // fix: JSON_HEX_TAG turns every "<" and ">" into < / >,
            // so no amount of nesting can smuggle a </script><script> pair
            // back out through wp_kses(), which explicitly allows <script>.
            $safeJsonLd = (string) json_encode(
                $decoded,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            $entries[] = [
                '_seopress_pro_rich_snippets_type'   => 'custom',
                '_seopress_pro_rich_snippets_custom' => '<script type="application/ld+json">' . $safeJsonLd . '</script>',
            ];
        }

        if ( [] === $entries ) {
            delete_post_meta( $post->ID, '_seopress_pro_schemas_manual' );
        } else {
            update_post_meta( $post->ID, '_seopress_pro_schemas_manual', $entries );
        }

        return [
            'success' => true,
            'count'   => count( $entries ),
        ];
    }

    /**
     * Extracts the raw JSON-LD from a string that may wrap it in a <script> tag.
     *
     * @param string $raw Raw schema entry, either bare JSON-LD or a <script> wrapped snippet.
     * @return string
     */
    private function extractJsonLd( string $raw ): string {
        $raw = trim( $raw );

        if ( preg_match( '#<script[^>]*>(.*?)</script>#si', $raw, $matches ) ) {
            return trim( $matches[1] );
        }

        return $raw;
    }
}
