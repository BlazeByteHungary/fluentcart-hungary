<?php
/**
 * Plugin Name:       FluentCart Hungary
 * Plugin URI:        https://github.com/BlazeByteHungary/fluentcart-hungary
 * Description:       Hungarian invoicing, payment and logistics integrations for FluentCart (Számlázz, Billingo, Barion, SimplePay, DPD, GLS, Packeta, FoxPost, MPL, pickup points). Modular add-on; FluentCart is optional at load time.
 * Version:           0.6.1
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            UNBND
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       fluentcart-hungary
 * Domain Path:       /languages
 *
 * @package FluentCartHungary
 */
// below this line only god knows what this code does.

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('FCH_VERSION', '0.6.1');
define('FCH_FILE', __FILE__);
define('FCH_DIR', plugin_dir_path(__FILE__));
define('FCH_URL', plugin_dir_url(__FILE__));
define('FCH_SCHEMA_VERSION', 2);
define('FCH_MIN_FC_VERSION', '1.7.0');

require_once FCH_DIR . 'src/Core/functions.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'FluentCartHungary\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = FCH_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

register_activation_hook(__FILE__, ['FluentCartHungary\\Core\\Lifecycle', 'activate']);
register_deactivation_hook(__FILE__, ['FluentCartHungary\\Core\\Lifecycle', 'deactivate']);

add_filter('cron_schedules', ['FluentCartHungary\\Core\\Lifecycle', 'cronSchedules']);

/**
 * Boot after every plugin is loaded. This is the ONLY place where FluentCart symbols may first be
 * touched (spike B1); no FluentCart symbol is referenced at file scope.
 */
add_action('plugins_loaded', static function (): void {
    \FluentCartHungary\Core\Plugin::instance()->boot();
}, 20);
