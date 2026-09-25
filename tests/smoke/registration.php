<?php
/**
 * The plugin boots on its own wiring and registers its abilities.
 *
 * @package WpMcpAbilities
 */

require __DIR__ . '/assert.php';

wpmcpa_assert( ! defined( 'WPMCPA_KERNEL_NAME' ), 'no kernel constant is defined' );
wpmcpa_assert( class_exists( 'WpMcpAbilities\Plugin' ), 'Plugin class autoloads' );
wpmcpa_assert( null !== wp_get_ability_category( 'wp-mcp-abilities' ), 'category is registered' );

$names = array_keys( wp_get_abilities() );
$ours  = array_values( array_filter( $names, static fn ( $n ) => str_starts_with( $n, 'wp-mcp-abilities/' ) ) );

foreach ( [ 'list-media', 'get-term', 'list-templates', 'replace-in-post-content', 'get-post-meta' ] as $slug ) {
    wpmcpa_assert( in_array( "wp-mcp-abilities/{$slug}", $ours, true ), "{$slug} is registered" );
}
wpmcpa_assert( in_array( 'wp-mcp-abilities/list-posts', $ours, true ), 'post type abilities are registered' );

// Integration abilities follow the same detection as the code, so this holds on
// a bare testbed as well as on one running WooCommerce or SEOPress.
$settings     = new WpMcpAbilities\Services\SettingsService();
$woocommerce  = defined( 'WC_VERSION' ) && function_exists( 'wc_get_product' );
$seopress     = defined( 'SEOPRESS_VERSION' );
$seopress_pro = $seopress && defined( 'SEOPRESS_PRO_VERSION' );
$integrations = [
    'create-product'               => [ $woocommerce, true ],
    'update-product'               => [ $woocommerce, true ],
    'create-product-attribute'     => [ $woocommerce, true ],
    'list-product-attributes'      => [ $woocommerce, true ],
    'create-product-variation'     => [ $woocommerce, true ],
    'get-product-variation'        => [ $woocommerce, true ],
    'list-product-variations'      => [ $woocommerce, true ],
    'update-product-variation'     => [ $woocommerce, true ],
    'delete-product-variation'     => [ $woocommerce, false ],
    'get-post-seopress'            => [ $seopress, true ],
    'get-term-seopress'            => [ $seopress, true ],
    'update-post-seopress'         => [ $seopress, true ],
    'update-term-seopress'         => [ $seopress, true ],
    'update-post-schemas-seopress' => [ $seopress_pro, true ],
];
foreach ( $integrations as $slug => [ $available, $enabled_by_default ] ) {
    $name     = "wp-mcp-abilities/{$slug}";
    $expected = $available && $settings->isEnabled( $name, $enabled_by_default );
    wpmcpa_assert(
        $expected === in_array( $name, $ours, true ),
        sprintf( '%s is %s', $slug, $expected ? 'registered' : 'left out' )
    );
}

$ability = wp_get_ability( 'wp-mcp-abilities/list-media' );
$meta    = $ability ? $ability->get_meta() : [];
wpmcpa_assert( 'wp-mcp-abilities' === ( $meta['wpmcpa']['plugin'] ?? null ), 'meta marker is set' );

fwrite( STDOUT, sprintf( "  info %d abilities registered under wp-mcp-abilities/\n", count( $ours ) ) );
wpmcpa_assert_done();
