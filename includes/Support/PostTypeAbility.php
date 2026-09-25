<?php
/**
 * Parametrised CRUD ability for a single (post type, operation) pair.
 *
 * @package WpMcpAbilities\Support
 */

namespace WpMcpAbilities\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Describes and executes one of the five CRUD operations for a given post type.
 *
 * One instance is built per (post type × operation) by
 * `WpMcpAbilities\Hooks\PostTypeAbilityProvider`, so this class lives
 * under `includes/Support` rather than `includes/Abilities`: the latter only
 * holds the argument-free abilities listed in `Plugin::ABILITIES`, whereas this
 * one requires a post type and an operation.
 */
class PostTypeAbility extends AbstractAbility {
    /**
     * Operations this class knows how to handle.
     */
    private const OPERATIONS = [ 'list', 'get', 'create', 'update', 'delete' ];

    /**
     * Highest number of items a single `list` call may return.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * Default page size of a `list` call.
     */
    private const DEFAULT_PER_PAGE = 50;

    /**
     * @param \WP_Post_Type $postType  Post type this ability operates on.
     * @param string        $operation One of 'list', 'get', 'create', 'update', 'delete'.
     * @throws \InvalidArgumentException When $operation is not one of the supported operations.
     */
    public function __construct( private readonly \WP_Post_Type $postType, private readonly string $operation ) {
        if ( ! in_array( $operation, self::OPERATIONS, true ) ) {
            throw new \InvalidArgumentException(
                esc_html( sprintf( 'Unsupported post type ability operation "%s".', $operation ) )
            );
        }

        $this->taxonomies = new PostTypeTaxonomies( $postType );
    }

    /**
     * Taxonomy rules of this post type: what is reachable, and what a write may say.
     *
     * @var PostTypeTaxonomies
     */
    private readonly PostTypeTaxonomies $taxonomies;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( sprintf( '%s-%s', $this->operation, $this->nameSlug() ) );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        $singular = $this->postType->labels->singular_name;
        $plural   = $this->postType->labels->name;

        return match ( $this->operation ) {
            // translators: %s: plural label of the post type (e.g. "Posts").
            'list'   => sprintf( __( 'List %s', 'wp-mcp-abilities' ), $plural ),
            // translators: %s: singular label of the post type (e.g. "Post").
            'get'    => sprintf( __( 'Get a %s', 'wp-mcp-abilities' ), $singular ),
            // translators: %s: singular label of the post type (e.g. "Post").
            'create' => sprintf( __( 'Create a %s', 'wp-mcp-abilities' ), $singular ),
            // translators: %s: singular label of the post type (e.g. "Post").
            'update' => sprintf( __( 'Update a %s', 'wp-mcp-abilities' ), $singular ),
            // translators: %s: singular label of the post type (e.g. "Post").
            'delete' => sprintf( __( 'Delete a %s', 'wp-mcp-abilities' ), $singular ),
        };
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        $singular = strtolower( $this->postType->labels->singular_name );
        $plural   = strtolower( $this->postType->labels->name );

