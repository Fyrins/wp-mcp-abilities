<?php
/**
 * Disabling an ability in the settings removes it from the registry.
 *
 * `wp eval-file` runs after `init`, so each check re-reads a fresh process:
 * the option is written, then a sub-process lists the abilities.
 *
 * @package WpMcpAbilities
 */

require __DIR__ . '/assert.php';

$target  = 'wp-mcp-abilities/list-media';
$list    = static function (): array {
    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
    $out = shell_exec( 'wp eval "echo wp_json_encode( array_keys( wp_get_abilities() ) );" 2>/dev/null' );
    return (array) json_decode( (string) $out, true );
};
$initial = get_option( 'wpmcpa_enabled', null );

update_option( 'wpmcpa_enabled', array_merge( (array) $initial, [ $target => false ] ) );
$disabled_list = $list();
wpmcpa_assert( [] !== $disabled_list, 'ability list was read' );
wpmcpa_assert( ! in_array( $target, $disabled_list, true ), 'disabled ability is not registered' );

update_option( 'wpmcpa_enabled', array_merge( (array) $initial, [ $target => true ] ) );
wpmcpa_assert( in_array( $target, $list(), true ), 're-enabled ability is registered' );

null === $initial ? delete_option( 'wpmcpa_enabled' ) : update_option( 'wpmcpa_enabled', $initial );
wpmcpa_assert_done();
