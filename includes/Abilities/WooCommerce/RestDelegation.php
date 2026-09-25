<?php
/**
 * Hands the catalogue abilities over to the WooCommerce REST controllers.
 *
 * @package WpMcpAbilities\Abilities\WooCommerce
 */

namespace WpMcpAbilities\Abilities\WooCommerce;

use WpMcpAbilities\Support\Capabilities;
use WpMcpAbilities\Support\RequestCache;

defined( 'ABSPATH' ) || exit;

/**
 * Dispatch, schema derivation and error translation shared by the catalogue.
 *
 * The abilities of this family describe no product field of their own. They name
 * a route, hand the input over to `rest_do_request()` and translate what comes
 * back. WooCommerce owns the schemas, the business rules and the capability
 * checks, and keeps them in step with its own releases; the plugin owns the MCP
 * surface. A field added to the REST API shows up here without a line of code.
 */
trait RestDelegation {
    /**
     * Namespace of the WooCommerce REST API the catalogue talks to.
     *
     * Written once here rather than in every ability: a move to another version
     * of the API is a single line, not a sweep through the family. A method
     * rather than a constant: traits only accept constants from PHP 8.2.
     *
     * @return string
     */
    private static function routeNamespace(): string {
        return '/wc/v3';
    }


    /**
     * Route of the product collection, or of one product.
     *
     * @param int|null $productId Product id, or null for the collection.
     */
    protected function productsRoute( ?int $productId = null ): string {
        return self::routeNamespace() . '/products' . ( null === $productId ? '' : '/' . $productId );
    }

    /**
     * Route of the variations of a product, or of one variation.
     *
     * @param int      $productId   Parent product id.
     * @param int|null $variationId Variation id, or null for the collection.
     */
    protected function variationsRoute( int $productId, ?int $variationId = null ): string {
        return $this->productsRoute( $productId ) . '/variations' . ( null === $variationId ? '' : '/' . $variationId );
    }

    /**
     * Route of the global product attributes.
     */
    protected function attributesRoute(): string {
        return self::routeNamespace() . '/products/attributes';
    }

    /**
     * Whether WooCommerce is running on this site.
     *
     * The constant alone would not prove the plugin is active: WooCommerce may
     * sit in the Composer tree without being switched on, in which case its REST
     * controllers are never registered.
     */
    protected function isWooCommerceActive(): bool {
        return defined( 'WC_VERSION' ) && function_exists( 'wc_get_product' );
    }

    /**
     * Runs a request against a WooCommerce REST route.
     *
     * @param string               $method HTTP verb.
     * @param string               $route  Fully built route, ids included.
     * @param array<string, mixed> $input  Parameters to send.
     * @return array<string, mixed>|\WP_Error
     */
    protected function dispatch( string $method, string $route, array $input ): array|\WP_Error {
        if ( ! $this->isWooCommerceActive() ) {
            return $this->wooCommerceMissing();
        }

        $request = $this->buildRequest( $method, $route, $input );
        $response = $this->trim( rest_do_request( $request ), $request );

        if ( $response->is_error() ) {
            /*
             * WooCommerce answers with a business code such as
             * `woocommerce_rest_product_invalid_id`. Passing it through beats
             * rewording it: the caller gets the vocabulary of the API it is
             * really talking to.
             */
            return $response->as_error();
        }

        $data = $response->get_data();

        return is_array( $data ) ? $this->prune( $data ) : [ 'result' => $data ];
    }

    /**
     * Whether the current user may call a route, without running it.
     *
     * Resolves the route the way the REST server does, then asks its own
     * `permission_callback`. The verdict finally goes through the plugin filter,
     * so an integrator keeps the last word.
     *
     * @param string               $method HTTP verb.
     * @param string               $route  Fully built route, ids included.
     * @param array<string, mixed> $input  Parameters to send.
     */
    protected function canDispatch( string $method, string $route, array $input ): bool {
        if ( ! $this->isWooCommerceActive() ) {
            return false;
        }

        /*
         * The Abilities API asks first, the guard at the top of `execute()` asks
         * again. Resolving a route means walking the whole table with a regex per
         * entry, so the answer is held for the request. The current user is part
         * of the key: a JSON-RPC batch may switch identity between two calls.
         */
        $key = 'permission:' . $method . ':' . $route . ':' . get_current_user_id() . ':' . md5( (string) wp_json_encode( $input ) );

        return (bool) RequestCache::remember(
            $key,
            fn (): bool => $this->resolvePermission( $method, $route, $input )
        );
    }

