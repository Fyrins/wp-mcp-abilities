<?php
/**
 * Declares the ability switches through the Settings API.
 *
 * @package WpMcpAbilities\Hooks\Admin
 */

namespace WpMcpAbilities\Hooks\Admin;

use WpMcpAbilities\Contracts\HookInterface;
use WpMcpAbilities\Services\AbilityCatalogService;
use WpMcpAbilities\Services\SettingsService;
use WpMcpAbilities\Services\ThirdPartyAbilityCatalog;
use WpMcpAbilities\Support\AbstractAbility;

defined( 'ABSPATH' ) || exit;

/**
 * One section per ability group, one field per ability.
 *
 * The switches live in a single option keyed by ability name rather than in one
 * option per ability: the list is built at runtime and currently counts a few
 * dozen entries.
 */
class RegisterAbilitiesSettings implements HookInterface {
    /**
     * Prefix of the generated section identifiers.
     *
     * @var string
     */
    private const SECTION_PREFIX = 'wpmcpa_';

    /**
     * @param AbilityCatalogService    $catalog     Inventory of the abilities of this plugin.
     * @param ThirdPartyAbilityCatalog $thirdParty  Inventory of the abilities of other plugins.
     * @param SettingsService          $settings    Reader of the switches.
     */
    public function __construct(
        private AbilityCatalogService $catalog,
        private ThirdPartyAbilityCatalog $thirdParty,
        private SettingsService $settings
    ) {
    }

    /**
     * @inheritDoc
     */
    public function hooks(): void {
        add_action( 'admin_init', [ $this, 'registerSettings' ] );

        // Ties what options.php requires to write the option to what the screen
        // requires to be opened, so lowering one never silently leaves the other
        // behind.
        add_filter(
            'option_page_capability_' . SettingsService::PAGE_SLUG,
            static fn (): string => SettingsService::CAPABILITY
        );
    }

    /**
     * Registers the option, its sections and its fields.
     *
     * @return void
     */
    public function registerSettings(): void {
        register_setting(
            SettingsService::PAGE_SLUG,
            SettingsService::OPTION_KEY,
            [
                'type'              => 'object',
                'default'           => [],
                'sanitize_callback' => [ $this, 'sanitize' ],
                'show_in_rest'      => false,
            ]
        );

        register_setting(
            SettingsService::PAGE_SLUG,
            SettingsService::EXPOSED_OPTION_KEY,
            [
                'type'              => 'object',
                'default'           => [],
                'sanitize_callback' => [ $this, 'sanitizeExposed' ],
                'show_in_rest'      => false,
            ]
        );

        // Section and field titles are echoed unescaped by the Settings API, and
        // they carry post type labels, which third-party CPT plugins let users
        // edit under a lesser capability than this screen requires.
        foreach ( $this->catalog->grouped() as $group => $abilities ) {
            $section = self::SECTION_PREFIX . sanitize_key( $group );

            add_settings_section(
                $section,
                esc_html( $group ),
                '__return_null',
                SettingsService::PAGE_SLUG
            );

            foreach ( $abilities as $name => $ability ) {
                add_settings_field(
                    $name,
                    esc_html( $ability->getLabel() ),
                    [ $this, 'renderField' ],
                    SettingsService::PAGE_SLUG,
                    $section,
                    [
                        'ability'   => $ability,
                        'label_for' => $name,
                    ]
                );
            }
        }

        // Third-party sections ask the catalogue for its inventory, which resolves
        // the abilities registry. Doing that on `admin_init`, so on every admin
        // screen, freezes the registry before plugins registering later have had
        // their turn: the MCP adapter then fails to find its own abilities and
        // gives up on creating its default server. The notice it raises is fatal
        // wherever an error handler promotes notices to exceptions, which takes
        // the whole admin down. The sections are only ever read on this screen,
        // so they are built only when this screen is shown or saved.
        if ( $this->isSettingsRequest() ) {
            $this->registerThirdPartySections();
        }
    }

