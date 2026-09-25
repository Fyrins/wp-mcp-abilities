<?php
/**
 * Inventory of every ability the plugin can expose.
 *
 * @package WpMcpAbilities\Services
 */

namespace WpMcpAbilities\Services;

use WpMcpAbilities\Registry\AbilityRegistry;
use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\PostTypeAbility;

defined( 'ABSPATH' ) || exit;

/**
 * Assembles the abilities declared as classes and those generated per post type.
 *
 * Kept out of the hooks on purpose: the hooks only wire this inventory into
 * WordPress, whether to register the abilities or to render the settings
 * screen, and hold no knowledge of how the list is built.
 */
class AbilityCatalogService {
    /**
     * CRUD operations generated for every eligible post type.
     *
     * @var string[]
     */
    private const OPERATIONS = [ 'list', 'get', 'create', 'update', 'delete' ];

    /**
     * @param AbilityRegistry $registry Registry of the static abilities.
     */
    public function __construct( private AbilityRegistry $registry ) {
    }

    /**
     * Every available ability, class-declared and post type ones alike.
     *
     * Abilities whose own requirements are not met are left out, so a caller
     * never has to care about, say, SEOpress being absent.
     *
     * @return array<string, AbstractAbility>
     */
    public function all(): array {
        $abilities = array_merge( $this->registry->getAbilities(), $this->postTypeAbilities() );

        return array_filter(
            $abilities,
            static fn ( $ability ): bool => $ability instanceof AbstractAbility && $ability->isAvailable()
        );
    }

    /**
     * Abilities generated for each exposed post type.
     *
     * The registry holds one ability per class, which cannot express a number
     * of abilities only known at runtime.
     *
     * @return array<string, PostTypeAbility>
     */
    public function postTypeAbilities(): array {
        $abilities = [];

        foreach ( $this->getPostTypes() as $postType ) {
            foreach ( $this->getOperations( $postType ) as $operation ) {
                $ability = new PostTypeAbility( $postType, $operation );

                if ( ! $ability->isAvailable() ) {
                    continue;
                }

                $abilities[ $ability->getName() ] = $ability;
            }
        }

        return $abilities;
    }

    /**
     * Every available ability, keyed by settings group then by ability name.
     *
     * @return array<string, array<string, AbstractAbility>>
     */
    public function grouped(): array {
        $abilities = $this->all();

        uasort(
            $abilities,
            static fn ( AbstractAbility $a, AbstractAbility $b ): int =>
                [ $a->getGroup(), $a->getLabel() ] <=> [ $b->getGroup(), $b->getLabel() ]
        );

        $grouped = [];

        foreach ( $abilities as $name => $ability ) {
            $grouped[ $ability->getGroup() ][ $name ] = $ability;
        }

        return $grouped;
    }

    /**
     * Operations exposed for a given post type.
     *
     * A post type is rarely all or nothing. WooCommerce hands over the writing
     * of a product, which needs a price and a stock the generic pair knows
     * nothing about, while the reading may perfectly well stay generic on a
     * shop whose WooCommerce is too old to describe its own catalogue.
     *
     * @param \WP_Post_Type $postType Post type the abilities are generated for.
     * @return string[]
     */
    private function getOperations( \WP_Post_Type $postType ): array {
        /**
         * Filters the operations exposed for a post type.
         *
         * @param string[]      $operations One or more of list, get, create, update, delete.
         * @param \WP_Post_Type $postType   Post type the abilities are generated for.
         */
        $operations = (array) apply_filters(
            'wpmcpa_post_type_operations',
            self::OPERATIONS,
            $postType
        );

        return array_values( array_intersect( self::OPERATIONS, $operations ) );
    }

    /**
     * Resolves the post types exposed through the dynamic abilities.
     *
     * @return \WP_Post_Type[]
     */
    private function getPostTypes(): array {
        $postTypes = get_post_types( [ 'public' => true ], 'objects' );

        unset( $postTypes['attachment'] );

        /**
         * Filters the post types exposed through the dynamic post type abilities.
         *
         * @param \WP_Post_Type[] $postTypes Post types eligible for list/get/create/update/delete abilities.
         */
        return (array) apply_filters( 'wpmcpa_post_types', $postTypes );
    }
}
