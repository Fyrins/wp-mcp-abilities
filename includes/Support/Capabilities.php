<?php
/**
 * Capability checks shared by every ability.
 *
 * @package WpMcpAbilities\Support
 */

namespace WpMcpAbilities\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves WordPress capabilities against the object being touched.
 *
 * Abilities are executed on behalf of a logged-in user, so they must honour the
 * same authorisation model as the editor: meta capabilities bound to the target
 * object (`edit_post`, `delete_term`…) rather than the broad primitive ones.
 */
class Capabilities {
    /**
     * Lets integrators override any decision taken here.
     *
     * @param bool   $allowed    Whether WordPress granted the capability.
     * @param string $capability The capability that was checked.
     * @param mixed  $context    The object the check was made against, when any.
     * @return bool
     */
    private static function filter( bool $allowed, string $capability, mixed $context = null ): bool {
        /**
         * Filters a capability check performed by an ability.
         *
         * @param bool   $allowed    Whether WordPress granted the capability.
         * @param string $capability The capability that was checked.
         * @param mixed  $context    The object the check was made against, when any.
         */
        return (bool) apply_filters(
            'wpmcpa_check_permission',
            $allowed,
            $capability,
            $context
        );
    }

    /**
     * Read access, aligned with the REST API baseline for content endpoints.
     */
    public static function canRead(): bool {
        return self::filter( current_user_can( 'edit_posts' ), 'edit_posts' );
    }

    /**
     * Whether the current user may edit this very post.
     *
     * @param \WP_Post $post Target post.
     */
    public static function canEditPost( \WP_Post $post ): bool {
        return self::filter( current_user_can( 'edit_post', $post->ID ), 'edit_post', $post );
    }

    /**
     * Whether the current user may delete this very post.
     *
     * @param \WP_Post $post Target post.
     */
    public static function canDeletePost( \WP_Post $post ): bool {
        return self::filter( current_user_can( 'delete_post', $post->ID ), 'delete_post', $post );
    }

    /**
     * Whether the current user may create a post of the given type.
     *
     * @param string $postType Post type slug.
     */
    public static function canCreatePost( string $postType ): bool {
        $object = get_post_type_object( $postType );

        if ( ! $object instanceof \WP_Post_Type ) {
            return self::filter( false, 'create_posts', $postType );
        }

        $capability = $object->cap->create_posts;

        return self::filter( current_user_can( $capability ), $capability, $postType );
    }

    /**
     * Whether the current user may publish a post of the given type.
     *
     * Guards `status => publish` so a contributor cannot bypass the editorial
     * workflow through an ability.
     *
     * @param string $postType Post type slug.
     */
    public static function canPublishPost( string $postType ): bool {
        $object = get_post_type_object( $postType );

        if ( ! $object instanceof \WP_Post_Type ) {
            return self::filter( false, 'publish_posts', $postType );
        }

        $capability = $object->cap->publish_posts;

        return self::filter( current_user_can( $capability ), $capability, $postType );
    }

    /**
     * Whether the current user may assign a post to somebody else.
     *
     * @param string $postType Post type slug.
     */
    public static function canAssignAuthor( string $postType ): bool {
        $object = get_post_type_object( $postType );

        if ( ! $object instanceof \WP_Post_Type ) {
            return self::filter( false, 'edit_others_posts', $postType );
        }

        $capability = $object->cap->edit_others_posts;

        return self::filter( current_user_can( $capability ), $capability, $postType );
    }

    /**
     * Whether the current user may upload files.
     */
    public static function canUploadFiles(): bool {
        return self::filter( current_user_can( 'upload_files' ), 'upload_files' );
    }

    /**
     * Whether the current user may create terms in the given taxonomy.
     *
     * @param string $taxonomy Taxonomy slug.
     */
    public static function canManageTerms( string $taxonomy ): bool {
        $object = get_taxonomy( $taxonomy );

        if ( ! $object instanceof \WP_Taxonomy ) {
            return self::filter( false, 'manage_terms', $taxonomy );
        }

        $capability = $object->cap->manage_terms;

        return self::filter( current_user_can( $capability ), $capability, $taxonomy );
    }

    /**
     * Whether the current user may edit this very term.
     *
     * @param \WP_Term $term Target term.
     */
    public static function canEditTerm( \WP_Term $term ): bool {
        return self::filter( current_user_can( 'edit_term', $term->term_id ), 'edit_term', $term );
    }

    /**
     * Whether the current user may delete this very term.
     *
     * @param \WP_Term $term Target term.
     */
    public static function canDeleteTerm( \WP_Term $term ): bool {
        return self::filter( current_user_can( 'delete_term', $term->term_id ), 'delete_term', $term );
    }

    /**
     * Lets the plugin filter a decision already taken by a REST route.
     *
     * The catalogue abilities delegate to the WooCommerce controllers, whose own
     * `permission_callback` knows the capability model of the shop better than
     * this class would. Its verdict still goes through the plugin filter, so an
     * integrator keeps the last word on what an agent may reach.
     *
     * @param bool   $allowed Whether the route granted access.
     * @param string $route   Route the decision was taken on.
     */
    public static function canCallRestRoute( bool $allowed, string $route ): bool {
        return self::filter( $allowed, 'rest_route', $route );
    }

    /**
     * Whether the current user may manage block templates and template parts.
     *
     * Both post types remap every meta capability to `edit_theme_options`.
     */
    public static function canEditTemplates(): bool {
        return self::filter( current_user_can( 'edit_theme_options' ), 'edit_theme_options' );
    }
}
