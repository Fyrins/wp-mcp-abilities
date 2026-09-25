<?php
/**
 * Retrieves a single product variation.
 *
 * @package WpMcpAbilities\Abilities\WooCommerce
 */

namespace WpMcpAbilities\Abilities\WooCommerce;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Full read access to one variation.
 */
class GetProductVariationAbility extends AbstractAbility {
    use RestDelegation;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'get-product-variation' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'Get a product variation', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __(
            'Retrieves a WooCommerce product variation with its attributes, price, stock and dimensions.',
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
        $schema = [ 'type' => 'object' ];

        $identifiers = [
            'product_id'   => [
                'type'        => 'integer',
                'description' => __( 'Id of the parent product.', 'wp-mcp-abilities' ),
            ],
            'variation_id' => [
                'type'        => 'integer',
                'description' => __( 'Id of the variation.', 'wp-mcp-abilities' ),
            ],
        ];

        $schema['properties'] = $this->withFieldSelection( $identifiers );

        $schema['required'] = [ 'product_id', 'variation_id' ];

        return $schema;
    }

    /**
     * @inheritDoc
     */
    public function getOutputSchema(): array {
        return $this->itemSchema( \WC_REST_Product_Variations_Controller::class );
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
        unset( $query['product_id'], $query['variation_id'] );

        return $this->dispatch( 'GET', $this->route( $input ), $query );
    }

    /**
     * Builds the route this ability targets.
     *
     * @param array<string, mixed> $input Normalised input.
     */
    private function route( array $input ): string {
        return $this->variationsRoute( Input::int( $input, 'product_id' ), Input::int( $input, 'variation_id' ) );
    }
}
