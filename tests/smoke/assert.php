<?php
/**
 * Minimal assertion helper for smoke scripts run through `wp eval-file`.
 *
 * @package WpMcpAbilities
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput

if ( ! function_exists( 'wpmcpa_assert' ) ) {
    /**
     * Prints the outcome and records failures.
     *
     * @param bool   $condition Expected to be true.
     * @param string $label     What is being checked.
     */
    function wpmcpa_assert( bool $condition, string $label ): void {
        $GLOBALS['wpmcpa_failures'] = $GLOBALS['wpmcpa_failures'] ?? 0;
        if ( $condition ) {
            fwrite( STDOUT, "  ok   {$label}\n" );
            return;
        }
        ++$GLOBALS['wpmcpa_failures'];
        fwrite( STDERR, "  FAIL {$label}\n" );
    }

    /**
     * Exits non-zero when any assertion failed.
     */
    function wpmcpa_assert_done(): void {
        exit( empty( $GLOBALS['wpmcpa_failures'] ) ? 0 : 1 );
    }
}
