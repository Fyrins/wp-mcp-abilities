<?php
/**
 * Replaces a literal string inside the content of a post.
 *
 * @package WpMcpAbilities\Abilities\Content
 */

namespace WpMcpAbilities\Abilities\Content;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Capabilities;
use WpMcpAbilities\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Edits a post in place, without the caller ever holding the whole content.
 *
 * The update abilities take the full content, which forces an agent wanting to
 * change three words in a long page to read it, reproduce it and send it back.
 * On a page of a few hundred kilobytes that round trip is expensive and, above
 * all, one silent transcription slip is enough to lose part of the content.
 * Here the caller sends only what it is looking for and what replaces it.
 *
 * Two guards make the operation predictable:
 *
 * - `expected_occurrences` states how many matches the caller believes are
 *   there. A different count means the content is not what it thought, and
 *   nothing is written.
 * - `dry_run` counts and measures without writing, to check a pattern before
 *   committing to it.
 *
 * The search is a literal string, never a regular expression: block markup is
 * full of characters a pattern would interpret, and an agent building a regex
 * from a description is exactly the kind of blunt instrument this ability
 * exists to avoid.
 *
 * Literal also means untouched. `search` and `replace` reach `str_replace()`
 * byte for byte, tags, HTML comments, newlines and runs of spaces included, so
 * a caller can anchor on `<h1 class="wp-block-heading">` or on the newline
 * closing a block and get back what it asked for. Sanitising either of them
 * would silently answer a different question while still reporting a plausible
 * count — which is worse than refusing, because nothing in the response says
 * so. What the caller may write is settled by the `edit_post` check above and
 * by kses on the way into the database, not by deforming the needle.
 */
