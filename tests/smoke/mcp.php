<?php
/**
 * With the adapter, the default MCP server lists and runs the abilities.
 *
 * @package WpMcpAbilities
 */

require __DIR__ . '/assert.php';

use WpMcpAbilities\Support\Requirements;

if ( ! Requirements::hasMcpAdapter() ) {
    fwrite( STDOUT, "  skip the MCP Adapter is not active\n" );
    exit( 0 );
}

wpmcpa_assert( Requirements::hasMcpAdapter(), 'adapter is detected' );
wpmcpa_assert( [] === Requirements::missing(), 'no requirement is missing' );

wp_set_current_user( 1 );
$ability = wp_get_ability( 'wp-mcp-abilities/list-media' );
wpmcpa_assert( null !== $ability, 'list-media exists' );
$result = $ability ? $ability->execute( [] ) : null;
wpmcpa_assert( ! is_wp_error( $result ) && is_array( $result ), 'list-media executes' );

$replace = wp_get_ability( 'wp-mcp-abilities/replace-in-post-content' );
wpmcpa_assert( null !== $replace, 'replace-in-post-content exists' );
$schema = $replace ? $replace->get_input_schema() : null;
wpmcpa_assert( is_array( $schema ), 'replace-in-post-content has an input schema' );
fwrite( STDOUT, '  info input schema: ' . wp_json_encode( array_keys( is_array( $schema ) ? ( $schema['properties'] ?? [] ) : [] ) ) . "\n" );

$post_id = wp_insert_post( [ 'post_title' => 'MCP smoke', 'post_content' => 'Hello smoke', 'post_status' => 'draft' ] );
wpmcpa_assert( is_int( $post_id ) && $post_id > 0, 'test post is created' );
if ( is_int( $post_id ) && $post_id > 0 ) {
    try {
        $replaced = $replace ? $replace->execute( [ 'post_id' => $post_id, 'search' => 'Hello', 'replace' => 'Bye' ] ) : null;
        wpmcpa_assert( null !== $replaced && ! is_wp_error( $replaced ), 'replace-in-post-content executes' );
        clean_post_cache( $post_id );
        $post    = get_post( $post_id );
        $content = $post instanceof WP_Post ? $post->post_content : '';
        wpmcpa_assert( str_contains( $content, 'Bye' ), 'replace-in-post-content writes the replacement' );
        wpmcpa_assert( $post instanceof WP_Post && ! str_contains( $content, 'Hello' ), 'replace-in-post-content removes the search string' );
    } finally {
        wp_delete_post( $post_id, true );
    }
}

wpmcpa_assert_done();
