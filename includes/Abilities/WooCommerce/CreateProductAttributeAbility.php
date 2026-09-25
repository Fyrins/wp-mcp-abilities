<?php
/**
 * Creates a global product attribute.
 *
 * @package WpMcpAbilities\Abilities\WooCommerce
 */

namespace WpMcpAbilities\Abilities\WooCommerce;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Creates a global attribute, the shared vocabulary a variable product draws its variations from.
 */
class CreateProductAttributeAbility extends AbstractAbility {
    use RestDelegation;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'create-product-attribute' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'Create a product attribute', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __(
            'Creates a global WooCommerce product attribute. Its terms are created along the way when a product is given values for it.',
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
        $schema = $this->routeSchema( \WC_REST_Product_Attributes_Controller::class, \WP_REST_Server::CREATABLE );

        $schema['required'] = [ 'name' ];

        return $schema;
    }

    /**
     * @inheritDoc
     */
    public function getOutputSchema(): array {
        return $this->itemSchema( \WC_REST_Product_Attributes_Controller::class );
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

        $result = $this->dispatch( 'POST', $this->route( $input ), $input );

        if ( ! is_wp_error( $result ) ) {
            AttributeTaxonomyBootstrap::register();
        }

        return $result;
    }

    /**
     * Builds the route this ability targets.
     *
     * @param array<string, mixed> $input Normalised input.
     */
    private function route( array $input ): string {
        return $this->attributesRoute();
    }
}
