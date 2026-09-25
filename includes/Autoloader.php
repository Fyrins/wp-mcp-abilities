<?php
/**
 * PSR-4 autoloader for the plugin's classes.
 *
 * @package WpMcpAbilities
 */

namespace WpMcpAbilities;

defined( 'ABSPATH' ) || exit;

/**
 * Maps `WpMcpAbilities\Foo\Bar` to `includes/Foo/Bar.php`.
 */
final class Autoloader {
    private const PREFIX = __NAMESPACE__ . '\\';

    /**
     * Registers the autoloader.
     *
     * @return void
     */
    public static function register(): void {
        spl_autoload_register( [ self::class, 'load' ] );
    }

    /**
     * Loads one class of the plugin.
     *
     * @param string $class_name Fully qualified class name.
     * @return void
     */
    public static function load( string $class_name ): void {
        if ( ! str_starts_with( $class_name, self::PREFIX ) ) {
            return;
        }

        $relative = str_replace( '\\', '/', substr( $class_name, strlen( self::PREFIX ) ) );
        $file     = __DIR__ . '/' . $relative . '.php';

        if ( is_readable( $file ) ) {
            require $file;
        }
    }
}