        return match ( $this->operation ) {
            // translators: %s: plural label of the post type (e.g. "Posts").
            'list'   => sprintf( __( 'Lists all published and draft %s.', 'wp-mcp-abilities' ), $plural ),
            // translators: %s: singular label of the post type (e.g. "Post").
            'get'    => sprintf( __( 'Retrieves the full content of a %s.', 'wp-mcp-abilities' ), $singular ),
            // translators: %s: singular label of the post type (e.g. "Post").
            'create' => sprintf( __( 'Creates a new %s.', 'wp-mcp-abilities' ), $singular ),
            // translators: %s: singular label of the post type (e.g. "Post").
            'update' => sprintf( __( 'Updates an existing %s.', 'wp-mcp-abilities' ), $singular ),
            'delete' => sprintf(
                // translators: %s: singular label of the post type (e.g. "Post").
                __( 'Deletes a %s (trash by default, permanent if force=true).', 'wp-mcp-abilities' ),
                $singular
            ),
        };
    }

    /**
     * @inheritDoc
     */
    public function getGroup(): string {
        return $this->postType->labels->name;
    }

    /**
     * @inheritDoc
     */
    public function isEnabledByDefault(): bool {
        return 'delete' !== $this->operation;
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array {
        return match ( $this->operation ) {
            'list'   => $this->listInputSchema(),
            'get'    => $this->postIdInputSchema( false ),
            'delete' => $this->postIdInputSchema( true ),
            'create' => $this->writeInputSchema( true ),
            'update' => $this->writeInputSchema( false ),
        };
    }

    /**
     * @inheritDoc
     */
    public function getOutputSchema(): array {
        return match ( $this->operation ) {
            'list'   => $this->listOutputSchema(),
            'get'    => $this->postSchema( true ),
            'create' => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'post_id' => [ 'type' => 'integer' ],
                    'link'    => [ 'type' => 'string' ],
                    'status'  => [
                        'type'        => 'string',
                        'description' => __( 'Status actually stored, which is not always the one asked for: a "publish" carrying a future date is scheduled by WordPress as "future".', 'wp-mcp-abilities' ),
                    ],
                    'date'    => [
                        'type'        => 'string',
                        'description' => __( 'Publication date actually stored.', 'wp-mcp-abilities' ),
                    ],
                ],
            ],
            'update' => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'link'    => [ 'type' => 'string' ],
                    'status'  => [
                        'type'        => 'string',
                        'description' => __( 'Status actually stored, which is not always the one asked for: a "publish" carrying a future date is scheduled by WordPress as "future".', 'wp-mcp-abilities' ),
                    ],
                    'date'    => [
                        'type'        => 'string',
                        'description' => __( 'Publication date actually stored.', 'wp-mcp-abilities' ),
                    ],
                ],
            ],
            'delete' => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                ],
            ],
        };
    }

    /**
     * Authorises the call, resolving the targeted post when there is one.
     *
     * @inheritDoc
     * @param mixed $input Raw input coming from the MCP adapter.
     */
    public function checkPermission( mixed $input = null ): bool {
        $input = Input::normalize( $input );

        return match ( $this->operation ) {
            'list', 'get' => Capabilities::canRead(),
            'create'      => Capabilities::canCreatePost( $this->postType->name ),
            'update'      => $this->checkTargetedPermission( $input, [ Capabilities::class, 'canEditPost' ] ),
            'delete'      => $this->checkTargetedPermission( $input, [ Capabilities::class, 'canDeletePost' ] ),
        };
    }

    /**
     * @inheritDoc
     * @param mixed $input Raw input coming from the MCP adapter.
     */
    public function execute( mixed $input = null ): array|\WP_Error {
        $input = Input::normalize( $input );

        return match ( $this->operation ) {
            'list'   => $this->list( $input ),
            'get'    => $this->get( $input ),
            'create' => $this->create( $input ),
            'update' => $this->update( $input ),
            'delete' => $this->delete( $input ),
        };
    }

    /**
     * Lists the posts of the target type, paginated.
     *
     * @param array<string, mixed> $input Normalised MCP input.
     * @return array<string, mixed>
     */
    private function list( array $input ): array {
        $perPage = min( max( Input::int( $input, 'per_page', self::DEFAULT_PER_PAGE ), 1 ), self::MAX_PER_PAGE );
        $page    = max( Input::int( $input, 'page', 1 ), 1 );

        $args = [
            'post_type'      => $this->postType->name,
            'post_status'    => [ 'publish', 'draft' ],
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'posts_per_page' => $perPage,
            'paged'          => $page,
        ];

        /**
         * Filters the query arguments used by the list ability of a post type.
         *
         * @param array<string, mixed> $args  Arguments passed to WP_Query.
         * @param array<string, mixed> $input Normalised MCP input.
         */
        $args = (array) apply_filters( 'wpmcpa_list_posts_query_args', $args, $input );

        $query = new \WP_Query( $args );

        $result = [
            'items'    => array_map( [ $this, 'formatPostSummary' ], $query->posts ),
            'total'    => (int) $query->found_posts,
            'page'     => $page,
            'per_page' => $perPage,
        ];

        /**
         * Filters the payload returned by the list ability of a post type.
         *
         * @param array<string, mixed> $result Formatted payload.
         * @param \WP_Post[]           $posts  Raw post objects.
         */
        return (array) apply_filters( 'wpmcpa_list_posts_result', $result, $query->posts );
    }

    /**
     * Retrieves a single post of the target type.
     *
     * @param array<string, mixed> $input Normalised MCP input.
     * @return array<string, mixed>|\WP_Error
     */
    private function get( array $input ): array|\WP_Error {
        $post = $this->resolveTypedPost( Input::int( $input, 'post_id' ) );

        if ( is_wp_error( $post ) ) {
            return $post;
        }

        $terms = $this->taxonomies->postTerms( $post );

        $result = [
            'id'           => $post->ID,
            'title'        => $post->post_title,
            'slug'         => $post->post_name,
            'content'      => $post->post_content,
            'excerpt'      => $post->post_excerpt,
            'status'       => $post->post_status,
            'author_id'    => (int) $post->post_author,
            'date'         => $post->post_date,
            // Derived from the terms just read: querying the categories again
            // would repeat a query already answered.
            'category_ids' => $terms['category'] ?? $this->taxonomies->categoryIds( $post ),
            'terms'        => $terms,
        ];

        /**
         * Filters the payload returned by the get ability of a post type.
         *
         * @param array<string, mixed> $result Formatted payload.
         * @param \WP_Post              $post   Raw post object.
         */
        return (array) apply_filters( 'wpmcpa_get_post_result', $result, $post );
    }

    /**
     * Creates a post of the target type, re-checking capabilities before writing.
     *
     * @param array<string, mixed> $input Normalised MCP input.
     * @return array<string, mixed>|\WP_Error
     */
    private function create( array $input ): array|\WP_Error {
        if ( ! Capabilities::canCreatePost( $this->postType->name ) ) {
            return $this->forbidden();
        }

        $status = Input::string( $input, 'status', 'draft' );

        if ( 'publish' === $status && ! Capabilities::canPublishPost( $this->postType->name ) ) {
            return $this->forbidden();
        }

        if ( isset( $input['author_id'] ) && ! Capabilities::canAssignAuthor( $this->postType->name ) ) {
            return $this->forbidden();
        }

        // Checked before the insert: a refusal raised afterwards would leave an
        // orphan post behind a response that reported a failure.
        $termsCheck = $this->taxonomies->validateTerms( $input );
        if ( is_wp_error( $termsCheck ) ) {
            return $termsCheck;
        }

        $stickyCheck = $this->validateSticky( $input );
        if ( is_wp_error( $stickyCheck ) ) {
            return $stickyCheck;
        }

        $postData = [
            'post_type'   => $this->postType->name,
            'post_status' => $status,
            'post_title'  => Input::string( $input, 'title' ),
        ];

        if ( isset( $input['date'] ) ) {
            $date = $this->resolveDate( Input::string( $input, 'date' ) );

            if ( is_wp_error( $date ) ) {
                return $date;
            }

            $postData += $date;
        }

        if ( isset( $input['content'] ) ) {
            $postData['post_content'] = (string) $input['content'];
        }

        if ( isset( $input['excerpt'] ) ) {
            $postData['post_excerpt'] = (string) $input['excerpt'];
        }

        if ( isset( $input['slug'] ) ) {
            $postData['post_name'] = sanitize_title( Input::string( $input, 'slug' ) );
        }

        if ( isset( $input['category_ids'] ) ) {
            $postData['post_category'] = array_map( 'intval', Input::list( $input, 'category_ids' ) );
        }

        if ( isset( $input['author_id'] ) ) {
            $postData['post_author'] = Input::int( $input, 'author_id' );
        }

        if ( $this->postType->hierarchical && isset( $input['parent_id'] ) ) {
            $postData['post_parent'] = Input::int( $input, 'parent_id' );
        }

        /**
         * Filters the `wp_insert_post()` arguments used by the create ability of a post type.
         *
         * @param array<string, mixed> $postData Arguments passed to wp_insert_post().
         * @param array<string, mixed> $input    Normalised MCP input.
         */
        $postData = (array) apply_filters( 'wpmcpa_create_post_data', $postData, $input );

        // Slashed at the very last moment, so filters see the raw values.
        // wp_insert_post() expects escaped data and eats one level of
        // backslashes otherwise, as the template abilities already account for.
        $postId = wp_insert_post( wp_slash( $postData ), true );

        if ( is_wp_error( $postId ) ) {
            return $postId;
        }

        $terms = $this->taxonomies->applyTerms( $postId, $input );
        if ( is_wp_error( $terms ) ) {
            return $terms;
        }

        $this->applySticky( $postId, $input );

        // Read back rather than echoed: wp_insert_post() turns a `publish`
        // carrying a future date into `future`, and a caller told `success`
        // with nothing else would believe its post is online.
        return [
            'success' => true,
            'post_id' => $postId,
            'link'    => get_permalink( $postId ),
            'status'  => (string) get_post_status( $postId ),
            'date'    => (string) get_post_field( 'post_date', $postId ),
        ];
    }

    /**
     * Pins or unpins a post, when the post type is the one that knows how to be pinned.
     *
     * @param int                  $postId Post to pin or unpin.
     * @param array<string, mixed> $input  Normalised MCP input.
     */
    private function applySticky( int $postId, array $input ): void {
        if ( 'post' !== $this->postType->name || ! isset( $input['sticky'] ) ) {
            return;
        }

        if ( Input::bool( $input, 'sticky' ) ) {
            stick_post( $postId );

            return;
        }

        unstick_post( $postId );
    }

    /**
     * Checks the capability pinning a post demands, before anything is written.
     *
     * `sticky_posts` is a site-wide option, not a property of the post, so
     * `edit_post` on one's own draft is not enough to write into it — the core
     * REST controller refuses with `rest_cannot_assign_sticky` unless the user
     * holds `edit_others_posts` or `publish_posts`. Without the same guard a
     * contributor could pin their own draft and have it surface on the front
     * page the moment an editor published it, which nobody decided.
     *
     * Only pinning is guarded, as in core: unpinning is not a way in.
     *
     * @param array<string, mixed> $input Normalised MCP input.
     * @return true|\WP_Error
     */
    private function validateSticky( array $input ): bool|\WP_Error {
        if ( 'post' !== $this->postType->name || ! isset( $input['sticky'] ) ) {
            return true;
        }

        if ( ! Input::bool( $input, 'sticky' ) ) {
            return true;
        }

        if ( current_user_can( $this->postType->cap->edit_others_posts )
            || current_user_can( $this->postType->cap->publish_posts ) ) {
            return true;
        }

        return new \WP_Error(
            'cannot_assign_sticky',
            __( 'You are not allowed to pin a post.', 'wp-mcp-abilities' ),
            [ 'status' => 403 ]
        );
    }

    /**
     * Updates a post of the target type, re-checking capabilities before writing.
     *
     * @param array<string, mixed> $input Normalised MCP input.
     * @return array<string, mixed>|\WP_Error
     */
    private function update( array $input ): array|\WP_Error {
        $post = $this->resolveTypedPost( Input::int( $input, 'post_id' ) );

        if ( is_wp_error( $post ) ) {
            return $post;
        }

        if ( ! Capabilities::canEditPost( $post ) ) {
            return $this->forbidden();
        }

        if ( isset( $input['status'] ) && 'publish' === $input['status']
            && ! Capabilities::canPublishPost( $this->postType->name ) ) {
            return $this->forbidden();
        }

        if ( isset( $input['author_id'] ) && ! Capabilities::canAssignAuthor( $this->postType->name ) ) {
            return $this->forbidden();
        }

        $termsCheck = $this->taxonomies->validateTerms( $input );
        if ( is_wp_error( $termsCheck ) ) {
            return $termsCheck;
        }

        $stickyCheck = $this->validateSticky( $input );
        if ( is_wp_error( $stickyCheck ) ) {
            return $stickyCheck;
        }

        $updateData = [ 'ID' => $post->ID ];

        $fields = [
            'title'   => 'post_title',
            'content' => 'post_content',
            'excerpt' => 'post_excerpt',
            'status'  => 'post_status',
        ];

        foreach ( $fields as $inputKey => $postKey ) {
            if ( isset( $input[ $inputKey ] ) ) {
                $updateData[ $postKey ] = (string) $input[ $inputKey ];
            }
        }

        if ( isset( $input['slug'] ) ) {
            $updateData['post_name'] = sanitize_title( Input::string( $input, 'slug' ) );
        }

        if ( isset( $input['category_ids'] ) ) {
            $updateData['post_category'] = array_map( 'intval', Input::list( $input, 'category_ids' ) );
        }

        if ( isset( $input['author_id'] ) ) {
            $updateData['post_author'] = Input::int( $input, 'author_id' );
        }

        if ( $this->postType->hierarchical && isset( $input['parent_id'] ) ) {
            $updateData['post_parent'] = Input::int( $input, 'parent_id' );
        }

        if ( isset( $input['date'] ) ) {
            $date = $this->resolveDate( Input::string( $input, 'date' ) );

            if ( is_wp_error( $date ) ) {
                return $date;
            }

            $updateData += $date;
        }

        /**
         * Filters the `wp_update_post()` arguments used by the update ability of a post type.
         *
         * @param array<string, mixed> $updateData Arguments passed to wp_update_post().
         * @param \WP_Post              $post       Post being updated.
         * @param array<string, mixed> $input      Normalised MCP input.
         */
        $updateData = (array) apply_filters( 'wpmcpa_update_post_data', $updateData, $post, $input );

        // Same escaping as on creation: without it, rewriting a page through
        // MCP silently degrades every backslash its markup carries.
        $result = wp_update_post( wp_slash( $updateData ), true );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $terms = $this->taxonomies->applyTerms( $post->ID, $input );
        if ( is_wp_error( $terms ) ) {
            return $terms;
        }

        $this->applySticky( $post->ID, $input );

        return [
            'success' => true,
            'link'    => get_permalink( $post->ID ),
            'status'  => (string) get_post_status( $post->ID ),
            'date'    => (string) get_post_field( 'post_date', $post->ID ),
        ];
    }

    /**
     * Deletes a post of the target type, re-checking capabilities before writing.
     *
     * @param array<string, mixed> $input Normalised MCP input.
     * @return array<string, mixed>|\WP_Error
     */
    private function delete( array $input ): array|\WP_Error {
        $post = $this->resolveTypedPost( Input::int( $input, 'post_id' ) );

        if ( is_wp_error( $post ) ) {
            return $post;
        }

        if ( ! Capabilities::canDeletePost( $post ) ) {
            return $this->forbidden();
        }

        $force  = Input::bool( $input, 'force' );
        $result = wp_delete_post( $post->ID, $force );

        if ( ! $result ) {
            return new \WP_Error(
                'delete_failed',
                __( 'Unable to delete the content.', 'wp-mcp-abilities' ),
                [ 'status' => 500 ]
            );
        }

        return [ 'success' => true ];
    }

    /**
     * Resolves a post and makes sure it belongs to this ability's post type.
     *
     * @param int $postId Post ID.
     * @return \WP_Post|\WP_Error
     */
    private function resolveTypedPost( int $postId ): \WP_Post|\WP_Error {
        $post = $this->resolvePost( $postId );

        if ( is_wp_error( $post ) ) {
            return $post;
        }

        if ( $post->post_type !== $this->postType->name ) {
            return new \WP_Error(
                'post_not_found',
                __( 'Post not found.', 'wp-mcp-abilities' ),
                [ 'status' => 404 ]
            );
        }

        return $post;
    }

    /**
     * Runs a capability check against the post resolved from the input.
     *
     * @param array<string, mixed> $input           Normalised MCP input.
     * @param callable             $capabilityCheck Callable receiving the resolved WP_Post.
     */
    private function checkTargetedPermission( array $input, callable $capabilityCheck ): bool {
        $post = $this->resolveTypedPost( Input::int( $input, 'post_id' ) );

        if ( is_wp_error( $post ) ) {
            return false;
        }

        return (bool) $capabilityCheck( $post );
    }

    /**
     * Resolves the `date` input into the pair wp_insert_post() expects.
     *
     * An unparseable date used to travel straight into `post_date`, where
     * WordPress turned it into a zeroed timestamp: the write succeeded and the
     * post came out dated year zero. Refusing is the honest answer.
     *
     * @param string $date Raw date, expected as YYYY-MM-DD HH:MM:SS in site time.
     * @return array{post_date: string, post_date_gmt: string}|\WP_Error
     */
    private function resolveDate( string $date ): array|\WP_Error {
        // Parsed strictly, and only accepted when it survives the round trip
        // unchanged. The obvious guards do not hold here: `strtotime()` reads
        // `0000-00-00 00:00:00` as a real timestamp rather than returning
        // false, and `get_gmt_from_date()` never returns an empty string — it
        // falls back on the epoch. That sentinel, which any import or export
        // hands out for an undated draft, would therefore sail through and
        // land as a `post_date_gmt` of `-0001-11-30 00:00:00`: the very zeroed
        // timestamp this method exists to refuse, moved to the next column.
        $parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $date, wp_timezone() );

        if ( ! $parsed instanceof \DateTimeImmutable || $parsed->format( 'Y-m-d H:i:s' ) !== $date ) {
            return new \WP_Error(
                'invalid_date',
                __( 'The "date" parameter could not be read. Expected format: YYYY-MM-DD HH:MM:SS.', 'wp-mcp-abilities' ),
                [ 'status' => 400 ]
            );
        }

        return [
            'post_date'     => $date,
            'post_date_gmt' => $parsed->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
        ];
    }

    /**
     * Formats a post for the list ability payload.
     *
     * @param \WP_Post $post Post to format.
     * @return array<string, mixed>
     */
    private function formatPostSummary( \WP_Post $post ): array {
        return [
            'id'            => $post->ID,
            'title'         => $post->post_title,
            'slug'          => $post->post_name,
            'status'        => $post->post_status,
            'date_modified' => $post->post_modified,
            'link'          => get_permalink( $post ),
            'author_id'     => (int) $post->post_author,
            'category_ids'  => $this->taxonomies->categoryIds( $post ),
        ];
    }

    /**
     * Slug fragment used to build the ability name.
     *
     * Lists operate on the plural (`list-formations`), everything else targets
     * a single object and uses the singular (`get-formation`, `create-formation`…).
     *
     * @return string
     */
    private function nameSlug(): string {
        $slug = 'list' === $this->operation ? $this->pluralSlug() : $this->postType->name;

        return self::sanitizeNameSegment( $slug );
    }

    /**
     * Resolves the plural slug used by the list ability name.
     *
     * @return string
     */
    private function pluralSlug(): string {
        if ( ! empty( $this->postType->rest_base ) ) {
            return $this->postType->rest_base;
        }

        return $this->postType->name . 's';
    }

    /**
     * Makes a post type slug usable inside an ability name.
     *
     * `WP_Abilities_Registry::register()` only accepts `[a-z0-9-]` on each side
     * of the slash and returns null otherwise. Post type slugs commonly carry
     * underscores (`book_review`, `faq_entry`), which would silently
     * prevent every ability of that post type from being registered.
     *
     * @param string $segment Raw slug fragment.
     * @return string
     */
    private static function sanitizeNameSegment( string $segment ): string {
        $segment = str_replace( '_', '-', strtolower( $segment ) );
        $segment = preg_replace( '/[^a-z0-9-]/', '', $segment );

        return trim( (string) $segment, '-' );
    }

    /**
     * Input schema of the `list` operation.
     *
     * @return array<string, mixed>
     */
    private function listInputSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'per_page' => [
                    'type'        => 'integer',
                    'description' => __( 'Number of items per page.', 'wp-mcp-abilities' ),
                    'minimum'     => 1,
                    'maximum'     => self::MAX_PER_PAGE,
                    'default'     => self::DEFAULT_PER_PAGE,
                ],
                'page'     => [
                    'type'        => 'integer',
                    'description' => __( 'Page to return, starting at 1.', 'wp-mcp-abilities' ),
                    'minimum'     => 1,
                    'default'     => 1,
                ],
            ],
        ];
    }

    /**
     * Input schema of the `get` and `delete` operations.
     *
     * @param bool $withForce Whether to expose the `force` (skip trash) property.
     * @return array<string, mixed>
     */
    private function postIdInputSchema( bool $withForce ): array {
        $properties = [
            'post_id' => [
                'type'        => 'integer',
                'description' => sprintf(
                    // translators: %s: singular label of the post type (e.g. "Post").
                    __( 'The %s ID.', 'wp-mcp-abilities' ),
                    $this->postType->labels->singular_name
                ),
            ],
        ];

        if ( $withForce ) {
            $properties['force'] = [
                'type'        => 'boolean',
                'description' => __( 'Permanently delete (skip trash).', 'wp-mcp-abilities' ),
                'default'     => false,
            ];
        }

        return [
            'type'       => 'object',
            'properties' => $properties,
            'required'   => [ 'post_id' ],
        ];
    }

    /**
     * Input schema of the `create` and `update` operations.
     *
     * @param bool $isCreate Whether the schema is built for the `create` operation.
     * @return array<string, mixed>
     */
    private function writeInputSchema( bool $isCreate ): array {
        $label      = $this->postType->labels->singular_name;
        $properties = [];

        if ( ! $isCreate ) {
            $properties['post_id'] = [
                'type'        => 'integer',
                // translators: %s: singular label of the post type (e.g. "Post").
                'description' => sprintf( __( 'The %s ID.', 'wp-mcp-abilities' ), $label ),
            ];
        }

        $properties['title'] = [
            'type'        => 'string',
            // translators: %s: singular label of the post type (e.g. "Post").
            'description' => sprintf( __( 'The %s title.', 'wp-mcp-abilities' ), $label ),
        ];

        $properties['content'] = [
            'type'        => 'string',
            // translators: %s: singular label of the post type (e.g. "Post").
            'description' => sprintf( __( 'The HTML content of the %s.', 'wp-mcp-abilities' ), $label ),
        ];

        $properties['excerpt'] = [
            'type'        => 'string',
            // translators: %s: singular label of the post type (e.g. "Post").
            'description' => sprintf( __( 'The %s excerpt.', 'wp-mcp-abilities' ), $label ),
        ];

        $properties['slug'] = [
            'type'        => 'string',
            'description' => __(
                'The URL slug (post_name). Auto-generated from the title if omitted.',
                'wp-mcp-abilities'
            ),
        ];

        $properties['status'] = [
            'type'        => 'string',
            'description' => __( 'The status (draft, publish).', 'wp-mcp-abilities' ),
            'enum'        => [ 'draft', 'publish' ],
        ];

        if ( $isCreate ) {
            $properties['status']['default'] = 'draft';
        }

        if ( $this->postType->hierarchical ) {
            $properties['parent_id'] = [
                'type'        => 'integer',
                // translators: %s: singular label of the post type (e.g. "Post").
                'description' => sprintf( __( 'The parent %s ID.', 'wp-mcp-abilities' ), $label ),
            ];
        }

        if ( is_object_in_taxonomy( $this->postType->name, 'category' ) ) {
            $properties['category_ids'] = [
                'type'        => 'array',
                'items'       => [ 'type' => 'integer' ],
                'description' => __( 'The IDs of categories to assign. Equivalent to the "category" entry of "terms".', 'wp-mcp-abilities' ),
            ];
        }

        $termProperties = $this->taxonomies->assignableTaxonomySchema();

        if ( [] !== $termProperties ) {
            $properties['terms'] = [
                'type'                 => 'object',
                'properties'           => $termProperties,
                'additionalProperties' => false,
                'description'          => __(
                    'Term IDs to assign, keyed by taxonomy. Each taxonomy sent replaces the terms already assigned in it; a taxonomy left out is untouched, and an empty array clears it.',
                    'wp-mcp-abilities'
                ),
            ];
        }

        $properties['author_id'] = [
            'type'        => 'integer',
            'description' => sprintf(
                // translators: %s: singular label of the post type (e.g. "Post").
                __( 'The user ID to assign as author of the %s.', 'wp-mcp-abilities' ),
                $label
            ),
        ];

        // Dating and pinning belong to creation as much as to editing. Kept to
        // `update` only, importing a backlog cost one call per item to create
        // it and a second to date it, and a `date` sent to `create` was
        // dropped without a word: the adapter discards what the schema does
        // not declare, so the post came out stamped today and the response
        // said nothing.
        $properties['date'] = [
            'type'        => 'string',
            'description' => __( 'The publication date (YYYY-MM-DD HH:MM:SS), in site time.', 'wp-mcp-abilities' ),
        ];

        if ( 'post' === $this->postType->name ) {
            $properties['sticky'] = [
                'type'        => 'boolean',
                'description' => __( 'Pin as sticky (true) or unpin (false).', 'wp-mcp-abilities' ),
            ];
        }

        return [
            'type'       => 'object',
            'properties' => $properties,
            'required'   => $isCreate ? [ 'title' ] : [ 'post_id' ],
        ];
    }

    /**
     * Output schema of the `list` operation.
     *
     * @return array<string, mixed>
     */
    private function listOutputSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'items'    => [
                    'type'  => 'array',
                    'items' => $this->postSchema( false ),
                ],
                'total'    => [ 'type' => 'integer' ],
                'page'     => [ 'type' => 'integer' ],
                'per_page' => [ 'type' => 'integer' ],
            ],
        ];
    }

    /**
     * Output schema of a single post, shared by `list` (summary) and `get` (full).
     *
     * @param bool $withContent Whether to include content/excerpt (`get`) instead of link/date (`list`).
     * @return array<string, mixed>
     */
    private function postSchema( bool $withContent ): array {
        $properties = [
            'id'           => [ 'type' => 'integer' ],
            'title'        => [ 'type' => 'string' ],
            'slug'         => [ 'type' => 'string' ],
            'status'       => [ 'type' => 'string' ],
            'author_id'    => [ 'type' => 'integer' ],
            'category_ids' => [
                'type'  => 'array',
                'items' => [ 'type' => 'integer' ],
            ],
        ];

        if ( $withContent ) {
            $properties['content'] = [ 'type' => 'string' ];
            $properties['excerpt'] = [ 'type' => 'string' ];
            $properties['date']    = [ 'type' => 'string' ];

            $termProperties = $this->taxonomies->assignableTaxonomySchema();

            if ( [] !== $termProperties ) {
                $properties['terms'] = [
                    'type'        => 'object',
                    'properties'  => $termProperties,
                    'description' => __( 'Term IDs assigned to the post, keyed by taxonomy.', 'wp-mcp-abilities' ),
                ];
            }
        } else {
            $properties['date_modified'] = [ 'type' => 'string' ];
            $properties['link']          = [ 'type' => 'string' ];
        }

        return [
            'type'       => 'object',
            'properties' => $properties,
        ];
    }
}
