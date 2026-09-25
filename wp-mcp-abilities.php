<?php
/**
 * Plugin Name:       WP MCP Abilities
 * Description:       Content management abilities for AI agents, exposed through the WordPress Abilities API and the MCP Adapter.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            Alexandre Revire
 * Author URI:        https://alexandre-revire.fr
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-mcp-abilities
 * Domain Path:       /languages
 *
 * @package WpMcpAbilities
 */

namespace WpMcpAbilities;

defined( 'ABSPATH' ) || exit;

define( 'WPMCPA_VERSION', '1.0.0' );
define( 'WPMCPA_FILE', __FILE__ );
define( 'WPMCPA_DIR', __DIR__ );

require_once __DIR__ . '/includes/Autoloader.php';

Autoloader::register();
Plugin::boot();
