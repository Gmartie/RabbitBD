<?php
if (!defined('ABSPATH')) exit;

// ── Acción: descargar imágenes de PrestaShop ───────────────────────────────
add_action('wp_ajax_rabbit_bd_download_images', 'rabbit_ajax_download_images');
function rabbit_ajax_download_images(): void {
    rabbit_verify_nonce();

    $api_url = sanitize_text_field($_POST['presta_url']     ?? '');
    $api_key = sanitize_text_field($_POST['presta_api_key'] ?? '');
    $dir     = get_option('rabbit_bd_images_dir', WP_CONTENT_DIR . '/uploads/rabbit-imports');

    wp_mkdir_p($dir);

    $api      = new Rabbit_Prestashop_API($api_url, $api_key);
    $ids      = $api->get_product_ids();
    $success  = 0;
    $errors   = 0;

    foreach ($ids as $id) {
        $product = $api->get_product((int)$id);
        if (!$product) { $errors++; continue; }

        $files = $api->download_all_images_for_product($product, $dir);
        if (empty($files)) { $errors++; continue; }

        rabbit_bd_log(
            $product['reference'] ?? '',
            $product['name'][0]['value'] ?? '',
            'downloaded',
            sprintf('%d imagen(es) descargada(s).', count($files))
        );
        $success++;
    }

    wp_send_json_success([
        'productos_ok'     => $success,
        'productos_error'  => $errors,
        'directorio'       => $dir,
    ]);
}

// ── Acción: generar CSV de WooCommerce ────────────────────────────────────
add_action('wp_ajax_rabbit_bd_generate_csv', 'rabbit_ajax_generate_csv');
function rabbit_ajax_generate_csv(): void {
    rabbit_verify_nonce();

    if (empty($_FILES['master_file'])) {
        wp_send_json_error(['message' => 'Falta el archivo MASTER.']);
    }

    $presta_csv  = $_FILES['presta_csv']['tmp_name']  ?? '';
    $master_file = $_FILES['master_file']['tmp_name'] ?? '';
    $base_url    = sanitize_text_field($_POST['base_url'] ?? '');
    $images_dir  = get_option('rabbit_bd_images_dir', WP_CONTENT_DIR . '/uploads/rabbit-imports');

    // Leer hojas CSV del cliente (MASTER) — formato simple columnas: name,sku,price,categories
    $master_rows = [];
    $handle      = fopen($master_file, 'r');
    $header      = fgetcsv($handle);
    while (($row = fgetcsv($handle)) !== false) {
        $master_rows[] = array_combine(array_map('strtolower', $header), $row);
    }
    fclose($handle);

    // Diccionario PrestaShop CSV (opcional)
    $sku_dict = $presta_csv ? Rabbit_CSV_Processor::build_sku_dictionary($presta_csv) : [];

    // Generar CSV con galerías
    $upload_dir  = wp_upload_dir();
    $output_path = $upload_dir['basedir'] . '/rabbit-bd-import.csv';
    $count       = Rabbit_CSV_Processor::generate_woo_csv($master_rows, $sku_dict, $images_dir, $base_url, $output_path);

    // Regenerar con URLs absolutas (fix definitivo)
    $final_path = $upload_dir['basedir'] . '/rabbit-bd-import-URLS.csv';
    Rabbit_Image_Builder::rebuild_csv_with_absolute_urls($output_path, $final_path, $base_url);

    wp_send_json_success([
        'productos'    => $count,
        'csv_url'      => $upload_dir['baseurl'] . '/rabbit-bd-import-URLS.csv',
        'csv_filename' => 'rabbit-bd-import-URLS.csv',
    ]);
}

// ── Acción: validar URL de imagen ─────────────────────────────────────────
add_action('wp_ajax_rabbit_bd_test_image_url', 'rabbit_ajax_test_image_url');
function rabbit_ajax_test_image_url(): void {
    rabbit_verify_nonce();

    $url = esc_url_raw($_POST['url'] ?? '');
    if (empty($url)) {
        wp_send_json_error(['message' => 'URL vacía.']);
    }

    $ok = Rabbit_Image_Builder::is_image_accessible($url);
    if ($ok) {
        wp_send_json_success(['message' => '✅ Imagen accesible. El servidor sirve estáticos correctamente.']);
    } else {
        wp_send_json_error(['message' => '❌ La imagen devolvió 404. Revisa la configuración del servidor / .htaccess.']);
    }
}

// ── Acción: obtener log ───────────────────────────────────────────────────
add_action('wp_ajax_rabbit_bd_get_log', 'rabbit_ajax_get_log');
function rabbit_ajax_get_log(): void {
    rabbit_verify_nonce();

    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT sku, product_name, status, message, created_at
         FROM {$wpdb->prefix}rabbit_bd_log
         ORDER BY id DESC LIMIT 200",
        ARRAY_A
    );
    wp_send_json_success(['log' => $rows]);
}

// ── Acción: limpiar log ───────────────────────────────────────────────────
add_action('wp_ajax_rabbit_bd_clear_log', 'rabbit_ajax_clear_log');
function rabbit_ajax_clear_log(): void {
    rabbit_verify_nonce();
    global $wpdb;
    $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}rabbit_bd_log");
    wp_send_json_success(['message' => 'Log limpiado.']);
}

// ── Helper ────────────────────────────────────────────────────────────────
function rabbit_verify_nonce(): void {
    if (!check_ajax_referer('rabbit_bd_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Nonce inválido.'], 403);
        exit;
    }
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Sin permisos.'], 403);
        exit;
    }
}