    /**
     * Asks the route itself whether the current user may call it.
     *
     * Resolving the route by hand would drift from the server: the core keeps
     * the first handler whose method matches, even without a
     * `permission_callback`, and only looks at the namespaces the path belongs
     * to. A verdict taken on a different handler than the one
     * `rest_do_request()` would run is the kind of gap nobody notices until a
     * plugin registers a competing route, so the server does the resolving.
     *
     * `rest_request_before_callbacks` hands over the resolved handler, and fires
     * before the permission is evaluated: the filter grabs the handler and
     * returns an error, which stops the request before anything is created,
     * updated or deleted. The permission is then evaluated here, on the very
     * handler the server picked, following the two rules of the core: no
     * `permission_callback` means allowed, otherwise strictly true.
     *
     * @param string               $method HTTP verb.
     * @param string               $route  Fully built route, ids included.
     * @param array<string, mixed> $input  Parameters to send.
     */
    private function resolvePermission( string $method, string $route, array $input ): bool {
        [ $resolved, $probed ] = $this->probe( $method, $route, $input );

        if ( null === $resolved ) {
            /*
             * The server validates and sanitises before the filter the probe
             * hangs on, so malformed input stops the request short of it and
             * leaves nothing resolved. Reading that as a refusal would answer
             * "forbidden" to what is really a bad parameter, and send the agent
             * hunting for permissions it already has. Probing again on the bare
             * route settles the permission question; the real dispatch will
             * then return WooCommerce's own validation error, which says what
             * is actually wrong.
             */
            [ $resolved, $probed ] = $this->probe( $method, $route, [] );
        }

        if ( null === $resolved ) {
            return Capabilities::canCallRestRoute( false, $route );
        }

        if ( empty( $resolved['permission_callback'] ) ) {
            return Capabilities::canCallRestRoute( true, $route );
        }

        // The probe assigns both or neither, so a resolved handler always has its request.
        $permission = call_user_func( $resolved['permission_callback'], $probed );

        /*
         * WooCommerce answers a missing object from the permission callback
         * itself, with a business error rather than a plain false, so reading
         * something that does not exist comes back as a refusal. Passing that
         * error on is not an option: the Abilities API drops it on purpose,
         * rather than leak to a caller without the rights what it should not
         * learn, and says so through `_doing_it_wrong()`.
         */
        return Capabilities::canCallRestRoute( true === $permission, $route );
    }

    /**
     * Runs a request far enough to know which handler would serve it.
     *
     * `rest_request_before_callbacks` hands over the resolved handler and fires
     * before the permission is evaluated: the filter grabs the handler and
     * returns an error, which stops the request before anything is created,
     * updated or deleted.
     *
     * @param string               $method HTTP verb.
     * @param string               $route  Fully built route, ids included.
     * @param array<string, mixed> $input  Parameters to send.
     * @return array{0: array<string, mixed>|null, 1: \WP_REST_Request|null}
     */
    private function probe( string $method, string $route, array $input ): array {
        $resolved = null;
        $probed   = null;

        $probe = static function ( $response, $handler, $request ) use ( &$resolved, &$probed, $method, $route ) {
            // Another request may be dispatched while ours is in flight.
            if ( $request->get_method() !== $method || $request->get_route() !== $route ) {
                return $response;
            }

            $resolved = $handler;
            $probed   = $request;

            return new \WP_Error(
                'wpmcpa_permission_probe',
                __( 'Permission probe, the request stops here.', 'wp-mcp-abilities' ),
                [ 'status' => 200 ]
            );
        };

        add_filter( 'rest_request_before_callbacks', $probe, PHP_INT_MAX, 3 );

        rest_do_request( $this->buildRequest( $method, $route, $input ) );

        remove_filter( 'rest_request_before_callbacks', $probe, PHP_INT_MAX );

        return [ $resolved, $probed ];
    }

    /**
     * Input schema derived from the controller of the targeted route.
     *
     * @param string $controller Controller class name.
     * @param string $method     One of the WP_REST_Server constants.
     * @return array<string, mixed>
     */
    protected function routeSchema( string $controller, string $method ): array {
        $arguments = $this->controllerArguments( $controller, $method );

        if ( [] === $arguments ) {
            return [ 'type' => 'object' ];
        }

        $properties = [];

        foreach ( $arguments as $name => $argument ) {
            // `context` drives the REST envelope, not the catalogue.
            if ( in_array( $name, [ 'context', '_fields' ], true ) ) {
                continue;
            }

            unset( $argument['required'], $argument['validate_callback'], $argument['sanitize_callback'] );

            $properties[ $name ] = $argument;
        }

        return [
            'type'       => 'object',
            'properties' => $this->withFieldSelection( $properties ),
        ];
    }

