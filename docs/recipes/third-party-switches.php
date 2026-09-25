<?php
/**
 * Acceptance recipe for the switches on other plugins' abilities.
 *
 * The plugin ships no test suite: this recipe stands in for one for this family, and must be
 * replayed after every change that touches it.
 *
 * Usage, on a site running at least one third-party plugin that registers abilities:
 *
 *     wp eval-file docs/recipes/third-party-switches.php
 *
 * It switches off a third-party ability, then dispatches two REST requests to check that the
 * removal only happens on MCP routes. Dispatching goes through `rest_do_request()`, which fires
 * `rest_pre_dispatch` exactly as an HTTP request does: the real chain is measured, hook wiring
 * included, not just a call to the removal method. The MCP route does not even need to exist,
 * since `rest_pre_dispatch` fires before the route is resolved.
 *
 * The in-process registry stays trimmed after the recipe runs, as it does not register again what
 * it had removed. The option, however, is restored to its initial state.
 *
 * @package WpMcpAbilities
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

$catalog  = new \WpMcpAbilities\Services\ThirdPartyAbilityCatalog();
$settings = new \WpMcpAbilities\Services\SettingsService();
$key      = \WpMcpAbilities\Services\SettingsService::OPTION_KEY;

/**
 * Dispatches a REST request and returns.
 *
 * @var callable $dispatch
 */
$dispatch = static function ( string $route ): void {
    rest_do_request( new \WP_REST_Request( 'POST', $route ) );
};

echo "--- Inventory ---\n";

foreach ( $catalog->grouped() as $namespace => $abilities ) {
    echo '  ' . $namespace . ': ' . count( $abilities ) . " abilities\n";
}

$inventory = $catalog->all();

echo '  total: ' . count( $inventory ) . "\n";

if ( [] === $inventory ) {
    echo "no third-party ability on this site, nothing to check\n";

    return;
}

echo "\n--- What the catalog must not contain ---\n";

$names = array_keys( $inventory );

foreach ( [ 'core/', 'mcp-adapter/' ] as $prefix ) {
    $found = array_filter( $names, static fn ( string $name ): bool => str_starts_with( $name, $prefix ) );

    echo '  ' . $prefix . ': ' . ( $found ? "PRESENT, UNEXPECTED\n" : "absent, as expected\n" );
}

/*
 * Our abilities are recognised by their mark, not by their prefix: a `wp-mcp-abilities/` ability
 * registered by another plugin must, on the contrary, appear in this list.
 */
echo '  wp-mcp-abilities/list-posts: ' . ( isset( $inventory['wp-mcp-abilities/list-posts'] ) ? "PRESENT, UNEXPECTED\n" : "absent, as expected\n" );

$target    = $names[0];
$neighbour = $names[1] ?? null;

echo "\n--- Switching off " . $target . " ---\n";

$option            = get_option( $key, [] );
$initial           = $option;
$option[ $target ] = false;
update_option( $key, $option );

echo '  setting read back: ' . var_export( $settings->isEnabled( $target, true ), true ) . " (expected false)\n";
echo '  still listed by the catalog: ' . ( isset( $catalog->all()[ $target ] ) ? "yes, as expected\n" : "NO: the screen could not tick it again\n" );

/*
 * Snapshot before any removal: an ability the adapter never registered in this context would
 * otherwise be counted as removed, and the recipe would wrongly blame the plugin. From the command
 * line, the adapter does not always register its own abilities.
 */
$witnesses = [];

foreach ( [ 'wp-mcp-abilities/list-posts', 'mcp-adapter/execute-ability', 'mcp-adapter/discover-abilities' ] as $witness ) {
    $witnesses[ $witness ] = wp_has_ability( $witness );
}

echo "\n--- After an ordinary REST request ---\n";

$dispatch( '/wp/v2/types' );

echo '  ' . $target . ': ' . ( wp_has_ability( $target ) ? "present, as expected\n" : "REMOVED, UNEXPECTED: the removal spills beyond MCP\n" );

echo "\n--- After a request on an MCP route ---\n";

$dispatch( '/mcp/mcp-adapter-default-server' );

echo '  ' . $target . ': ' . ( wp_has_ability( $target ) ? "PRESENT, UNEXPECTED\n" : "removed, as expected\n" );

if ( $neighbour ) {
    echo '  ' . $neighbour . ': ' . ( wp_has_ability( $neighbour ) ? "present, as expected\n" : "REMOVED, UNEXPECTED\n" );
}

foreach ( [ 'wp-mcp-abilities/list-posts', 'mcp-adapter/execute-ability' ] as $protected ) {
    if ( ! $witnesses[ $protected ] ) {
        echo '  ' . $protected . ": never registered in this context, nothing to conclude\n";

        continue;
    }

    echo '  ' . $protected . ': ' . ( wp_has_ability( $protected ) ? "present, as expected\n" : "REMOVED, UNEXPECTED\n" );
}

echo "\n--- Listing through discover-abilities ---\n";

/*
 * The registry does not tell the whole story: what the agent sees goes through the adapter's
 * discovery tool. An MCP server freezing its tool list before the dispatch would let it show up.
 */
if ( ! $witnesses['mcp-adapter/discover-abilities'] ) {
    echo "  the adapter did not register its discovery tool here: check with an MCP client\n";
} else {
    $list   = wp_get_ability( 'mcp-adapter/discover-abilities' )->execute( [] );
    $listed = is_array( $list ) ? (string) wp_json_encode( $list ) : '';

    echo '  ' . $target . ': ' . ( str_contains( $listed, $target ) ? "LISTED, UNEXPECTED\n" : "absent, as expected\n" );
}

echo "\n--- Call through execute-ability ---\n";

if ( ! $witnesses['mcp-adapter/execute-ability'] ) {
    echo "  the adapter did not register its executor here: check with an MCP client\n";
} else {
    $response = wp_get_ability( 'mcp-adapter/execute-ability' )->execute(
        [
            'ability_name' => $target,
            'parameters'   => [],
        ]
    );

    echo '  ' . ( is_wp_error( $response ) ? 'refused: ' . $response->get_error_code() : 'ACCEPTED, UNEXPECTED' ) . "\n";
}

echo "\n--- Back to the initial state ---\n";

/*
 * The target is set back to true explicitly, rather than simply removed from the option.
 * `register_setting` hooks the screen's validation to every write of this option: it starts from
 * what is stored and sets back to false every listed ability the payload does not mention, since a
 * form never sends an unticked checkbox. Removing the key would therefore bring it back to false.
 * The screen does send the ticked checkbox, and does not have this problem.
 */
$initial[ $target ] = true;

update_option( $key, $initial );

echo '  setting read back: ' . var_export( $settings->isEnabled( $target, true ), true ) . " (expected true)\n";
echo "  the key stays in the option, set to true: the validation cannot return to the unset state\n";
