<?php
/**
 * Repairs the WooCommerce attribute lifecycle inside a single request.
 *
 * @package WpMcpAbilities\Abilities\WooCommerce
 */

namespace WpMcpAbilities\Abilities\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Makes attribute taxonomies born in this request usable right away.
 *
 * This is not REST delegation, which is why it lives outside `RestDelegation`:
 * it works around an ordering problem in WooCommerce itself. WooCommerce fills
 * `$wc_product_attributes` and registers the `pa_*` taxonomies on `init`, from
 * the database. An attribute created later in the same request is in neither, so
 * assigning options to it is dropped without a word: the product keeps the
 * attribute and loses its values. Each MCP call is normally its own HTTP
 * request, which hides the problem, but JSON-RPC allows batching and a silent
 * data loss is not worth the gamble.
 */
class AttributeTaxonomyBootstrap {
    /**
     * Capabilities WooCommerce gives its own attribute taxonomies.
     *
     * Registering without them would fall back on `manage_categories` and
     * `edit_posts`, looser than what the shop uses, and the term checks made
     * during the rest of the request would answer against the wrong set.
     *
     * @see \WC_Post_Types::register_taxonomies()
     *
     * @var array<string, string>
     */
    private const CAPABILITIES = [
        'manage_terms' => 'manage_product_terms',
        'edit_terms'   => 'edit_product_terms',
        'delete_terms' => 'delete_product_terms',
        'assign_terms' => 'assign_product_terms',
    ];

    /**
     * Registers every attribute taxonomy the current request does not know yet.
     */
    public static function register(): void {
        if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
            return;
        }

        global $wc_product_attributes;

        foreach ( wc_get_attribute_taxonomies() as $attribute ) {
            $name = wc_attribute_taxonomy_name( $attribute->attribute_name );

            $wc_product_attributes[ $name ] = $attribute;

            if ( taxonomy_exists( $name ) ) {
                continue;
            }

            register_taxonomy(
                $name,
                [ 'product' ],
                [
                    'hierarchical' => false,
                    'show_ui'      => false,
                    'query_var'    => true,
                    'rewrite'      => false,
                    'capabilities' => self::CAPABILITIES,
                ]
            );
        }
    }
}
