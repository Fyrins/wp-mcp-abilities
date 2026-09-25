<?php
/**
 * Creates a variation on a variable product.
 *
 * @package WpMcpAbilities\Abilities\WooCommerce
 */

namespace WpMcpAbilities\Abilities\WooCommerce;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Creates one variation, with its own price, stock and reference.
 */
class CreateProductVariationAbility extends AbstractAbility {
    use RestDelegation;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'create-product-variation' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'Create a product variation', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __(
            'Creates a variation on a WooCommerce variable product. Each entry of "attributes" pairs an attribute of the parent with one of its values; an empty value means any. The parent must be of type variable and carry attributes marked for variation.',
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
        $schema = $this->routeSchema( \WC_REST_Product_Variations_Controller::class, \WP_REST_Server::CREATABLE );

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
        return $this->itemSchema( \WC_REST_Product_Variations_Controller::class );
    }

    /**
     * @inheritDoc
     * @param mixed $input Raw input coming from the MCP adapter.
     */
    public function checkPermission( mixed $input = null ): bool {
        $input = Input::normalize( $input );

        return $this->canDispatch( 'POST', $this->route( $input ), $input );
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

        $body = $input;
        unset( $body['product_id'] );

        return $this->dispatch( 'POST', $this->route( $input ), $body );
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
