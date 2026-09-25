<?php
/**
 * Warns when a dependency the plugin needs is missing.
 *
 * @package WpMcpAbilities\Hooks
 */

namespace WpMcpAbilities\Hooks;

use WpMcpAbilities\Contracts\HookInterface;
use WpMcpAbilities\Services\SettingsService;
use WpMcpAbilities\Support\Requirements;

defined( 'ABSPATH' ) || exit;

/**
 * Prints an admin notice listing what has to be installed.
 *
 * Without the Abilities API, `wp_abilities_api_init` is never fired: nothing
 * is registered, so the notice is an error. Without the MCP Adapter, the
 * abilities exist and stay usable through the Abilities API, but no MCP client
 * can reach them, so the notice is only a warning. Both cases are otherwise
 * silent, hence this notice.
 *
 * The error shows on every admin screen. The warning only shows where it can
 * be acted upon, on the Plugins screen and on the plugin's settings screen,
 * so that a site deliberately running without MCP is not nagged everywhere.
 */
class RequirementsNotice implements HookInterface {
    /**
     * @inheritDoc
     */
    public function hooks(): void {
        add_action( 'admin_notices', [ $this, 'render' ] );
        add_action( 'network_admin_notices', [ $this, 'render' ] );
    }

    /**
     * Renders the notice when something is missing.
     *
     * @return void
     */
    public function render(): void {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }

        $missing = Requirements::missing();

        if ( ! $this->isWarningScreen() ) {
            $missing = array_values(
                array_filter(
                    $missing,
                    static fn ( array $requirement ): bool => 'error' === $requirement['severity']
                )
            );
        }

        if ( [] === $missing ) {
            return;
        }

        $is_error = in_array( 'error', array_column( $missing, 'severity' ), true );
        ?>
        <div class="notice <?php echo esc_attr( $is_error ? 'notice-error' : 'notice-warning' ); ?>">
            <p>
                <strong>
                    <?php
                    echo esc_html(
                        $is_error
                            ? __( 'WP MCP Abilities cannot register its abilities.', 'wp-mcp-abilities' )
                            : __( 'WP MCP Abilities is not reachable by MCP clients yet.', 'wp-mcp-abilities' )
                    );
                    ?>
                </strong>
            </p>
            <?php foreach ( $missing as $requirement ) : ?>
                <p>
                    <strong><?php echo esc_html( $requirement['title'] ); ?></strong><br />
                    <?php echo esc_html( $requirement['detail'] ); ?>
                    <?php if ( ! empty( $requirement['command'] ) ) : ?>
                        <br /><code><?php echo esc_html( $requirement['command'] ); ?></code>
                    <?php endif; ?>
                    <?php if ( ! empty( $requirement['url'] ) ) : ?>
                        <br /><a href="<?php echo esc_url( $requirement['url'] ); ?>"><?php echo esc_html__( 'Download the MCP Adapter', 'wp-mcp-abilities' ); ?></a>
                    <?php endif; ?>
                </p>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Whether the current screen is one where the adapter warning belongs.
     *
     * The settings screen id is derived the way `add_options_page()` builds
     * its hook suffix, from the parent file and the page slug.
     *
     * @return bool
     */
    private function isWarningScreen(): bool {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

        if ( null === $screen ) {
            return false;
        }

        if ( ! function_exists( 'get_plugin_page_hookname' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $screens = [
            'plugins',
            'plugins-network',
            get_plugin_page_hookname( SettingsService::PAGE_SLUG, 'options-general.php' ),
        ];

        return in_array( $screen->id, $screens, true );
    }
}
