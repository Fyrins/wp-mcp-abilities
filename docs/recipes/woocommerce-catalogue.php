<?php
/**
 * Manual acceptance recipe for the WooCommerce abilities.
 *
 * The plugin ships no test suite: this recipe stands in for one for the WooCommerce family, and
 * must be replayed after every change that touches it. It calls the abilities through
 * `wp_get_ability()`, so it covers the logic, the schemas and the permissions, but not the MCP
 * transport layer.
 *
 * Usage, on a site where WooCommerce is active:
 *
 *     wp eval-file docs/recipes/woocommerce-catalogue.php --user=1
 *
 * It creates a global attribute and a variable product with six variations, checks the parent's
 * price range, then deletes what it created. Nothing outlives its run.
 *
 * @package WpMcpAbilities
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

/**
 * Runs an ability and reports what came back.
 *
 * A closure rather than a function: this file is included in the global scope by
 * `wp eval-file`, where a named function would sit outside the plugin namespace.
 *
 * @var callable $run
 */
$run = static function ( string $ability, array $input ): ?array {
    $object = wp_get_ability( $ability );

    if ( ! $object ) {
        echo 'MISSING: ' . $ability . "\n";

        return null;
    }

    $result = $object->execute( $input );

    if ( is_wp_error( $result ) ) {
        echo 'ERROR ' . $ability . ': ' . $result->get_error_code() . ': ' . $result->get_error_message() . "\n";

        return null;
    }

    return $result;
};

echo "--- Registered abilities ---\n";

$expected = [
    'create-product',
    'update-product',
    'list-product-variations',
    'get-product-variation',
    'create-product-variation',
    'update-product-variation',
    'list-product-attributes',
    'create-product-attribute',
];

foreach ( $expected as $slug ) {
    $name = 'wp-mcp-abilities/' . $slug;
    echo ( wp_has_ability( $name ) ? 'OK      ' : 'MISSING ' ) . ' ' . $name . "\n";
}

echo "\nVariation deletion is switched off by default: its absence is expected.\n";
echo "Reading, listing and deleting a product belong to WooCommerce since its 10.9,\n";
echo "under the names woocommerce/products-query, product-delete and their neighbours.\n";

echo "\n--- Global attribute and variable product, in the same request ---\n";

$attribute = $run(
    'wp-mcp-abilities/create-product-attribute',
    [
        'name' => 'Recipe size',
        'slug' => 'recipe-size',
        'type' => 'select',
    ]
);

$product = $run(
    'wp-mcp-abilities/create-product',
    [
        'name'   => 'Recipe catalog',
        'type'   => 'variable',
        'status' => 'draft',
    ]
);

if ( ! $attribute || ! $product ) {
    echo "stopping: the starting objects were not created\n";

    return;
}

$attributeId = (int) $attribute['id'];
$productId   = (int) $product['id'];

echo 'attribute ' . $attributeId . ', product ' . $productId . "\n";

echo "\n--- Product attributes, one global and one local ---\n";

$updated = $run(
    'wp-mcp-abilities/update-product',
    [
        'product_id' => $productId,
        'attributes' => [
            [
                'id'        => $attributeId,
                'options'   => [ 'Small', 'Medium', 'Large' ],
                'variation' => true,
                'visible'   => true,
            ],
            [
                'name'      => 'Background',
                'options'   => [ 'White', 'Black' ],
                'variation' => true,
                'visible'   => true,
            ],
        ],
    ]
);

foreach ( $updated['attributes'] ?? [] as $setAttribute ) {
    echo '  ' . $setAttribute['name'] . ': ' . implode( ', ', $setAttribute['options'] ) . "\n";
}

echo "\nAn empty attribute here would mean the taxonomy created in this request was not\n";
echo "registered: WooCommerce only reads pa_* taxonomies on init.\n";

echo "\n--- Six variations, at two prices ---\n";

