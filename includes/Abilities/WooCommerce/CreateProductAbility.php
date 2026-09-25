<?php
/**
 * Creates a product.
 *
 * @package WpMcpAbilities\Abilities\WooCommerce
 */

namespace WpMcpAbilities\Abilities\WooCommerce;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Creates a WooCommerce product of any catalogue type.
 */
class CreateProductAbility extends AbstractAbility {
    use RestDelegation;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'create-product' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'Create a product', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __(
            'Creates a WooCommerce product. Accepts every field of the REST API: type, price and dated sale, stock, references, dimensions, taxonomies, images, attributes and default attributes. Prices set on a variable product are derived from its variations.',
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
        $schema = $this->routeSchema( \WC_REST_Products_Controller::class, \WP_REST_Server::CREATABLE );

        $schema['required'] = [ 'name' ];

        return $schema;
    }

    /**
     * @inheritDoc
     */
    public function getOutputSchema(): array {
        return $this->itemSchema( \WC_REST_Products_Controller::class );
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

        return $this->dispatch( 'POST', $this->route( $input ), $input );
    }

    /**
     * Builds the route this ability targets.
     *
     * @param array<string, mixed> $input Normalised input.
     */
    private function route( array $input ): string {
        return $this->productsRoute();
    }
}
