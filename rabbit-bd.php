<?php
/**
 * Plugin Name: Rabbit BD
 * Description: Herramienta de migración de sitios web hacia WooCommerce. Importa productos con SKUs, multicategorías, imágenes destacadas y galerías completas desde PrestaShop.
 * Version: 1.4
 * Author: Gabriel
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) exit;

define('RABBIT_BD_PATH',    plugin_dir_path(__FILE__));
define('RABBIT_BD_URL',     plugin_dir_url(__FILE__));
define('RABBIT_BD_VERSION', '1.4');

require_once RABBIT_BD_PATH . 'includes/database.php';
require_once RABBIT_BD_PATH . 'includes/ajax.php';
require_once RABBIT_BD_PATH . 'includes/prestashop-api.php';
require_once RABBIT_BD_PATH . 'includes/csv-processor.php';
require_once RABBIT_BD_PATH . 'includes/image-builder.php';
require_once RABBIT_BD_PATH . 'admin/settings-page.php';

register_activation_hook(__FILE__, 'rabbit_bd_activate');

function rabbit_bd_activate(): void {
    rabbit_bd_create_tables();
}

// Garantiza que las tablas existen aunque el plugin se actualice sin reactivar
add_action('admin_init', function () {
    if (get_option('rabbit_bd_db_version') !== RABBIT_BD_VERSION) {
        rabbit_bd_create_tables();
        update_option('rabbit_bd_db_version', RABBIT_BD_VERSION);
    }
});
