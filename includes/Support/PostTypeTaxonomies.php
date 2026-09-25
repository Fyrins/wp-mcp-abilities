<?php
/**
 * Taxonomy rules of a post type, for the abilities that write its content.
 *
 * @package WpMcpAbilities\Support
 */

namespace WpMcpAbilities\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Decides which taxonomies a caller may reach, checks what it sends and applies it.
 *
 * Split out of `PostTypeAbility`, which was carrying three reasons to change at
 * once: running the five CRUD operations, describing them as JSON schemas, and
 * the taxonomy rules gathered here. Those rules move on their own — what counts
 * as reachable, which capability governs an assignment, what a refusal says —
 * and they are the part worth exercising in isolation.
 *
 * Built from a post type alone, so nothing here needs an ability, an operation
 * or a request to be reasoned about.
 *
 * Lives under `includes/Support` like its caller: `includes/Abilities` only
 * holds the argument-free abilities built by `Plugin`, and this class needs a
 * `\WP_Post_Type`.
 */
class PostTypeTaxonomies {
    /**
     * @param \WP_Post_Type $postType Post type whose taxonomies are governed here.
     */
    public function __construct( private readonly \WP_Post_Type $postType ) {
    }

    /**
     * Taxonomies this ability lets a caller assign terms in.
     *
     * Only what a taxonomy declares as API-facing. `show_in_rest` is the flag
     * core itself filters on before exposing a taxonomy to a REST client, and
     * this list stays strictly aligned with it: a taxonomy registered `public`
     * but deliberately kept out of `show_in_rest` — a plugin wanting a slug in
     * its permalinks without opening the taxonomy to clients — is not ours to
     * reopen, all the more so as `assign_terms` defaults to `edit_posts`, which
     * a contributor holds. A site that does want one exposed says so through
     * the filter below.
     *
     * The same rule governs the taxonomy abilities, so what a client can assign
     * and what it can list are one and the same set. `post_format` needs no
     * special case: it does not declare `show_in_rest`, and falls out on its
     * own.
     *
     * @return array<string, \WP_Taxonomy>
     */
    public function assignableTaxonomies(): array {
        $taxonomies = [];

        foreach ( (array) get_object_taxonomies( $this->postType->name, 'objects' ) as $taxonomy ) {
            if ( ! $taxonomy instanceof \WP_Taxonomy || ! $taxonomy->show_in_rest ) {
                continue;
            }

            $taxonomies[ $taxonomy->name ] = $taxonomy;
        }

        /**
         * Filters the taxonomies the post type abilities may assign terms in.
         *
         * @param array<string, \WP_Taxonomy> $taxonomies Assignable taxonomies, keyed by slug.
         * @param \WP_Post_Type               $postType   Post type being described.
         */
        return (array) apply_filters(
            'wpmcpa_assignable_taxonomies',
            $taxonomies,
            $this->postType
        );
    }

    /**
     * Schema fragment describing the `terms` property, one entry per taxonomy.
     *
     * The taxonomies are spelled out rather than left to
     * `additionalProperties`, so an agent reading the schema knows which ones
     * this post type actually has instead of having to guess a slug.
     *
     * @return array<string, mixed>
     */
    public function assignableTaxonomySchema(): array {
        $properties = [];

        foreach ( $this->assignableTaxonomies() as $slug => $taxonomy ) {
            $properties[ $slug ] = [
                'type'        => 'array',
                'items'       => [ 'type' => 'integer' ],
                'description' => sprintf(
                    /* translators: 1: taxonomy label (e.g. "Categories"), 2: taxonomy slug. */
                    __( 'Term IDs in %1$s (%2$s).', 'wp-mcp-abilities' ),
                    $taxonomy->labels->name,
                    $slug
                ),
            ];
        }

        return $properties;
    }

