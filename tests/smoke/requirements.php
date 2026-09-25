<?php
/**
 * Without the MCP Adapter the plugin still works and only warns.
 *
 * @package WpMcpAbilities
 */

require __DIR__ . '/assert.php';

use WpMcpAbilities\Support\Requirements;

if ( Requirements::hasMcpAdapter() ) {
    fwrite( STDOUT, "  skip the MCP Adapter is active\n" );
    exit( 0 );
}

wpmcpa_assert( ! Requirements::hasMcpAdapter(), 'testbed runs without the adapter' );
wpmcpa_assert( [] !== array_filter( array_keys( wp_get_abilities() ), static fn ( $n ) => str_starts_with( $n, 'wp-mcp-abilities/' ) ), 'abilities are registered anyway' );

$missing = Requirements::missing();
wpmcpa_assert( 1 === count( $missing ), 'only the adapter is reported missing' );
wpmcpa_assert( 'warning' === ( $missing[0]['severity'] ?? null ), 'adapter is a warning' );
wpmcpa_assert( Requirements::MCP_ADAPTER_URL === ( $missing[0]['url'] ?? null ), 'adapter notice links to the releases' );

$adapter_folder = is_dir( WP_PLUGIN_DIR . '/mcp-adapter' );
if ( $adapter_folder ) {
    wpmcpa_assert( 'wp plugin activate mcp-adapter' === ( $missing[0]['command'] ?? null ), 'adapter folder present: the command activates it' );
} else {
    wpmcpa_assert( ! array_key_exists( 'command', $missing[0] ), 'adapter folder absent: no command is suggested' );
}

require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';

/**
 * Renders the notice as it would print on the given admin screen.
 *
 * @param string $screen Screen id.
 * @return string
 */
function wpmcpa_render_notice_on( string $screen ): string {
    set_current_screen( $screen );
    ob_start();
    ( new WpMcpAbilities\Hooks\RequirementsNotice() )->render();
    return (string) ob_get_clean();
}

wp_set_current_user( 1 );
$html = wpmcpa_render_notice_on( 'plugins' );
wpmcpa_assert( str_contains( $html, 'notice-warning' ), 'notice is rendered as a warning on the Plugins screen' );
wpmcpa_assert( ! str_contains( $html, 'inactive' ), 'notice does not claim the plugin is inactive' );
wpmcpa_assert( str_contains( $html, 'href="' . esc_url( Requirements::MCP_ADAPTER_URL ) . '"' ), 'notice links to the adapter' );
wpmcpa_assert( $adapter_folder === str_contains( $html, '<code>' ), 'the command is printed only when there is one' );

// wp-admin/includes/menu.php maps the Settings menu to the `settings` hook name.
$GLOBALS['admin_page_hooks']['options-general.php'] = 'settings';
$html = wpmcpa_render_notice_on( 'settings_page_wp-mcp-abilities' );
wpmcpa_assert( str_contains( $html, 'notice-warning' ), 'notice is rendered on the plugin settings screen' );

$html = wpmcpa_render_notice_on( 'dashboard' );
wpmcpa_assert( '' === trim( $html ), 'the adapter warning is not rendered on the dashboard' );

wpmcpa_assert_done();
