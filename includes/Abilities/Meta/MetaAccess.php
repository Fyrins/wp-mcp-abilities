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
     * Objects are rejected outright. Strings are then handled in one of two
     * ways, according to the key being written.
     *
     * - A key registered with its own `sanitize_callback` gets its strings
     *   untouched here: `update_metadata()` runs that callback through
     *   `sanitize_meta()`, and it knows the value better than a generic rule.
     *   Sanitising first would feed it an already altered value, which is how
     *   an URL used to lose its `%20` before `esc_url_raw()` could keep it.
     * - Any other key gets its strings stripped of tags and invalid UTF-8.
     *   `sanitize_text_field()` did that too, but also removed line breaks and
     *   every percent-encoded octet, so multiline values and encoded URLs came
     *   back altered while the call reported a success.
     *
     * @param mixed  $rawMetaValue Raw "meta_value" input value.
     * @param string $objectType   Meta object type, "post" or "term".
     * @param string $metaKey      Resolved meta key.
     * @param string $subtype      Post type or taxonomy of the object written to.
     * @return mixed|\WP_Error
     */
    protected function sanitizeMetaValue( mixed $rawMetaValue, string $objectType = '', string $metaKey = '', string $subtype = '' ): mixed {
        if ( null === $rawMetaValue || is_bool( $rawMetaValue ) || is_int( $rawMetaValue ) || is_float( $rawMetaValue ) ) {
            return $rawMetaValue;
        }

        $keepStrings = $this->hasOwnSanitizer( $objectType, $metaKey, $subtype );

        if ( is_string( $rawMetaValue ) ) {
            return $keepStrings ? $rawMetaValue : $this->sanitizeString( $rawMetaValue );
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

                $sanitized[ $key ] = is_string( $item ) && ! $keepStrings ? $this->sanitizeString( $item ) : $item;
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
     * Whether the key was registered with a sanitisation of its own.
     *
     * Read from what `register_meta()` recorded rather than from the
     * `sanitize_{$objectType}_meta_{$metaKey}` filter it hooks: a third party
     * may hook the same filter for logging or reshaping without neutralising
     * anything, and that must not turn the generic sanitising off. A key can
     * be registered for every object of its type or for one post type or
     * taxonomy only, so both registries are read.
     *
     * @param string $objectType Meta object type.
     * @param string $metaKey    Meta key.
     * @param string $subtype    Post type or taxonomy.
     */
    private function hasOwnSanitizer( string $objectType, string $metaKey, string $subtype ): bool {
        if ( '' === $objectType || '' === $metaKey ) {
            return false;
        }

        foreach ( array_unique( [ $subtype, '' ] ) as $objectSubtype ) {
            $registered = get_registered_meta_keys( $objectType, $objectSubtype );

            if ( isset( $registered[ $metaKey ]['sanitize_callback'] ) && is_callable( $registered[ $metaKey ]['sanitize_callback'] ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strips tags and invalid UTF-8 from a string, keeping its content as is.
     *
     * Line breaks, percent-encoded octets and surrounding spaces are left
     * alone: they are part of the value, not markup. A lone "<", as in
     * "a < b", is encoded first, as `sanitize_text_field()` does, so that
     * stripping tags does not swallow the text after it.
     *
     * @param string $value Raw string.
     */
    private function sanitizeString( string $value ): string {
        return wp_strip_all_tags( wp_pre_kses_less_than( wp_check_invalid_utf8( $value ) ) );
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