    /**
     * Adds the field selection to a set of input properties.
     *
     * A product sheet carries seventy-odd properties, and a page of them costs
     * an agent dearly for the three it came for. `_fields` is the lever the REST
     * API already provides; leaving it out of the schema meant the agent could
     * not ask for less, page after page.
     *
     * @param array<string, mixed> $properties Properties derived from the controller.
     * @return array<string, mixed>
     */
    protected function withFieldSelection( array $properties ): array {
        $properties['_fields'] = [
            'type'        => 'string',
            'description' => __(
                'Comma separated list of the fields to return, for instance "id,name,sku,price,stock_quantity". Everything is returned when it is left out.',
                'wp-mcp-abilities'
            ),
        ];

        return $properties;
    }

    /**
     * Reads the endpoint arguments of a controller, once per request.
     *
     * Instantiating a controller to read its schema is not free, and several
     * abilities of the family ask for the very same one.
     *
     * @param string $controller Controller class name.
     * @param string $method     One of the WP_REST_Server constants.
     * @return array<string, array<string, mixed>>
     */
    private function controllerArguments( string $controller, string $method ): array {
        if ( ! $this->controllerExists( $controller ) ) {
            return [];
        }

        return (array) RequestCache::remember(
            $controller . ':' . $method,
            static function () use ( $controller, $method ): array {
                $instance = new $controller();

                return $instance->get_endpoint_args_for_item_schema( $method );
            }
        );
    }

    /**
     * Whether a WooCommerce REST controller is there to be read.
     *
     * Answering false without a word would hand back a wide open schema while
     * WooCommerce is running, and nobody would notice until an agent sent
     * anything it liked. The controllers named here are not part of the
     * documented WooCommerce API, so a rename is unlikely but possible: it must
     * be loud.
     *
     * @param string $controller Controller class name.
     */
    private function controllerExists( string $controller ): bool {
        if ( ! $this->isWooCommerceActive() ) {
            return false;
        }

        if ( class_exists( $controller ) ) {
            return true;
        }

        _doing_it_wrong(
            __METHOD__,
            sprintf(
                /* translators: %s: REST controller class name. */
                esc_html__( 'The WooCommerce REST controller %s is missing: the ability falls back on an unconstrained schema.', 'wp-mcp-abilities' ),
                esc_html( $controller )
            ),
            '2.3.0'
        );

        return false;
    }

    /**
     * Output schema of a single catalogue item: field names, no types.
     *
     * WooCommerce describes its REST fields for what goes in, not for what comes
     * out: `stock_quantity` is an integer that answers null when the stock is
     * unmanaged, `shipping_class_id` a string that answers an integer. The REST
     * server never notices, since it only validates input. The Abilities API
     * validates output too, so reusing those types verbatim would refuse
     * perfectly ordinary products. Their names and descriptions are kept, which
     * tells an agent what it may read without asserting a shape that would lie.
     *
     * @param string $controller Controller class name.
     * @return array<string, mixed>
     */
    protected function itemSchema( string $controller ): array {
        if ( ! $this->controllerExists( $controller ) ) {
            return [ 'type' => 'object' ];
        }

        $properties = (array) RequestCache::remember(
            $controller . ':item',
            function () use ( $controller ): array {
                $instance = new $controller();
                $schema   = $instance->get_item_schema();

                unset( $schema['properties']['_links'] );

                return $this->describeOnly( $schema['properties'] ?? [] );
            }
        );

        return [
            'type'        => 'object',
            'description' => __(
                'Item as returned by the WooCommerce REST API, minus its _links.',
                'wp-mcp-abilities'
            ),
            'properties'  => $properties,
        ];
    }

    /**
     * Keeps the name and the description of each property, drops the rest.
     *
     * @param array<string, mixed> $properties Schema properties.
     * @return array<string, mixed>
     */
    private function describeOnly( array $properties ): array {
        $described = [];

        foreach ( $properties as $name => $property ) {
            $described[ $name ] = isset( $property['description'] )
                ? [ 'description' => $property['description'] ]
                : [];
        }

        return $described;
    }

    /**
     * Output schema of a paginated collection.
     *
     * @param string $controller Controller class name.
     * @param string $key        Name of the list in the payload.
     * @return array<string, mixed>
     */
    protected function collectionSchema( string $controller, string $key ): array {
        return [
            'type'       => 'object',
            'properties' => [
                $key          => [
                    'type'  => 'array',
                    'items' => $this->itemSchema( $controller ),
                ],
                'total'       => [
                    'type'        => 'integer',
                    'description' => __(
                        'Number of matching items, absent when WooCommerce did not report one.',
                        'wp-mcp-abilities'
                    ),
                ],
                'total_pages' => [
                    'type'        => 'integer',
                    'description' => __(
                        'Number of pages, absent when WooCommerce did not report one.',
                        'wp-mcp-abilities'
                    ),
                ],
            ],
        ];
    }

