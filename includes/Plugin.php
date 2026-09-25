<?php
/**
 * Builds the plugin's object graph and attaches its hooks.
 *
 * @package WpMcpAbilities
 */

namespace WpMcpAbilities;

use WpMcpAbilities\AbilityCategories\ContentAbilityCategory;
use WpMcpAbilities\Contracts\HookInterface;
use WpMcpAbilities\Hooks\AbilityAvailability;
use WpMcpAbilities\Hooks\Admin\RegisterAbilitiesPage;
use WpMcpAbilities\Hooks\Admin\RegisterAbilitiesSettings;
use WpMcpAbilities\Hooks\PostTypeAbilityProvider;
use WpMcpAbilities\Hooks\RequirementsNotice;
use WpMcpAbilities\Hooks\ThirdPartyAbilityAvailability;
use WpMcpAbilities\Hooks\ThirdPartyAbilityExposure;
use WpMcpAbilities\Hooks\WooCommerceProductTypes;
use WpMcpAbilities\Registry\AbilityRegistry;
use WpMcpAbilities\Services\AbilityCatalogService;
use WpMcpAbilities\Services\SettingsService;
use WpMcpAbilities\Services\ThirdPartyAbilityCatalog;

defined( 'ABSPATH' ) || exit;

/**
 * Explicit wiring: every service and hook is built here, once.
 */
final class Plugin {
    /**
     * Static abilities. Post type abilities are built by `AbilityCatalogService`.
     *
     * @var list<class-string<Contracts\AbilityInterface>>
     */
    public const ABILITIES = [
        Abilities\Content\ReplaceInPostContentAbility::class,
        Abilities\Media\DeleteMediaAbility::class,
        Abilities\Media\ListMediaAbility::class,
        Abilities\Media\SetFeaturedImageAbility::class,
        Abilities\Media\UpdateMediaAbility::class,
        Abilities\Media\UploadMediaAbility::class,
        Abilities\Meta\DeleteTermMetaAbility::class,
        Abilities\Meta\GetPostMetaAbility::class,
        Abilities\Meta\GetTermMetaAbility::class,
        Abilities\Meta\UpdatePostMetaAbility::class,
        Abilities\Meta\UpdateTermMetaAbility::class,
        Abilities\Seopress\GetPostSeoAbility::class,
        Abilities\Seopress\GetTermSeoAbility::class,
        Abilities\Seopress\UpdatePostSchemasAbility::class,
        Abilities\Seopress\UpdatePostSeoAbility::class,
        Abilities\Seopress\UpdateTermSeoAbility::class,
        Abilities\Taxonomy\CreateTermAbility::class,
        Abilities\Taxonomy\DeleteTermAbility::class,
        Abilities\Taxonomy\GetTermAbility::class,
        Abilities\Taxonomy\ListTaxonomiesAbility::class,
        Abilities\Taxonomy\ListTermsAbility::class,
        Abilities\Taxonomy\UpdateTermAbility::class,
        Abilities\Template\CreateTemplateAbility::class,
        Abilities\Template\DeleteTemplateAbility::class,
        Abilities\Template\GetTemplateAbility::class,
        Abilities\Template\ListTemplatesAbility::class,
        Abilities\Template\UpdateTemplateAbility::class,
        Abilities\WooCommerce\CreateProductAbility::class,
        Abilities\WooCommerce\CreateProductAttributeAbility::class,
        Abilities\WooCommerce\CreateProductVariationAbility::class,
        Abilities\WooCommerce\DeleteProductVariationAbility::class,
        Abilities\WooCommerce\GetProductVariationAbility::class,
        Abilities\WooCommerce\ListProductAttributesAbility::class,
        Abilities\WooCommerce\ListProductVariationsAbility::class,
        Abilities\WooCommerce\UpdateProductAbility::class,
        Abilities\WooCommerce\UpdateProductVariationAbility::class,
    ];

    /**
     * Guards against a second boot.
     *
     * @var bool
     */
    private static bool $booted = false;

    /**
     * Builds the graph and attaches the hooks.
     *
     * @return void
     */
    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }
        self::$booted = true;

        $registry = new AbilityRegistry();
        $registry->addCategory( new ContentAbilityCategory() );
        foreach ( self::ABILITIES as $class ) {
            $registry->addAbility( new $class() );
        }

        $settings   = new SettingsService();
        $catalog    = new AbilityCatalogService( $registry );
        $thirdParty = new ThirdPartyAbilityCatalog();

        $hooks = [
            $registry,
            new AbilityAvailability( $settings ),
            new PostTypeAbilityProvider( $catalog, $settings ),
            new ThirdPartyAbilityAvailability( $thirdParty, $settings ),
            new ThirdPartyAbilityExposure( $thirdParty, $settings ),
            new RequirementsNotice(),
            new WooCommerceProductTypes(),
            new RegisterAbilitiesPage( $catalog ),
            new RegisterAbilitiesSettings( $catalog, $thirdParty, $settings ),
        ];

        foreach ( $hooks as $hook ) {
            /** @var HookInterface $hook */
            $hook->hooks();
        }

        /**
         * Fires once the plugin has built its services and attached its hooks.
         *
         * Custom abilities are better added on `wpmcpa_register_abilities`,
         * which also reaches code loaded after this plugin.
         *
         * @param AbilityRegistry $registry The plugin's ability registry.
         */
        do_action( 'wpmcpa_loaded', $registry );
    }
}