foreach ( [ 'Small' => '4', 'Medium' => '4', 'Large' => '8' ] as $size => $price ) {
    foreach ( [ 'White', 'Black' ] as $background ) {
        $variation = $run(
            'wp-mcp-abilities/create-product-variation',
            [
                'product_id'    => $productId,
                'regular_price' => $price,
                'stock_status'  => 'onbackorder',
                'attributes'    => [
                    [ 'id' => $attributeId, 'option' => $size ],
                    [ 'name' => 'Background', 'option' => $background ],
                ],
            ]
        );

        if ( $variation ) {
            echo '  variation ' . $variation['id'] . ': ' . $size . ' / ' . $background . ' at ' . $variation['regular_price'] . "\n";
        }
    }
}

echo "\n--- Reading back and syncing the parent ---\n";

$list   = $run( 'wp-mcp-abilities/list-product-variations', [ 'product_id' => $productId, 'per_page' => 20 ] );
$parent = wc_get_product( $productId );

echo 'variations listed: ' . ( $list['total'] ?? 0 ) . "\n";
echo 'parent price range: ' . $parent->get_variation_price( 'min' ) . ' to ' . $parent->get_variation_price( 'max' ) . "\n";
echo "expected: 6 variations, from 4.00 to 8.00\n";

echo "\n--- Expected refusals ---\n";

$nonexistent = wp_get_ability( 'wp-mcp-abilities/update-product' )->execute(
    [
        'product_id' => 999999999,
        'name'       => 'Product that does not exist',
    ]
);
echo 'write on a nonexistent product: ' . ( is_wp_error( $nonexistent ) ? $nonexistent->get_error_code() : 'ACCEPTED, UNEXPECTED' ) . "\n";
echo "expected: woocommerce_rest_product_invalid_id, WooCommerce's business error rather than a permission refusal\n";

$missingVariation = wp_get_ability( 'wp-mcp-abilities/get-product-variation' )->execute(
    [
        'product_id'   => 999999999,
        'variation_id' => 999999998,
    ]
);
echo 'read of a nonexistent variation: ' . ( is_wp_error( $missingVariation ) ? $missingVariation->get_error_code() : 'ACCEPTED, UNEXPECTED' ) . "\n";
echo "expected: ability_invalid_permissions. WooCommerce refuses a missing object from its permission\n";
echo "check, and the Abilities API never passes such an error on, so as not to reveal to someone\n";
echo "without the rights what they must not learn. An agent therefore sees a refusal, not an absence.\n";

/*
 * An observation rather than a check: WooCommerce answers an empty collection for the variations
 * of a product that does not exist, where a single read refuses. An agent that lists cannot
 * therefore infer from the absence of results that the parent exists.
 */
$emptyList = wp_get_ability( 'wp-mcp-abilities/list-product-variations' )->execute( [ 'product_id' => 999999999 ] );
echo 'listing the variations of a nonexistent product: ';
echo is_wp_error( $emptyList ) ? $emptyList->get_error_code() . "\n" : 'empty collection, total ' . var_export( $emptyList['total'] ?? null, true ) . "\n";

add_role( 'wpmcpa_recipe', 'Recipe', [ 'read' => true ] );
$userId = wp_insert_user(
    [
        'user_login' => 'wpmcpa_recipe_' . wp_rand( 1000, 9999 ),
        'user_pass'  => wp_generate_password(),
        'role'       => 'wpmcpa_recipe',
    ]
);

wp_set_current_user( $userId );
$refusal = wp_get_ability( 'wp-mcp-abilities/create-product' )->execute( [ 'name' => 'Forbidden' ] );
echo 'creation without the right: ' . ( is_wp_error( $refusal ) ? $refusal->get_error_code() : 'ACCEPTED, UNEXPECTED' ) . "\n";
wp_set_current_user( 1 );

wp_delete_user( $userId );
remove_role( 'wpmcpa_recipe' );

echo "\n--- Updating a variation ---\n";

$first = ( $list['variations'][0]['id'] ?? 0 );

