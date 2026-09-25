<?php
/**
 * Base class shared by every ability of the plugin.
 *
 * @package WpMcpAbilities\Support
 */

namespace WpMcpAbilities\Support;

use WpMcpAbilities\Contracts\AbilityInterface;
use WpMcpAbilities\AbilityCategories\ContentAbilityCategory;

defined( 'ABSPATH' ) || exit;

/**
 * Assembles the properties array expected by `wp_register_ability()`.
 *
 * Subclasses only describe themselves; wiring, category and callback plumbing
 * live here. Classes extending this one and placed under `includes/Abilities`
 * are listed in `Plugin::ABILITIES` and built there, so they must be
 * instantiable without arguments.
 */
abstract class AbstractAbility implements AbilityInterface {
    /**
     * Value of `meta.wpmcpa.plugin` on every ability this plugin registers.
     *
     * The name alone cannot tell them apart: `wp-mcp-abilities/` is this plugin's
     * prefix, but another plugin registering abilities could carry it too. Reading a
     * mark this plugin sets itself keeps the third-party catalogue honest,
     * whereas excluding the prefix would leave those abilities out of both
     * screens, which is the one place they must never be.
     */
    public const PLUGIN_MARK = 'wp-mcp-abilities';

    /**
     * Ability name, namespaced by the plugin category.
     */
    abstract public function getName(): string;

    /**
     * Short human readable name, shown on the settings screen.
     */
    abstract public function getLabel(): string;

    /**
     * What the ability does, read by the MCP client to pick a tool.
     */
    abstract public function getDescription(): string;

    /**
     * Settings screen group this ability belongs to.
     */
    abstract public function getGroup(): string;

    /**
     * JSON schema of the accepted input.
     *
     * @return array<string, mixed>
     */
    abstract public function getInputSchema(): array;

    /**
     * JSON schema of the returned payload.
     *
     * @return array<string, mixed>
     */
    abstract public function getOutputSchema(): array;

    /**
     * Runs the ability.
     *
     * @param mixed $input Raw input coming from the MCP adapter.
     * @return array<string, mixed>|\WP_Error
     */
    abstract public function execute( mixed $input = null ): array|\WP_Error;

    /**
     * Authorises the call against the object being targeted.
     *
     * @param mixed $input Raw input coming from the MCP adapter.
     */
    abstract public function checkPermission( mixed $input = null ): bool;

    /**
     * Whether the ability is exposed when the site has no explicit setting yet.
     *
     * Destructive abilities override this and return false.
     */
    public function isEnabledByDefault(): bool {
        return true;
    }

    /**
     * Whether the ability's own requirements are met.
     *
     * An ability depending on a third-party plugin returns false when that
     * plugin is missing, so it is never registered rather than failing at call
     * time with a misleading success.
     */
    public function isAvailable(): bool {
        return true;
    }

    /**
     * Builds the fully namespaced ability name.
     *
     * @param string $slug Ability slug, without the category namespace.
     */
    protected static function qualify( string $slug ): string {
        return ContentAbilityCategory::SLUG . '/' . $slug;
    }

    /**
     * @inheritDoc
     */
    public function getProperties(): array {
        return [
            'label'               => $this->getLabel(),
            'description'         => $this->getDescription(),
            'category'            => ContentAbilityCategory::SLUG,
            'input_schema'        => $this->getInputSchema(),
            'output_schema'       => $this->getOutputSchema(),
            'permission_callback' => [ $this, 'checkPermission' ],
            'execute_callback'    => [ $this, 'execute' ],

            /*
             * Without this flag the ability is registered, yet stays invisible:
             * the adapter reads `meta.mcp.public` and treats a missing value as
             * false (`McpAbilityHelperTrait::is_ability_mcp_public()`). It is
             * then left out of the discovery listing, and recent versions also
             * refuse to run it. Every ability of this plugin exists to be
             * called over MCP, so the flag is set for all of them; what an
             * agent may actually reach is decided by the settings screen and by
             * each `checkPermission()`.
             */
            'meta'                => [
                'mcp'              => [ 'public' => true ],
                // Read by the third-party catalogue to tell our abilities from the rest.
                'wpmcpa' => [ 'plugin' => self::PLUGIN_MARK ],
            ],
        ];
    }

    /**
     * Resolves a post and makes sure the ability is allowed to touch its type.
     *
     * @param int $postId Post ID.
     * @return \WP_Post|\WP_Error
     */
    protected function resolvePost( int $postId ): \WP_Post|\WP_Error {
        $post = get_post( $postId );

        if ( ! $post instanceof \WP_Post ) {
            return new \WP_Error(
                'post_not_found',
                __( 'Post not found.', 'wp-mcp-abilities' ),
                [ 'status' => 404 ]
            );
        }

        return $post;
    }

    /**
     * Resolves a term.
     *
     * @param int    $termId   Term ID.
     * @param string $taxonomy Optional taxonomy to constrain the lookup.
     * @return \WP_Term|\WP_Error
     */
    protected function resolveTerm( int $termId, string $taxonomy = '' ): \WP_Term|\WP_Error {
        $term = '' !== $taxonomy ? get_term( $termId, $taxonomy ) : get_term( $termId );

        if ( ! $term instanceof \WP_Term ) {
            return new \WP_Error(
                'term_not_found',
                __( 'Term not found.', 'wp-mcp-abilities' ),
                [ 'status' => 404 ]
            );
        }

        return $term;
    }

    /**
     * Builds the standard "insufficient permissions" error.
     */
    protected function forbidden(): \WP_Error {
        return new \WP_Error(
            'insufficient_permissions',
            __( 'You are not allowed to perform this action.', 'wp-mcp-abilities' ),
            [ 'status' => 403 ]
        );
    }
}
