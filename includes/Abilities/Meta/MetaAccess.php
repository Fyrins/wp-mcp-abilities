<?php
/**
 * Shared allowlisting and validation for meta abilities.
 *
 * @package WpMcpAbilities\Abilities\Meta
 */

namespace WpMcpAbilities\Abilities\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Guards which meta keys abilities are allowed to read, write or delete.
 *
 * The legacy implementation called `update_post_meta()` / `update_term_meta()`
 * with whatever key the MCP client supplied, with no filtering at all: a caller
 * could overwrite `_wp_page_template`, `_thumbnail_id`, or any meta key owned by
 * a third-party plugin that drives access-control logic. This trait closes that
 * hole by refusing every protected meta key unless it has been explicitly
 * allowlisted by the site.
 */
trait MetaAccess {
    /**
     * Meta keys a site explicitly allows abilities to touch, even though
     * WordPress flags them as protected.
     *
     * Empty by default: every protected meta key is refused until a site opts
     * it in through the `wpmcpa_allowed_meta_keys` filter. Public
     * (non-protected) meta keys are always allowed and never need to be listed.
     *
     * Example, exposing `_thumbnail_id` to the post meta abilities only:
     *
     *     add_filter(
     *         'wpmcpa_allowed_meta_keys',
     *         function ( array $keys, string $objectType ) {
     *             if ( 'post' === $objectType ) {
     *                 $keys[] = '_thumbnail_id';
     *             }
     *
     *             return $keys;
     *         },
     *         10,
     *         2
     *     );
     *
     * @param string $objectType Object type being targeted, 'post' or 'term'.
     * @return array<int, string>
     */
    protected function getAllowedMetaKeys( string $objectType ): array {
        /**
         * Filters the protected meta keys abilities are allowed to touch.
         *
         * @param array<int, string> $keys       Allowed protected meta keys, empty by default.
         * @param string             $objectType Object type being targeted, 'post' or 'term'.
         */
        return (array) apply_filters( 'wpmcpa_allowed_meta_keys', [], $objectType );
    }

    /**
     * Resolves and validates a meta key against the protected-meta allowlist.
     *
     * @param string $objectType  Object type being targeted, 'post' or 'term'.
     * @param mixed  $rawMetaKey  Raw "meta_key" input value.
     * @return string|\WP_Error
     */
    protected function resolveMetaKey( string $objectType, mixed $rawMetaKey ): string|\WP_Error {
        if ( ! is_scalar( $rawMetaKey ) ) {
            return new \WP_Error(
                'meta_key_not_allowed',
                __( 'The "meta_key" parameter is required.', 'wp-mcp-abilities' ),
                [ 'status' => 403 ]
            );
        }

        $metaKey = sanitize_text_field( (string) $rawMetaKey );

        if ( '' === $metaKey ) {
            return new \WP_Error(
                'meta_key_not_allowed',
                __( 'The "meta_key" parameter is required.', 'wp-mcp-abilities' ),
                [ 'status' => 403 ]
            );
        }

        if ( is_protected_meta( $metaKey, $objectType ) && ! in_array( $metaKey, $this->getAllowedMetaKeys( $objectType ), true ) ) {
            return new \WP_Error(
                'meta_key_not_allowed',
                sprintf(
                    /* translators: %s: meta key name. */
                    __( 'The "%s" meta key is protected and has not been allowlisted for MCP abilities.', 'wp-mcp-abilities' ),
                    $metaKey
                ),
                [ 'status' => 403 ]
            );
        }

        return $metaKey;
    }

    /**
     * Sanitises a meta value, restricting it to scalars or arrays of scalars.
     *
     * The legacy implementation stored whatever value the MCP client sent,
     * including arbitrary arrays or objects. Objects are rejected outright and
     * every string, in a scalar or in a list, is passed through
     * `sanitize_text_field()`.
     *
     * @param mixed $rawMetaValue Raw "meta_value" input value.
     * @return mixed|\WP_Error
     */
    protected function sanitizeMetaValue( mixed $rawMetaValue ): mixed {
        if ( null === $rawMetaValue || is_bool( $rawMetaValue ) || is_int( $rawMetaValue ) || is_float( $rawMetaValue ) ) {
            return $rawMetaValue;
        }

        if ( is_string( $rawMetaValue ) ) {
            return sanitize_text_field( $rawMetaValue );
        }

        if ( is_array( $rawMetaValue ) ) {
            $sanitized = [];

            foreach ( $rawMetaValue as $key => $item ) {
                if ( null !== $item && ! is_scalar( $item ) ) {
                    return new \WP_Error(
                        'invalid_meta_value',
                        __( 'The "meta_value" parameter only accepts scalars or arrays of scalars.', 'wp-mcp-abilities' ),
                        [ 'status' => 400 ]
                    );
                }

                $sanitized[ $key ] = is_string( $item ) ? sanitize_text_field( $item ) : $item;
            }

            return $sanitized;
        }

        return new \WP_Error(
            'invalid_meta_value',
            __( 'The "meta_value" parameter only accepts scalars or arrays of scalars.', 'wp-mcp-abilities' ),
            [ 'status' => 400 ]
        );
    }

    /**
     * Keeps only the meta entries the caller is allowed to read.
     *
     * Used when no `meta_key` is given: everything WordPress flags as protected
     * is dropped unless the site allowlisted it, so listing can never become a
     * way around `resolveMetaKey()`.
     *
     * @param string                      $objectType Either "post" or "term".
     * @param array<string, array<mixed>> $meta       Raw meta, as returned by get_*_meta().
     * @return array<string, mixed>
     */
    protected function filterReadableMeta( string $objectType, array $meta ): array {
        $allowed  = $this->getAllowedMetaKeys( $objectType );
        $readable = [];

        foreach ( $meta as $key => $values ) {
            if ( is_protected_meta( $key, $objectType ) && ! in_array( $key, $allowed, true ) ) {
                continue;
            }

            $readable[ $key ] = is_array( $values ) && 1 === count( $values )
                ? reset( $values )
                : $values;
        }

        return $readable;
    }

    /**
     * JSON schema fragment describing an accepted or returned meta value.
     *
     * @return array<string, mixed>
     */
    protected function metaValueSchema(): array {
        return [
            'description' => __(
                'The meta value. Accepts a string, number or boolean, or an array of such scalars; objects are rejected.',
                'wp-mcp-abilities'
            ),
            'anyOf'       => [
                [ 'type' => 'string' ],
                [ 'type' => 'number' ],
                [ 'type' => 'boolean' ],
                [
                    'type'  => 'array',
                    'items' => [
                        'type' => [ 'string', 'number', 'boolean' ],
                    ],
                ],
            ],
        ];
    }
}
