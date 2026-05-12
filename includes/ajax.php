<?php
if (!defined('ABSPATH')) exit;

// Tamaño de lote por petición AJAX (evita timeouts y memory exhausted)
const RABBIT_BD_BATCH_SIZE = 50;

// Descarga un lote de imágenes desde PrestaShop
add_action('wp_ajax_rabbit_bd_download_images', 'rabbit_ajax_download_images');
function rabbit_ajax_download_images(): void {
    rabbit_verify_nonce();

    $api_url = sanitize_text_field($_POST['presta_url']     ?? '');
    $api_key = sanitize_text_field($_POST['presta_api_key'] ?? '');
    $offset  = max(0, (int)($_POST['offset'] ?? 0));
    $dir     = get_option('rabbit_bd_images_dir', WP_CONTENT_DIR . '/uploads/rabbit-bd');

    // Validar URL antes de continuar
    if (!filter_var($api_url, FILTER_VALIDATE_URL)) {
        wp_send_json_error(['message' => 'URL de PrestaShop no valida.']);
    }

    wp_mkdir_p($dir);

    $api = new Rabbit_Prestashop_API($api_url, $api_key);
    $ids = $api->get_product_ids();

    if (empty($ids)) {
        wp_send_json_error(['message' => 'No se encontraron productos o la API no responde.']);
    }

    $total  = count($ids);
    $batch  = array_slice($ids, $offset, RABBIT_BD_BATCH_SIZE);
    $done   = 0;
    $errors = 0;

    foreach ($batch as $id) {
        $product = $api->get_product((int)$id);
        if (!$product) {
            $errors++;
            continue;
        }

        // Extraer nombre multilenguaje de forma robusta
        $name = Rabbit_Prestashop_API::extract_multilang_field($product, 'name');

        $files = $api->download_all_images_for_product($product, $dir);
        if (empty($files)) {
            $errors++;
            continue;
        }

        rabbit_bd_log(
            $product['reference'] ?? '',
            $name,
            'downloaded',
            sprintf('%d imagen(es) descargada(s).', count($files))
        );
        $done++;
    }

    // Guardar progreso para posible reanudacion
    $new_offset = $offset + count($batch);
    $finished   = $new_offset >= $total;
    rabbit_bd_save_state('download', $new_offset, $total, $finished ? 'done' : 'running');

    wp_send_json_success([
        'offset'          => $new_offset,
        'total'           => $total,
        'lote_ok'         => $done,
        'lote_error'      => $errors,
        'finished'        => $finished,
        'directorio'      => $dir,
    ]);
}

// Genera el CSV de importación para WooCommerce
add_action('wp_ajax_rabbit_bd_generate_csv', 'rabbit_ajax_generate_csv');
function rabbit_ajax_generate_csv(): void {
    rabbit_verify_nonce();

    if (empty($_FILES['master_file']['tmp_name'])) {
        wp_send_json_error(['message' => 'Falta el archivo MASTER.']);
    }

    $presta_csv  = $_FILES['presta_csv']['tmp_name']  ?? '';
    $master_file = $_FILES['master_file']['tmp_name'];
    $base_url    = esc_url_raw($_POST['base_url'] ?? '');
    $images_dir  = get_option('rabbit_bd_images_dir', WP_CONTENT_DIR . '/uploads/rabbit-bd');

    // Validar extensiones de archivo subido
    $master_ext = strtolower(pathinfo($_FILES['master_file']['name'], PATHINFO_EXTENSION));
    if (!in_array($master_ext, ['csv', 'txt'], true)) {
        wp_send_json_error(['message' => 'El MASTER debe ser un archivo CSV o TXT.']);
    }

    // Autodetectar delimitador antes de procesar
    $delimiter   = Rabbit_CSV_Processor::detect_delimiter($master_file);
    $master_rows = Rabbit_CSV_Processor::parse_csv($master_file, $delimiter);

    if (empty($master_rows)) {
        wp_send_json_error(['message' => 'El archivo MASTER esta vacio o no tiene el formato esperado.']);
    }

    $sku_dict = ($presta_csv && file_exists($presta_csv))
        ? Rabbit_CSV_Processor::build_sku_dictionary($presta_csv)
        : [];

    $upload_dir  = wp_upload_dir();
    $output_path = $upload_dir['basedir'] . '/rabbit-bd-import.csv';
    $final_path  = $upload_dir['basedir'] . '/rabbit-bd-import-URLS.csv';

    $count = Rabbit_CSV_Processor::generate_woo_csv($master_rows, $sku_dict, $images_dir, $base_url, $output_path);

    // Reconstruir con URLs absolutas corregidas (incluye carpeta SKU en la ruta)
    Rabbit_Image_Builder::rebuild_csv_with_absolute_urls($output_path, $final_path, $base_url);

    wp_send_json_success([
        'productos'    => $count,
        'csv_url'      => $upload_dir['baseurl'] . '/rabbit-bd-import-URLS.csv',
        'csv_filename' => 'rabbit-bd-import-URLS.csv',
    ]);
}

// Valida si una URL de imagen es accesible por HTTP
add_action('wp_ajax_rabbit_bd_test_image_url', 'rabbit_ajax_test_image_url');
function rabbit_ajax_test_image_url(): void {
    rabbit_verify_nonce();

    $url = esc_url_raw($_POST['url'] ?? '');
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        wp_send_json_error(['message' => 'URL no valida.']);
    }

    $ok = Rabbit_Image_Builder::is_image_accessible($url);
    if ($ok) {
        wp_send_json_success(['message' => 'Imagen accesible. El servidor sirve estaticos correctamente.']);
    } else {
        wp_send_json_error(['message' => 'La imagen devolvio un error. Revisa la configuracion del servidor.']);
    }
}

// Devuelve las ultimas 200 entradas del log
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

// Vacia la tabla de log
add_action('wp_ajax_rabbit_bd_clear_log', 'rabbit_ajax_clear_log');
function rabbit_ajax_clear_log(): void {
    rabbit_verify_nonce();
    global $wpdb;
    $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}rabbit_bd_log");
    wp_send_json_success(['message' => 'Log limpiado.']);
}

// Devuelve el progreso guardado para reanudar una descarga interrumpida
add_action('wp_ajax_rabbit_bd_get_state', 'rabbit_ajax_get_state');
function rabbit_ajax_get_state(): void {
    rabbit_verify_nonce();
    $key   = sanitize_text_field($_POST['batch_key'] ?? 'download');
    $state = rabbit_bd_get_state($key);
    wp_send_json_success(['state' => $state]);
}

// Comprueba nonce y permisos en todos los endpoints AJAX
function rabbit_verify_nonce(): void {
    if (!check_ajax_referer('rabbit_bd_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Nonce invalido.'], 403);
        exit;
    }
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Sin permisos.'], 403);
        exit;
    }
}
