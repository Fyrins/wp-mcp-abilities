<?php
/**
 * Per-request memo shared by the abilities.
 *
 * @package WpMcpAbilities\Support
 */

namespace WpMcpAbilities\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Holds for the length of a request what would otherwise be computed twice.
 *
 * Two things go through here: the endpoint arguments of a REST controller, whose
 * reading costs an instantiation, and the permission verdicts, which the
 * Abilities API and the guard at the top of `execute()` ask for one after the
 * other. A static local to a trait method would not do: PHP gives each using
 * class its own copy, so every ability would pay the price while a shared
 * comment claimed otherwise. The class lives under `Support`, which the
 * container leaves alone, since abilities must stay instantiable without
 * arguments and cannot receive it by injection.
 */
class RequestCache {
    /**
     * Values already computed, keyed by caller.
     *
     * @var array<string, mixed>
     */
    private static array $values = [];

    /**
     * Returns a value, computing it once per request.
     *
     * @param string   $key      Cache key.
     * @param callable $callback Computes the value when it is not cached yet.
     * @return mixed
     */
    public static function remember( string $key, callable $callback ): mixed {
        if ( ! array_key_exists( $key, self::$values ) ) {
            self::$values[ $key ] = $callback();
        }

        return self::$values[ $key ];
    }

    /**
     * Empties the store.
     *
     * Only useful to tests and to a long-running process replaying requests.
     */
    public static function flush(): void {
        self::$values = [];
    }
}