    /**
     * Checks the `terms` input before anything is written.
     *
     * Validation is deliberately separated from the write: a refusal raised
     * after `wp_insert_post()` would leave a post behind while the response
     * reported a failure, and the caller would have no way to tell which of the
     * two happened.
     *
     * @param array<string, mixed> $input Normalised MCP input.
     * @return true|\WP_Error
     */
    public function validateTerms( array $input ): bool|\WP_Error {
        if ( ! isset( $input['terms'] ) ) {
            return true;
        }

        // `category_ids` travels as `post_category` through wp_insert_post(),
        // and `applyTerms()` runs after it: sent together they would not merge,
        // `terms` would quietly overwrite the other. Rather than pick a winner
        // and say nothing, refuse and let the caller choose.
        if ( isset( $input['category_ids'] ) && is_array( $input['terms'] ) && isset( $input['terms']['category'] ) ) {
            return new \WP_Error(
                'ambiguous_categories',
                __( 'Send either "category_ids" or the "category" entry of "terms", not both: they write the same taxonomy.', 'wp-mcp-abilities' ),
                [ 'status' => 400 ]
            );
        }

        if ( ! is_array( $input['terms'] ) ) {
            return new \WP_Error(
                'invalid_terms',
                __( 'The "terms" parameter must be an object keyed by taxonomy.', 'wp-mcp-abilities' ),
                [ 'status' => 400 ]
            );
        }

        $assignable = $this->assignableTaxonomies();

        foreach ( $input['terms'] as $taxonomy => $termIds ) {
            $taxonomy = (string) $taxonomy;

            if ( ! isset( $assignable[ $taxonomy ] ) ) {
                return new \WP_Error(
                    'taxonomy_not_assignable',
                    sprintf(
                        /* translators: 1: taxonomy slug, 2: post type slug. */
                        __( 'The "%1$s" taxonomy is not registered for the "%2$s" post type, or is not open to MCP abilities.', 'wp-mcp-abilities' ),
                        $taxonomy,
                        $this->postType->name
                    ),
                    [ 'status' => 400 ]
                );
            }

            if ( ! is_array( $termIds ) ) {
                return new \WP_Error(
                    'invalid_terms',
                    sprintf(
                        /* translators: %s: taxonomy slug. */
                        __( 'The "%s" entry of "terms" must be an array of term IDs.', 'wp-mcp-abilities' ),
                        $taxonomy
                    ),
                    [ 'status' => 400 ]
                );
            }

            if ( ! current_user_can( $assignable[ $taxonomy ]->cap->assign_terms ) ) {
                return new \WP_Error(
                    'terms_not_allowed',
                    sprintf(
                        /* translators: %s: taxonomy slug. */
                        __( 'You are not allowed to assign terms in the "%s" taxonomy.', 'wp-mcp-abilities' ),
                        $taxonomy
                    ),
                    [ 'status' => 403 ]
                );
            }

            foreach ( $termIds as $termId ) {
                if ( ! is_scalar( $termId ) || ! is_numeric( $termId ) ) {
                    return new \WP_Error(
                        'invalid_terms',
                        sprintf(
                            /* translators: %s: taxonomy slug. */
                            __( 'The "%s" entry of "terms" must only contain term IDs.', 'wp-mcp-abilities' ),
                            $taxonomy
                        ),
                        [ 'status' => 400 ]
                    );
                }

                if ( ! term_exists( (int) $termId, $taxonomy ) ) {
                    return new \WP_Error(
                        'term_not_found',
                        sprintf(
                            /* translators: 1: term ID, 2: taxonomy slug. */
                            __( 'Term %1$d does not exist in the "%2$s" taxonomy.', 'wp-mcp-abilities' ),
                            (int) $termId,
                            $taxonomy
                        ),
                        [ 'status' => 404 ]
                    );
                }
            }
        }

        return true;
    }

    /**
     * Assigns the terms carried by `terms`, one taxonomy at a time.
     *
     * Runs after the insert or the update rather than through the post data:
     * `wp_insert_post()` only knows `post_category` and `tax_input`, and
     * `tax_input` silently drops every taxonomy the current user cannot assign.
     * Going through `wp_set_object_terms()` keeps that refusal sayable, which
     * `validateTerms()` has already said before this point.
     *
     * Each taxonomy sent replaces what the post carried in it; a taxonomy left
     * out of the payload is not touched.
     *
     * @param int                  $postId Post that has just been written.
     * @param array<string, mixed> $input  Normalised MCP input.
     * @return true|\WP_Error
     */
    public function applyTerms( int $postId, array $input ): bool|\WP_Error {
        if ( ! isset( $input['terms'] ) || ! is_array( $input['terms'] ) ) {
            return true;
        }

        foreach ( $input['terms'] as $taxonomy => $termIds ) {
            $result = wp_set_object_terms(
                $postId,
                array_map( 'intval', array_values( (array) $termIds ) ),
                (string) $taxonomy,
                false
            );

            if ( is_wp_error( $result ) ) {
                // `validateTerms()` has already refused everything it could see
                // beforehand, so reaching this point means the ground moved
                // under the write — a term deleted meanwhile, a third-party
                // filter on `wp_set_object_terms`. The publication exists by
                // now, and some taxonomies may already have been rewritten, so
                // the error carries the identifier: an error the caller cannot
                // act on is only half an answer.
                $result->add_data(
                    array_merge(
                        (array) $result->get_error_data(),
                        [
                            'post_id'      => $postId,
                            'post_written' => true,
                        ]
                    )
                );

                return $result;
            }
        }

        return true;
    }

    /**
     * Resolves the terms assigned to a post, keyed by taxonomy.
     *
     * @param \WP_Post $post Post to inspect.
     * @return array<string, array<int, int>>
     */
    public function postTerms( \WP_Post $post ): array {
        $taxonomies = array_keys( $this->assignableTaxonomies() );
        $terms      = array_fill_keys( $taxonomies, [] );

        if ( [] === $taxonomies ) {
            return $terms;
        }

        // One query for every taxonomy at once. Asking taxonomy by taxonomy
        // cost a query each, on a payload that already carries the whole
        // content.
        $assigned = wp_get_object_terms( $post->ID, $taxonomies );

        if ( is_wp_error( $assigned ) ) {
            return $terms;
        }

        foreach ( $assigned as $term ) {
            $terms[ $term->taxonomy ][] = (int) $term->term_id;
        }

        return $terms;
    }

    /**
     * Resolves the category IDs assigned to a post, when the post type supports them.
     *
     * @param \WP_Post $post Post to inspect.
     * @return array<int, int>
     */
    public function categoryIds( \WP_Post $post ): array {
        if ( ! in_array( 'category', (array) get_object_taxonomies( $this->postType->name ), true ) ) {
            return [];
        }

        $terms = wp_get_post_categories( $post->ID );

        return is_array( $terms ) ? array_map( 'intval', $terms ) : [];
    }
}
