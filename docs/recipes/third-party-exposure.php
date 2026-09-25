<?php
/**
 * Acceptance recipe for exposing other plugins' abilities.
 *
 * The plugin ships no test suite: this recipe stands in for one for this family, and must be
 * replayed after every change that touches it.
 *
 * Usage, on a site running at least one third-party plugin whose abilities are not meant for MCP,
 * SEOPress 10 for instance:
 *
 *     wp eval-file docs/recipes/third-party-exposure.php
 *
 * It ticks a closed ability and checks what the plugin declares to the MCP server, by applying the
 * filter the adapter itself applies to its default server configuration. What this recipe cannot
 * do is check that an agent sees the tool: that takes an MCP client on a site exposing a server,
 * and is done by hand.
 *
 * @package WpMcpAbilities
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

$catalog     = new \WpMcpAbilities\Services\ThirdPartyAbilityCatalog();
$settings    = new \WpMcpAbilities\Services\SettingsService();
$exposureKey = \WpMcpAbilities\Services\SettingsService::EXPOSED_OPTION_KEY;

/**
 * Returns the list of tools the default server would receive.
 *
 * @var callable $tools
 */
$tools = static function (): array {
    $config = apply_filters(
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- MCP Adapter filter, applied here as the adapter applies it.
        'mcp_adapter_default_server_config',
        [
            'server_id' => 'mcp-adapter-default-server',
            'tools'     => [
                'mcp-adapter/discover-abilities',
                'mcp-adapter/get-ability-info',
                'mcp-adapter/execute-ability',
            ],
        ]
    );

    return is_array( $config ) && isset( $config['tools'] ) ? (array) $config['tools'] : [];
};

echo "--- Third-party abilities, by state ---\n";

$closed = [];
$open   = [];

foreach ( $catalog->all() as $name => $ability ) {
    if ( $catalog->isExposedToMcp( $ability ) ) {
        $open[] = $name;

        continue;
    }

    $closed[] = $name;
}

echo '  reachable through their own declaration: ' . count( $open ) . "\n";
echo '  closed, so exposable here: ' . count( $closed ) . "\n";

if ( [] === $closed ) {
    echo "no closed ability on this site, nothing to check\n";

    return;
}

$target    = $closed[0];
$neighbour = $closed[1] ?? null;

echo "\n--- Before any exposure ---\n";
echo '  ' . $target . ' exposed: ' . var_export( $settings->isExposed( $target ), true ) . " (expected false)\n";
echo '  present in the tools: ' . ( in_array( $target, $tools(), true ) ? "YES, UNEXPECTED\n" : "no, as expected\n" );

echo "\n--- After ticking " . $target . " ---\n";

$initial = get_option( $exposureKey, [] );
$initial = is_array( $initial ) ? $initial : [];
$state   = $initial;

$state[ $target ] = true;
remove_all_filters( 'sanitize_option_' . $exposureKey );
update_option( $exposureKey, $state );

$toolList = $tools();

echo '  ' . $target . ': ' . ( in_array( $target, $toolList, true ) ? "declared to the server, as expected\n" : "MISSING, UNEXPECTED\n" );

if ( $neighbour ) {
    echo '  ' . $neighbour . ' (not ticked): ' . ( in_array( $neighbour, $toolList, true ) ? "PRESENT, UNEXPECTED\n" : "absent, as expected\n" );
}

echo '  the three adapter tools are kept: ';
echo count( array_intersect( [ 'mcp-adapter/discover-abilities', 'mcp-adapter/get-ability-info', 'mcp-adapter/execute-ability' ], $toolList ) ) === 3
    ? "yes, as expected\n"
    : "NO, UNEXPECTED\n";

if ( [] !== $open ) {
    echo '  an already reachable ability is not declared again: ';
    echo in_array( $open[0], $toolList, true ) ? "PRESENT, UNEXPECTED\n" : "absent, as expected\n";
}

echo "\n--- Disabling wins over exposure ---\n";

$switchKey       = \WpMcpAbilities\Services\SettingsService::OPTION_KEY;
$switches        = get_option( $switchKey, [] );
$switches        = is_array( $switches ) ? $switches : [];
$initialSwitches = $switches;

$switches[ $target ] = false;
remove_all_filters( 'sanitize_option_' . $switchKey );
update_option( $switchKey, $switches );

echo '  ' . $target . ' ticked but switched off: ';
echo in_array( $target, $tools(), true ) ? "DECLARED, UNEXPECTED\n" : "not declared, as expected\n";

update_option( $switchKey, $initialSwitches );

echo "\n--- Back to the initial state ---\n";

update_option( $exposureKey, $initial );

echo '  ' . $target . ' exposed: ' . var_export( $settings->isExposed( $target ), true ) . " (expected false)\n";
echo '  present in the tools: ' . ( in_array( $target, $tools(), true ) ? "YES, UNEXPECTED\n" : "no, as expected\n" );

echo "\nStill to check with an MCP client, on a site exposing a server: the tool shows up in\n";
echo "tools/list once ticked, and answers tools/call.\n";