if ( $first ) {
    $changed = $run(
        'wp-mcp-abilities/update-product-variation',
        [
            'product_id'    => $productId,
            'variation_id'  => (int) $first,
            'regular_price' => '5',
        ]
    );

    echo 'variation ' . $first . ': price ' . ( $changed['regular_price'] ?? 'unchanged' ) . ", expected 5\n";
}

echo "\n--- Integrator filter ---\n";

$denyAll = static fn (): bool => false;
add_filter( 'wpmcpa_check_permission', $denyAll );
$underFilter = wp_get_ability( 'wp-mcp-abilities/list-product-variations' )->execute( [ 'product_id' => $productId ] );
remove_filter( 'wpmcpa_check_permission', $denyAll );

echo 'read under a filter that denies everything: ';
echo ( is_wp_error( $underFilter ) ? $underFilter->get_error_code() : 'ACCEPTED, UNEXPECTED' ) . "\n";
echo "expected: an error, the filter having the last word over WooCommerce's verdict\n";

echo "\n--- Destructive abilities, switched off by default ---\n";

echo 'registered: ';
echo wp_has_ability( 'wp-mcp-abilities/delete-product-variation' ) ? "YES, UNEXPECTED\n" : "no, as expected\n";

$deletion           = new \WpMcpAbilities\Abilities\WooCommerce\DeleteProductVariationAbility();
$throwawayVariation = $run(
    'wp-mcp-abilities/create-product-variation',
    [
        'product_id'    => $productId,
        'regular_price' => '9',
        'attributes'    => [
            [ 'id' => $attributeId, 'option' => 'Large' ],
            [ 'name' => 'Background', 'option' => '' ],
        ],
    ]
);

if ( $throwawayVariation ) {
    $deleted = $deletion->execute(
        [
            'product_id'   => $productId,
            'variation_id' => (int) $throwawayVariation['id'],
        ]
    );

    echo 'direct deletion of variation ' . $throwawayVariation['id'] . ': ';
    echo ( is_wp_error( $deleted ) ? 'ERROR ' . $deleted->get_error_code() : 'done' ) . "\n";

    /*
     * WooCommerce's object cache keeps the variation for the length of the
     * request: without this purge, it seems to outlive its own deletion.
     */
    clean_post_cache( (int) $throwawayVariation['id'] );

    echo 'the variation still exists: ' . ( get_post( (int) $throwawayVariation['id'] ) ? "YES, UNEXPECTED\n" : "no\n" );
}

echo "\n--- Unknown field ---\n";

$withIntruder = $run(
    'wp-mcp-abilities/update-product',
    [
        'product_id'    => $productId,
        'unknown_field' => 'ignored',
        'status'        => 'draft',
    ]
);

echo 'a field outside the schema does not make the call fail: ' . ( $withIntruder ? 'confirmed' : 'NO, TO INVESTIGATE' ) . "\n";

echo "\n--- Malformed identifier ---\n";

$malformed = wp_get_ability( 'wp-mcp-abilities/list-product-variations' )->execute( [ 'product_id' => [ $productId ] ] );

echo 'a product_id passed as an array: ';
echo ( is_wp_error( $malformed ) ? $malformed->get_error_code() : 'ACCEPTED, UNEXPECTED' ) . "\n";
echo "expected: a refusal, and above all not a read of the variations of product 1\n";

echo "\n--- Field selection ---\n";

$reduced = $run(
    'wp-mcp-abilities/list-product-variations',
    [
        'product_id' => $productId,
        'per_page'   => 5,
        '_fields'    => 'id,regular_price',
    ]
);

$firstVariation = $reduced['variations'][0] ?? [];

echo 'keys returned: ' . implode( ', ', array_keys( $firstVariation ) ) . "\n";
echo "expected: id and regular_price, nothing else\n";

echo "\n--- Cleanup ---\n";

foreach ( $parent->get_children() as $childId ) {
    wc_get_product( $childId )->delete( true );
}

$parent->delete( true );
wc_delete_attribute( $attributeId );

echo "recipe product, variations and attribute deleted\n";
