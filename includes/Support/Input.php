<?php
/**
 * Input normalisation helpers shared by every ability.
 *
 * @package WpMcpAbilities\Support
 */

namespace WpMcpAbilities\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the loosely typed MCP payload into predictable PHP values.
 */
class Input {
    /**
     * Normalises the raw MCP input into an associative array.
     *
     * The adapter may hand over an object, an array or a JSON string. Anything
     * that cannot be decoded into an array yields an empty array rather than
     * null, so callers never have to guard against a TypeError.
     *
     * @param mixed $input Raw input coming from the MCP adapter.
     * @return array<string, mixed>
     */
    public static function normalize( mixed $input ): array {
        if ( is_array( $input ) ) {
            return $input;
        }

        if ( is_object( $input ) ) {
            return get_object_vars( $input );
        }

        if ( is_string( $input ) && '' !== trim( $input ) ) {
            $decoded = json_decode( $input, true );

            return is_array( $decoded ) ? $decoded : [];
        }

        return [];
    }

    /**
     * Reads an integer from the input.
     *
     * Anything that is not a scalar number falls back on the default rather than
     * being converted. PHP casts a non-empty array to 1 without a warning, so an
     * identifier arriving as an array, from a malformed agent payload or a bug
     * upstream in the MCP bridge, would silently point at the object with id 1.
     * On a delete or an update, that is a different object than the one asked
     * for. The abilities validate their input through their schema, but the
     * helper does not rely on a caller having done so.
     *
     * @param array<string, mixed> $input   Normalised input.
     * @param string               $key     Key to read.
     * @param int                  $default Value returned when the key is missing or unusable.
     * @return int
     */
    public static function int( array $input, string $key, int $default = 0 ): int {
        if ( ! isset( $input[ $key ] ) || ! is_scalar( $input[ $key ] ) || ! is_numeric( $input[ $key ] ) ) {
            return $default;
        }

        return (int) $input[ $key ];
    }

    /**
     * Reads a sanitised string from the input.
     *
     * For the parameters that name something — a slug, a status, a meta key, a
     * date. Use `literal()` instead when the parameter carries markup the
     * caller expects back byte for byte.
     *
     * @param array<string, mixed> $input   Normalised input.
     * @param string               $key     Key to read.
     * @param string               $default Value returned when the key is missing.
     * @return string
     */
    public static function string( array $input, string $key, string $default = '' ): string {
        if ( ! isset( $input[ $key ] ) || ! is_scalar( $input[ $key ] ) ) {
            return $default;
        }

        return sanitize_text_field( (string) $input[ $key ] );
    }

    /**
     * Reads a string from the input exactly as it was sent.
     *
     * The counterpart of `string()`, for the callers whose parameter *is* the
     * payload rather than a label: a literal needle to look for in block
     * markup, the replacement that goes in its place. `sanitize_text_field()`
     * strips tags and HTML comments, collapses whitespace and drops newlines,
     * so putting it in front of such a parameter does not defend anything, it
     * rewrites the request: a needle opening on `<` comes back empty, a needle
     * carrying attributes comes back without them, and a needle anchored on a
     * block boundary loses the newline it was anchored to.
     *
     * Sanitising is the wrong tool here in the first place. These values are
     * never echoed; they travel into `post_content`, which the abilities hand
     * to `wp_update_post()` slashed, where kses filters what the current user
     * is not allowed to write. The capability check has already run by then.
     *
     * The value is *not* unslashed either. The MCP adapter serves abilities
     * over the REST API, whose parameters are decoded from JSON and reach us
     * unslashed, exactly as every other write in this plugin already assumes
     * when it casts `content` straight to a string. Unslashing here would eat
     * one level of backslashes the caller actually sent, turning the `\u002d`
     * escapes block attributes are full of into a bare `u002d` — the very
     * corruption the `wp_slash()` calls downstream exist to prevent.
     *
     * @param array<string, mixed> $input   Normalised input.
     * @param string               $key     Key to read.
     * @param string               $default Value returned when the key is missing or not a scalar.
     * @return string
     */
    public static function literal( array $input, string $key, string $default = '' ): string {
        if ( ! isset( $input[ $key ] ) || ! is_scalar( $input[ $key ] ) ) {
            return $default;
        }

        return (string) $input[ $key ];
    }

    /**
     * Tells whether the input carries a usable scalar under a key.
     *
     * Lets a caller separate "the parameter never came" from "the parameter
     * came and is an empty string", two situations that deserve two different
     * errors.
     *
     * @param array<string, mixed> $input Normalised input.
     * @param string               $key   Key to read.
     * @return bool
     */
    public static function hasScalar( array $input, string $key ): bool {
        return isset( $input[ $key ] ) && is_scalar( $input[ $key ] );
    }

    /**
     * Reads a boolean from the input.
     *
     * @param array<string, mixed> $input   Normalised input.
     * @param string               $key     Key to read.
     * @param bool                 $default Value returned when the key is missing.
     * @return bool
     */
    public static function bool( array $input, string $key, bool $default = false ): bool {
        return isset( $input[ $key ] ) ? (bool) rest_sanitize_boolean( $input[ $key ] ) : $default;
    }

    /**
     * Reads a list from the input.
     *
     * @param array<string, mixed> $input Normalised input.
     * @param string               $key   Key to read.
     * @return array<int, mixed>
     */
    public static function list( array $input, string $key ): array {
        if ( ! isset( $input[ $key ] ) || ! is_array( $input[ $key ] ) ) {
            return [];
        }

        return array_values( $input[ $key ] );
    }
}
