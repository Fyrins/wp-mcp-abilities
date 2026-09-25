<?php
/**
 * Lists the variations of a product.
 *
 * @package WpMcpAbilities\Abilities\WooCommerce
 */

namespace WpMcpAbilities\Abilities\WooCommerce;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Read access to the variations held by a variable product.
 */
class ListProductVariationsAbility extends AbstractAbility {
    use RestDelegation;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'list-product-variations' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'List product variations', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __(
            'Lists the variations of a WooCommerce variable product, with their attributes, prices and stock.',
            'wp-mcp-abilities'
        );
    }

    /**
     * @inheritDoc
     */
    public function getGroup(): string {
        return __( 'WooCommerce', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function isAvailable(): bool {
        return $this->isWooCommerceActive();
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array {
        $schema = $this->collectionParamsSchema( \WC_REST_Product_Variations_Controller::class );

        $schema['properties'] = [
            'product_id' => [
                'type'        => 'integer',
                'description' => __( 'Id of the parent product.', 'wp-mcp-abilities' ),
            ],
        ] + ( $schema['properties'] ?? [] );

        $schema['required'] = [ 'product_id' ];

        return $schema;
    }

    /**
     * @inheritDoc
     */
    public function getOutputSchema(): array {
        return $this->collectionSchema( \WC_REST_Product_Variations_Controller::class, 'variations' );
    }

    /**
     * @inheritDoc
     * @param mixed $input Raw input coming from the MCP adapter.
     */
    public function checkPermission( mixed $input = null ): bool {
        $input = Input::normalize( $input );

        return $this->canDispatch( 'GET', $this->route( $input ), $input );
    }

    /**
     * @inheritDoc
     * @param mixed $input Raw input coming from the MCP adapter.
     */
    public function execute( mixed $input = null ): array|\WP_Error {
        $input = Input::normalize( $input );

        /*
         * The Abilities API already refused the call if this returned false, and
         * the REST route checks again when the request is dispatched. The guard
         * is kept because every other family of the plugin carries it: one way
         * of writing a thing beats two, and it holds if a caller ever reaches
         * `execute()` without passing through the ability layer.
         */
        if ( ! $this->checkPermission( $input ) ) {
            return $this->forbidden();
        }

        $query = $input;
        unset( $query['product_id'] );

        return $this->dispatchCollection( $this->route( $input ), $query, 'variations' );
    }

    /**
     * Builds the route this ability targets.
     *
     * @param array<string, mixed> $input Normalised input.
     */
    private function route( array $input ): string {
        return $this->variationsRoute( Input::int( $input, 'product_id' ) );
    }
}