    /**
     * Runs a collection request and unwraps its pagination headers.
     *
     * A REST collection answers with a plain list and puts the counts in the
     * headers. An agent reads a named list and a total far more easily.
     *
     * @param string               $route Fully built route.
     * @param array<string, mixed> $input Query parameters.
     * @param string               $key   Name to give the list in the payload.
     * @return array<string, mixed>|\WP_Error
     */
    protected function dispatchCollection( string $route, array $input, string $key ): array|\WP_Error {
        if ( ! $this->isWooCommerceActive() ) {
            return $this->wooCommerceMissing();
        }

        $request  = $this->buildRequest( 'GET', $route, $input );
        $response = $this->trim( rest_do_request( $request ), $request );

        if ( $response->is_error() ) {
            return $response->as_error();
        }

        $headers = $response->get_headers();
        $items   = array_map( [ $this, 'prune' ], (array) $response->get_data() );

        $collection = [ $key => array_values( $items ) ];

        /*
         * A count is only reported when WooCommerce reported one. Falling back
         * on the size of the page would tell an agent that it holds everything
         * there is, which is exactly wrong on the first page of a long
         * catalogue, and it would have no way of knowing.
         */
        if ( isset( $headers['X-WP-Total'] ) ) {
            $collection['total'] = (int) $headers['X-WP-Total'];
        }

        if ( isset( $headers['X-WP-TotalPages'] ) ) {
            $collection['total_pages'] = (int) $headers['X-WP-TotalPages'];
        }

        return $collection;
    }

    /**
     * Builds a REST request carrying the input as parameters.
     *
     * `set_param()` rather than `set_body_params()`: a GET request ignores body
     * parameters entirely, since `WP_REST_Request::get_parameter_order()` only
     * reads a body on POST, PUT, PATCH and DELETE. A read filtered by the agent
     * would have been dispatched unfiltered, and nothing would have said so.
     *
     * @param string               $method HTTP verb.
     * @param string               $route  Fully built route, ids included.
     * @param array<string, mixed> $input  Parameters to send.
     */
    private function buildRequest( string $method, string $route, array $input ): \WP_REST_Request {
        $request = new \WP_REST_Request( $method, $route );

        foreach ( $input as $name => $value ) {
            $request->set_param( $name, $value );
        }

        return $request;
    }

    /**
     * Applies the `_fields` selection the REST server only applies when serving.
     *
     * `rest_filter_response_fields()` hangs on `rest_post_dispatch`, fired by
     * `serve_request()` and not by `rest_do_request()`. Left alone, `_fields`
     * would be accepted, documented, and quietly ignored, and every read would
     * cost the agent a full product sheet.
     *
     * @param \WP_REST_Response|\WP_HTTP_Response $response Response to trim.
     * @param \WP_REST_Request                    $request  Request carrying the selection.
     * @return \WP_REST_Response|\WP_HTTP_Response
     */
    private function trim( $response, \WP_REST_Request $request ) {
        if ( ! $response instanceof \WP_REST_Response || ! isset( $request['_fields'] ) ) {
            return $response;
        }

        return rest_filter_response_fields( $response, rest_get_server(), $request );
    }

    /**
     * Input schema of a collection route, derived from its controller.
     *
     * @param string $controller Controller class name.
     * @return array<string, mixed>
     */
    protected function collectionParamsSchema( string $controller ): array {
        if ( ! $this->controllerExists( $controller ) ) {
            return [ 'type' => 'object' ];
        }

        $properties = (array) RequestCache::remember(
            $controller . ':collection',
            static function () use ( $controller ): array {
                $instance   = new $controller();
                $properties = [];

                foreach ( $instance->get_collection_params() as $name => $argument ) {
                    if ( in_array( $name, [ 'context', '_fields' ], true ) ) {
                        continue;
                    }

                    unset( $argument['validate_callback'], $argument['sanitize_callback'] );

                    $properties[ $name ] = $argument;
                }

                return $properties;
            }
        );

        return [
            'type'       => 'object',
            'properties' => $this->withFieldSelection( $properties ),
        ];
    }

    /**
     * Drops what belongs to the REST envelope rather than to the catalogue.
     *
     * @param array<string, mixed> $data Payload returned by the controller.
     * @return array<string, mixed>
     */
    protected function prune( array $data ): array {
        unset( $data['_links'] );

        return $data;
    }

    /**
     * Error returned when an ability is called on a site without WooCommerce.
     */
    protected function wooCommerceMissing(): \WP_Error {
        return new \WP_Error(
            'woocommerce_missing',
            __( 'WooCommerce is not active on this site.', 'wp-mcp-abilities' ),
            [ 'status' => 400 ]
        );
    }
}
