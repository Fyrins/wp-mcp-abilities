<?php
/**
 * Hands the product operations over to whoever describes them best.
 *
 * @package WpMcpAbilities\Hooks
 */

namespace WpMcpAbilities\Hooks;

use WpMcpAbilities\Contracts\HookInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Narrows the generic post type CRUD on `product` when WooCommerce runs.
 *
 * Two abilities able to create a product would force the agent to pick one,
 * with even odds of choosing the one that drops the price. The generic pair
 * steps aside for the writing, which the dedicated classes take over under the
 * very same names.
 *
 * The reading is a separate question. Since 10.9 WooCommerce registers a
 * catalogue of its own — `woocommerce/products-query`, `product-delete` and
 * their siblings — which reads a product with its price and its stock, where
 * the generic pair only ever sees a post. Duplicating those would put two
 * competing answers in front of the agent, so the generic reading steps aside
 * too. Below 10.9 there is nothing to step aside for, and it stays: a partial
 * view beats no view at all.
 *
 * A site without WooCommerce keeps today's behaviour untouched.
 */
class WooCommerceProductTypes implements HookInterface {
    /**
     * First WooCommerce version registering canonical product abilities.
     *
     * Read from the version rather than from the registry on purpose. Asking
     * `wp_has_ability()` here would depend on WooCommerce having registered
     * before this runs, which nothing guarantees: both hang on the same action,
     * at the same priority, and the order is the order they were added in.
     *
     * @see https://developer.woocommerce.com/2026/05/12/mcp-abilities-api-10-9/
     */
    private const CANONICAL_ABILITIES_SINCE = '10.9';

    /**
     * Operations the dedicated WooCommerce abilities of this plugin take over.
     *
     * @var string[]
     */
    private const TAKEN_OVER_HERE = [ 'create', 'update' ];

    /**
     * Operations the canonical WooCommerce catalogue takes over.
     *
     * @var string[]
     */
    private const TAKEN_OVER_BY_WOOCOMMERCE = [ 'list', 'get', 'delete' ];

    /**
     * @inheritDoc
     */
    public function hooks(): void {
        add_filter( 'wpmcpa_post_type_operations', [ $this, 'releaseProductOperations' ], 10, 2 );
    }

    /**
     * Drops the product operations somebody else describes better.
     *
     * @param array<int, string> $operations Operations about to be generated.
     * @param \WP_Post_Type      $postType   Post type they are generated for.
     * @return array<int, string>
     */
    public function releaseProductOperations( array $operations, \WP_Post_Type $postType ): array {
        if ( 'product' !== $postType->name || ! $this->isWooCommerceActive() ) {
            return $operations;
        }

        $released = self::TAKEN_OVER_HERE;

        if ( $this->hasCanonicalAbilities() ) {
            $released = array_merge( $released, self::TAKEN_OVER_BY_WOOCOMMERCE );
        }

        return array_values( array_diff( $operations, $released ) );
    }

    /**
     * Whether WooCommerce is running on this site.
     */
    private function isWooCommerceActive(): bool {
        return defined( 'WC_VERSION' ) && function_exists( 'wc_get_product' );
    }

    /**
     * Whether WooCommerce ships its own product abilities.
     */
    private function hasCanonicalAbilities(): bool {
        return version_compare( (string) WC_VERSION, self::CANONICAL_ABILITIES_SINCE, '>=' );
    }
}
