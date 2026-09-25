<?php
/**
 * Deletes a customised template or template part.
 *
 * @package WpMcpAbilities\Abilities\Template
 */

namespace WpMcpAbilities\Abilities\Template;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Capabilities;
use WpMcpAbilities\Support\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Trashes or permanently deletes a `custom` template. Destructive, disabled
 * by default.
 *
 * Templates that only exist as a theme file (`source !== 'custom'`) are
 * refused: there is no post to delete, and core itself blocks the same
 * request in `WP_REST_Templates_Controller::delete_item()`. Use the update
 * ability's `revert` option to drop a customisation instead.
 */
class DeleteTemplateAbility extends AbstractAbility {
    use TemplateAccess;

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return self::qualify( 'delete-template' );
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string {
        return __( 'Delete a template', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string {
        return __(
            'Trashes or permanently deletes a customised block template or template part.',
            'wp-mcp-abilities'
        );
    }

    /**
     * @inheritDoc
     */
    public function getGroup(): string {
        return __( 'Templates', 'wp-mcp-abilities' );
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'type'  => $this->typeProperty(),
                'id'    => [
                    'type'        => 'string',
                    'description' => __( 'Composite identifier, formatted as "{theme}//{slug}".', 'wp-mcp-abilities' ),
                ],
                'slug'  => [
                    'type'        => 'string',
                    'description' => __(
                        'Template slug. Used instead of "id", completed with the active theme.',
                        'wp-mcp-abilities'
                    ),
                ],
                'force' => [
                    'type'        => 'boolean',
                    'description' => __( 'Whether to bypass Trash and delete permanently.', 'wp-mcp-abilities' ),
                    'default'     => false,
                ],
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function getOutputSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'success' => [ 'type' => 'boolean' ],
            ],
        ];
    }

    /**
     * Destructive ability, never exposed unless explicitly enabled.
     *
     * @inheritDoc
     */
    public function isEnabledByDefault(): bool {
        return false;
    }

    /**
     * @inheritDoc
     * @param mixed $input Raw input coming from the MCP adapter.
     */
    public function checkPermission( mixed $input = null ): bool {
        return Capabilities::canEditTemplates();
    }

    /**
     * @inheritDoc
     * @param mixed $input Raw input coming from the MCP adapter.
     */
    public function execute( mixed $input = null ): array|\WP_Error {
        if ( ! Capabilities::canEditTemplates() ) {
            return $this->forbidden();
        }

        $input = Input::normalize( $input );
        $type  = $this->resolveType( $input );

        $valid = $this->validateType( $type );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        $id = $this->resolveTemplateId( $input );
        if ( is_wp_error( $id ) ) {
            return $id;
        }

        $template = $this->resolveTemplate( $id, $type );
        if ( is_wp_error( $template ) ) {
            return $template;
        }

        if ( 'custom' !== $template->source ) {
            return new \WP_Error(
                'invalid_template',
                __( 'Templates that come from the theme files cannot be deleted.', 'wp-mcp-abilities' ),
                [ 'status' => 400 ]
            );
        }

        $force = Input::bool( $input, 'force', false );

        if ( $force ) {
            $result = wp_delete_post( $template->wp_id, true );
        } else {
            if ( 'trash' === $template->status ) {
                return new \WP_Error(
                    'template_already_trashed',
                    __( 'The template has already been deleted.', 'wp-mcp-abilities' ),
                    [ 'status' => 410 ]
                );
            }

            $result = wp_trash_post( $template->wp_id );
        }

        if ( ! $result ) {
            return new \WP_Error(
                'delete_failed',
                __( 'Unable to delete the template.', 'wp-mcp-abilities' ),
                [ 'status' => 500 ]
            );
        }

        return [ 'success' => true ];
    }
}
