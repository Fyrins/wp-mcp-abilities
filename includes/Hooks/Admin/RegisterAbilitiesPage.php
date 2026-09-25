<?php
/**
 * Adds the settings page holding the ability switches.
 *
 * @package WpMcpAbilities\Hooks\Admin
 */

namespace WpMcpAbilities\Hooks\Admin;

use WpMcpAbilities\Contracts\HookInterface;
use WpMcpAbilities\Services\AbilityCatalogService;
use WpMcpAbilities\Services\SettingsService;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the page and renders its shell.
 *
 * The fields themselves are declared by RegisterAbilitiesSettings through the
 * Settings API, so this class only owns the menu entry and the form wrapper.
 */
class RegisterAbilitiesPage implements HookInterface {
    /**
     * @param AbilityCatalogService $catalog Inventory of the available abilities.
     */
    public function __construct( private AbilityCatalogService $catalog ) {
    }

    /**
     * @inheritDoc
     */
    public function hooks(): void {
        add_action( 'admin_menu', [ $this, 'registerPage' ] );
    }

    /**
     * Adds the page under the Settings menu.
     *
     * @return void
     */
    public function registerPage(): void {
        add_options_page(
            __( 'MCP Abilities', 'wp-mcp-abilities' ),
            __( 'MCP Abilities', 'wp-mcp-abilities' ),
            SettingsService::CAPABILITY,
            SettingsService::PAGE_SLUG,
            [ $this, 'renderPage' ]
        );
    }

    /**
     * Renders the page.
     *
     * @return void
     */
    public function renderPage(): void {
        if ( ! current_user_can( SettingsService::CAPABILITY ) ) {
            return;
        }

        if ( [] === $this->catalog->all() ) {
            $this->renderEmptyState();

            return;
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'MCP Abilities', 'wp-mcp-abilities' ); ?></h1>
            <p>
                <?php
                echo esc_html__(
                    'Pick the abilities exposed to MCP clients. Destructive abilities are off until you enable them.',
                    'wp-mcp-abilities'
                );
                ?>
            </p>
            <form action="options.php" method="post">
                <?php
                settings_fields( SettingsService::PAGE_SLUG );
                do_settings_sections( SettingsService::PAGE_SLUG );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Renders the page when no ability is available at all.
     *
     * @return void
     */
    private function renderEmptyState(): void {
        printf(
            '<div class="wrap"><h1>%s</h1><p>%s</p></div>',
            esc_html__( 'MCP Abilities', 'wp-mcp-abilities' ),
            esc_html__( 'No ability is available on this site.', 'wp-mcp-abilities' )
        );
    }
}