    /**
     * Is the current request showing or saving the settings screen?
     *
     * Reading the request is enough here: nothing is acted upon, the answer only
     * decides whether sections are declared, and the screen itself is guarded by
     * a capability check and by the Settings API nonce.
     */
    private function isSettingsRequest(): bool {
        global $pagenow;

        // Saving goes through options.php, where the screen is only identified
        // by the option group carried by the request.
        if ( 'options.php' === $pagenow ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- reading only, to decide whether to declare sections.
            $group = isset( $_POST['option_page'] ) ? sanitize_key( wp_unslash( $_POST['option_page'] ) ) : '';

            return SettingsService::PAGE_SLUG === $group;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading only, to decide whether to declare sections.
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

        return SettingsService::PAGE_SLUG === $page;
    }

    /**
     * Declares one section per plugin having registered abilities of its own.
     *
     * @return void
     */
    private function registerThirdPartySections(): void {
        foreach ( $this->thirdParty->grouped() as $namespace => $abilities ) {
            $section = self::SECTION_PREFIX . 'third_party_' . sanitize_key( $namespace );

            add_settings_section(
                $section,
                /* translators: %s: namespace of the plugin owning the abilities. */
                esc_html( sprintf( __( 'Other plugin: %s', 'wp-mcp-abilities' ), $namespace ) ),
                [ $this, 'renderThirdPartyNotice' ],
                SettingsService::PAGE_SLUG
            );

            foreach ( $abilities as $name => $ability ) {
                add_settings_field(
                    $name,
                    esc_html( $ability->get_label() ),
                    [ $this, 'renderThirdPartyField' ],
                    SettingsService::PAGE_SLUG,
                    $section,
                    [
                        'ability'   => $ability,
                        'label_for' => $name,
                    ]
                );
            }
        }
    }

    /**
     * Says what unchecking a third-party ability actually does.
     *
     * The wording has to match the scope of the removal exactly. An
     * administrator reading that the ability is gone from the site, while it is
     * only kept away from MCP, would believe a door closed that is still open.
     *
     * @return void
     */
    public function renderThirdPartyNotice(): void {
        ?>
        <p class="description">
            <?php
            esc_html_e(
                'These abilities come from another plugin. Unchecking one hides it from MCP requests: an agent no longer lists it, and can no longer run it. The rest of the site is untouched, and the plugin providing it keeps using it as usual.',
                'wp-mcp-abilities'
            );
            ?>
        </p>
        <p class="description">
            <?php
            esc_html_e(
                'Some of them were never meant for an agent by the plugin that registered them. Checking such a one hands it to the MCP server as a tool. It grants no right: the ability decides for itself who may call it, exactly as it does elsewhere. What changes is that an agent can now reach it.',
                'wp-mcp-abilities'
            );
            ?>
        </p>
        <?php $this->renderServerNotice(); ?>
        <?php
    }

    /**
     * Warns when nothing will read what the exposure boxes write.
     *
     * The adapter builds its default server on a REST request, so the screen
     * cannot see the server itself. It can see the one decision that would keep
     * it from ever existing, and says so rather than let an administrator check
     * boxes into the void.
     *
     * @return void
     */
    private function renderServerNotice(): void {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The MCP Adapter's filter, read here but not declared by this plugin.
        if ( apply_filters( 'mcp_adapter_create_default_server', true ) ) {
            return;
        }

        ?>
        <p class="description">
            <strong>
                <?php
                esc_html_e(
                    'This site switches off the default MCP server. Exposing an ability here will have no effect until a server declares it.',
                    'wp-mcp-abilities'
                );
                ?>
            </strong>
        </p>
        <?php
    }

    /**
     * Renders the checkbox of a third-party ability.
     *
     * @param array<string, mixed> $args Field arguments, carrying the ability.
     * @return void
     */
    public function renderThirdPartyField( array $args ): void {
        $ability = $args['ability'] ?? null;

        if ( ! $ability instanceof \WP_Ability ) {
            return;
        }

        $name   = $ability->get_name();
        $joinable = $this->thirdParty->isExposedToMcp( $ability );

        /*
         * One box, two questions, never both on the same ability. An ability its
         * own plugin exposes can only be taken away; one it keeps closed can only
         * be handed over. Showing both would ask an administrator to reason about
         * a state that cannot happen.
         */
        $option  = $joinable ? SettingsService::OPTION_KEY : SettingsService::EXPOSED_OPTION_KEY;
        $checked = $joinable ? $this->settings->isEnabled( $name, true ) : $this->settings->isExposed( $name );
        ?>
        <input
            type="checkbox"
            id="<?php echo esc_attr( $name ); ?>"
            name="<?php echo esc_attr( $option . '[' . $name . ']' ); ?>"
            value="1"
            <?php checked( $checked ); ?>
        />
        <p class="description">
            <code><?php echo esc_html( $name ); ?></code>
            <?php echo esc_html( (string) $ability->get_description() ); ?>
            <?php if ( ! $joinable ) : ?>
                <br /><em><?php esc_html_e( 'Closed to MCP by its own declaration. Check to hand it to an agent.', 'wp-mcp-abilities' ); ?></em>
            <?php endif; ?>
            <?php if ( $this->thirdParty->isDestructive( $ability ) ) : ?>
                <br /><strong><?php esc_html_e( 'Declares itself destructive.', 'wp-mcp-abilities' ); ?></strong>
            <?php endif; ?>
        </p>
        <?php
    }

    /**
     * Renders the checkbox of a single ability.
     *
     * @param array<string, mixed> $args Field arguments, carrying the ability.
     * @return void
     */
    public function renderField( array $args ): void {
        $ability = $args['ability'] ?? null;

        if ( ! $ability instanceof AbstractAbility ) {
            return;
        }

        $name = $ability->getName();
        ?>
        <input
            type="checkbox"
            id="<?php echo esc_attr( $name ); ?>"
            name="<?php echo esc_attr( SettingsService::OPTION_KEY . '[' . $name . ']' ); ?>"
            value="1"
            <?php checked( $this->settings->isEnabled( $name, $ability->isEnabledByDefault() ) ); ?>
        />
        <p class="description">
            <code><?php echo esc_html( $name ); ?></code>
            <?php echo esc_html( $ability->getDescription() ); ?>
        </p>
        <?php
    }

    /**
     * Casts the submitted exposures to booleans, leaving the rest alone.
     *
     * Starts from the stored option for the same reason as the switches: an
     * ability whose plugin is momentarily inactive is absent from the catalogue,
     * and rebuilding from scratch would drop its entry.
     *
     * @param mixed $value Raw value submitted by the form.
     * @return array<string, bool>
     */
    public function sanitizeExposed( mixed $value ): array {
        $submitted = is_array( $value ) ? $value : [];
        $sanitized = array_map( 'boolval', $this->settings->allExposed() );

        /*
         * Only the abilities the screen actually offered are rewritten, and only
         * those it offered an exposure box for. An entry left by a plugin that is
         * momentarily inactive stays, rather than quietly closing again on the
         * next read.
         */
        foreach ( $this->thirdParty->all() as $name => $ability ) {
            if ( $this->thirdParty->isExposedToMcp( $ability ) ) {
                continue;
            }

            $sanitized[ $name ] = ! empty( $submitted[ $name ] );
        }

        return $sanitized;
    }

    /**
     * Casts the submitted switches to booleans, leaving the rest alone.
     *
     * Starts from the stored option rather than from an empty array: an ability
     * whose dependency is momentarily inactive is absent from the catalogue, and
     * rebuilding from scratch would drop its entry. It would then fall back to
     * its own default on the next read, quietly turning back on an ability an
     * administrator had deliberately switched off.
     *
     * @param mixed $value Raw value submitted by the form.
     * @return array<string, bool>
     */
    public function sanitize( mixed $value ): array {
        $submitted = is_array( $value ) ? $value : [];
        $sanitized = array_map( 'boolval', $this->settings->all() );

        $listed = array_merge(
            array_keys( $this->catalog->all() ),
            array_keys( $this->thirdParty->all() )
        );

        foreach ( $listed as $name ) {
            $sanitized[ $name ] = ! empty( $submitted[ $name ] );
        }

        return $sanitized;
    }
}
