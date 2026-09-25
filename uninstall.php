<?php
/**
 * Removes the plugin's options.
 *
 * @package WpMcpAbilities
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wpmcpa_enabled' );
delete_option( 'wpmcpa_exposed' );
