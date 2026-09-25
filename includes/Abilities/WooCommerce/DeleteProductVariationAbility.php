<?php
/**
 * Deletes a product variation.
 *
 * @package WpMcpAbilities\Abilities\WooCommerce
 */

namespace WpMcpAbilities\Abilities\WooCommerce;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes one variation for good. There is no trash for variations, the WooCommerce admin does not offer one either. Disabled by default, like every destructive ability of the plugin.
 */
class DeleteProductVariationAbility extends AbstractAbility {
    use RestDelegation;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'delete-product-variation' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'Delete a product variation', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __(
            'Permanently deletes a WooCommerce product variation.',
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
    public function isEnabledByDefault(): bool {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array {
        $schema = [ 'type' => 'object' ];

        $schema['properties'] = [
            'product_id' => [
                'type'        => 'integer',
                'description' => __( 'Id of the parent product.', 'wp-mcp-abilities' ),
            ],
            'variation_id' => [
                'type'        => 'integer',
                'description' => __( 'Id of the variation to delete.', 'wp-mcp-abilities' ),
            ],
        ];

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

        return $this->canDispatch( 'DELETE', $this->route( $input ), $input );
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

        return $this->dispatch( 'DELETE', $this->route( $input ), [ 'force' => true ] );
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