class ReplaceInPostContentAbility extends AbstractAbility {
    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'replace-in-post-content' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'Replace in post content', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __(
            'Replaces a literal string inside the content of a post, without resending the whole content. Supports a dry run and an expected number of occurrences.',
            'wp-mcp-abilities'
        );
    }

    /**
     * @inheritDoc
     */
    public function getGroup(): string {
        return __( 'Content', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'post_id'              => [
                    'type'        => 'integer',
                    'description' => __( 'Identifier of the post to edit.', 'wp-mcp-abilities' ),
                ],
                'search'               => [
                    'type'        => 'string',
                    'minLength'   => 1,
                    'description' => __( 'Literal string to look for, matched byte for byte. Not a regular expression. Tags, attributes, HTML comments and newlines are kept as sent, so a block boundary or an opening tag with its attributes can be used as an anchor.', 'wp-mcp-abilities' ),
                ],
                'replace'              => [
                    'type'        => 'string',
                    'description' => __( 'String to put in its place, written as sent. An empty string deletes the match.', 'wp-mcp-abilities' ),
                ],
                'expected_occurrences' => [
                    'type'        => 'integer',
                    'minimum'     => 0,
                    'description' => __( 'Number of matches the caller expects. Nothing is written when the actual count differs.', 'wp-mcp-abilities' ),
                ],
                'dry_run'              => [
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => __( 'Counts and measures without writing anything.', 'wp-mcp-abilities' ),
                ],
            ],
            'required'   => [ 'post_id', 'search', 'replace' ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function getOutputSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'success'        => [ 'type' => 'boolean' ],
                'post_id'        => [ 'type' => 'integer' ],
                'occurrences'    => [
                    'type'        => 'integer',
                    'description' => __( 'Matches found in the content.', 'wp-mcp-abilities' ),
                ],
                'replaced'       => [
                    'type'        => 'boolean',
                    'description' => __( 'True when the content was actually written.', 'wp-mcp-abilities' ),
                ],
                'dry_run'        => [ 'type' => 'boolean' ],
                'length_before'  => [ 'type' => 'integer' ],
                'length_after'   => [
                    'type'        => 'integer',
                    'description' => __( 'Length read back from the database after writing, or the projected length on a dry run.', 'wp-mcp-abilities' ),
                ],
                'content_intact' => [
                    'type'        => 'boolean',
                    'description' => __( 'False when what was stored differs from what was submitted, which points to kses having stripped markup the caller is not allowed to write.', 'wp-mcp-abilities' ),
                ],
            ],
        ];
    }

    /**
     * Authorises against the very post being edited.
     *
     * @inheritDoc
     * @param mixed $input Raw input coming from the MCP adapter.
     */
    public function checkPermission( mixed $input = null ): bool {
        $input = Input::normalize( $input );
        $post  = $this->resolvePost( Input::int( $input, 'post_id' ) );

        if ( is_wp_error( $post ) ) {
            return false;
        }

        return Capabilities::canEditPost( $post );
    }

    /**
     * @inheritDoc
     * @param mixed $input Raw input coming from the MCP adapter.
     */
    public function execute( mixed $input = null ): array|\WP_Error {
        $input = Input::normalize( $input );
        $post  = $this->resolvePost( Input::int( $input, 'post_id' ) );

        if ( is_wp_error( $post ) ) {
            return $post;
        }

        if ( ! Capabilities::canEditPost( $post ) ) {
            return $this->forbidden();
        }

        // Read as sent, never sanitised: the needle is markup, and rewriting
        // it would make every count and every replacement below answer a
        // question the caller never asked. See Input::literal().
        if ( ! Input::hasScalar( $input, 'search' ) ) {
            return new \WP_Error(
                'missing_search',
                __( 'The "search" parameter is required.', 'wp-mcp-abilities' ),
                [ 'status' => 400 ]
            );
        }

        $search = Input::literal( $input, 'search' );
        if ( '' === $search ) {
            return new \WP_Error(
                'empty_search',
                __( 'The "search" parameter was sent as an empty string. Nothing was looked for.', 'wp-mcp-abilities' ),
                [ 'status' => 400 ]
            );
        }

        $replace = Input::literal( $input, 'replace' );
        $dryRun  = Input::bool( $input, 'dry_run' );

        $before      = $post->post_content;
        $occurrences = substr_count( $before, $search );

        // A stated expectation is a contract: a different count means the
        // content is not the one the caller had in mind, so nothing is written.
        if ( isset( $input['expected_occurrences'] ) ) {
            $expected = Input::int( $input, 'expected_occurrences' );

            if ( $expected !== $occurrences ) {
                return new \WP_Error(
                    'unexpected_occurrences',
                    sprintf(
                        /* translators: 1: expected number of matches, 2: number actually found. */
                        __( '%1$d matches expected, %2$d found. Nothing was written.', 'wp-mcp-abilities' ),
                        $expected,
                        $occurrences
                    ),
                    [
                        'status'      => 409,
                        'occurrences' => $occurrences,
                    ]
                );
            }
        }

        $after = 0 === $occurrences ? $before : str_replace( $search, $replace, $before );

        if ( $dryRun || 0 === $occurrences ) {
            return [
                'success'        => true,
                'post_id'        => $post->ID,
                'occurrences'    => $occurrences,
                'replaced'       => false,
                'dry_run'        => $dryRun,
                'length_before'  => strlen( $before ),
                'length_after'   => strlen( $after ),
                'content_intact' => true,
            ];
        }

        /**
         * Filters the content about to replace the one stored for the post.
         *
         * @param string   $after  Content after replacement.
         * @param \WP_Post $post   Post being edited.
         * @param string   $search Literal string looked for.
         */
        $after = (string) apply_filters( 'wpmcpa_replaced_post_content', $after, $post, $search );

        // wp_update_post() expects slashed data and strips one level of
        // backslashes otherwise, which would quietly turn the unicode escapes
        // block attributes are full of into a bare `u002d`, breaking every CSS
        // variable written that way.
        $result = wp_update_post(
            wp_slash(
                [
                    'ID'           => $post->ID,
                    'post_content' => $after,
                ]
            ),
            true
        );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $stored = (string) get_post_field( 'post_content', $post->ID );

        return [
            'success'        => true,
            'post_id'        => $post->ID,
            'occurrences'    => $occurrences,
            'replaced'       => true,
            'dry_run'        => false,
            'length_before'  => strlen( $before ),
            'length_after'   => strlen( $stored ),
            'content_intact' => $stored === $after,
        ];
    }
}
