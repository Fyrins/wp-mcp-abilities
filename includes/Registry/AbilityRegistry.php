<?php
/**
 * Holds the plugin's abilities and hands them to the Abilities API.
 *
 * @package WpMcpAbilities\Registry
 */

namespace WpMcpAbilities\Registry;

use WpMcpAbilities\Contracts\AbilityCategoryInterface;
use WpMcpAbilities\Contracts\AbilityInterface;
use WpMcpAbilities\Contracts\HookInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Registry of the static abilities and categories.
 *
 * Post type abilities are not stored here: `PostTypeAbilityProvider` builds and
 * registers them itself.
 */
final class AbilityRegistry implements HookInterface {
    /**
     * Abilities keyed by name.
     *
     * @var array<string, AbilityInterface>
     */
    private array $abilities = [];

    /**
     * Categories keyed by slug.
     *
     * @var array<string, AbilityCategoryInterface>
     */
    private array $categories = [];

    /**
     * Adds an ability; a later one with the same name replaces the earlier.
     *
     * @param AbilityInterface $ability Ability to register.
     * @return void
     */
    public function addAbility( AbilityInterface $ability ): void {
        $this->abilities[ $ability->getName() ] = $ability;
    }

    /**
     * Adds a category.
     *
     * @param AbilityCategoryInterface $category Category to register.
     * @return void
     */
    public function addCategory( AbilityCategoryInterface $category ): void {
        $this->categories[ $category->getName() ] = $category;
    }

    /**
     * Every ability, keyed by name.
     *
     * @return array<string, AbilityInterface>
     */
    public function getAbilities(): array {
        return $this->abilities;
    }

    /**
     * One ability by name.
     *
     * @param string $name Ability name.
     * @return AbilityInterface|null
     */
    public function getAbilityByName( string $name ): ?AbilityInterface {
        return $this->abilities[ $name ] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function hooks(): void {
        add_action( 'wp_abilities_api_categories_init', [ $this, 'registerCategories' ] );
        add_action( 'wp_abilities_api_init', [ $this, 'registerAbilities' ] );
    }

    /**
     * Registers the categories.
     *
     * @return void
     */
    public function registerCategories(): void {
        if ( ! function_exists( 'wp_register_ability_category' ) ) {
            return;
        }

        foreach ( $this->categories as $name => $category ) {
            wp_register_ability_category( $name, $category->getProperties() );
        }
    }

    /**
     * Registers the abilities left after the `wpmcpa_abilities` filter.
     *
     * @return void
     */
    public function registerAbilities(): void {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }

        /**
         * Filters the abilities about to be registered.
         *
         * @param array<string, AbilityInterface> $abilities Abilities keyed by name.
         */
        $abilities = apply_filters( 'wpmcpa_abilities', $this->abilities );

        foreach ( (array) $abilities as $ability ) {
            if ( $ability instanceof AbilityInterface ) {
                wp_register_ability( $ability->getName(), $ability->getProperties() );
            }
        }
    }
}
