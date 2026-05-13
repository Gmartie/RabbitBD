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
    $error_details = [];

    foreach ($batch as $id) {
        $product = $api->get_product((int)$id);
        if (!$product) {
            $errors++;
            $msg = "Producto ID {$id}: no se pudo obtener de la API (respuesta vacía o error HTTP).";
            $error_details[] = $msg;
            rabbit_bd_log('', "ID:{$id}", 'error', $msg);
            continue;
        }

        $sku  = $product['reference'] ?? '';
        $name = Rabbit_Prestashop_API::extract_multilang_field($product, 'name');

        if (empty($sku)) {
            $errors++;
            $msg = "Producto \"{$name}\" (ID {$id}) sin SKU (campo Referencia vacío), se omite.";
            $error_details[] = $msg;
            rabbit_bd_log('', $name, 'error', $msg);
            continue;
        }

        $image_ids = $product['associations']['images'] ?? [];
        if (empty($image_ids)) {
            $errors++;
            $msg = "SKU {$sku}: no tiene imágenes asociadas en PrestaShop.";
            $error_details[] = $msg;
            rabbit_bd_log($sku, $name, 'error', $msg);
            continue;
        }

        $files = $api->download_all_images_for_product($product, $dir);
        if (empty($files)) {
            $errors++;
            $msg = "SKU {$sku}: imágenes encontradas ({$id}) pero ninguna se descargó correctamente.";
            $error_details[] = $msg;
            rabbit_bd_log($sku, $name, 'error', $msg);
            continue;
        }

        rabbit_bd_log(
            $sku,
            $name,
            'downloaded',
            sprintf('%d imagen(es) descargada(s).', count($files))
        );
        $done++;
    }

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
        'error_details'   => $error_details,
    ]);
}

// Genera el MASTER CSV directamente desde la API de PrestaShop
add_action('wp_ajax_rabbit_bd_generate_master', 'rabbit_ajax_generate_master');
function rabbit_ajax_generate_master(): void {
    rabbit_verify_nonce();

    $api_url = sanitize_text_field($_POST['presta_url']     ?? '');
    $api_key = sanitize_text_field($_POST['presta_api_key'] ?? '');

    if (!filter_var($api_url, FILTER_VALIDATE_URL)) {
        wp_send_json_error(['message' => 'URL de PrestaShop no válida.']);
    }

    $api = new Rabbit_Prestashop_API($api_url, $api_key);
    $ids = $api->get_product_ids();

    if (empty($ids)) {
        wp_send_json_error(['message' => 'No se encontraron productos.']);
    }

    $rows = $api->get_master_rows($ids);

    if (empty($rows)) {
        wp_send_json_error(['message' => 'No se pudieron obtener datos de los productos.']);
    }

    $upload_dir  = wp_upload_dir();
    $output_path = $upload_dir['basedir'] . '/rabbit-bd-master.csv';

    $fp = fopen($output_path, 'w');
    if (!$fp) {
        wp_send_json_error(['message' => 'No se pudo crear el archivo CSV. Revisa permisos del directorio de uploads.']);
    }

    fwrite($fp, "\xEF\xBB\xBF");
    fputcsv($fp, ['name', 'sku', 'price', 'categories'], ';');
    foreach ($rows as $row) {
        fputcsv($fp, [$row['name'], $row['sku'], $row['price'], $row['categories']], ';');
    }
    fclose($fp);

    wp_send_json_success([
        'productos' => count($rows),
        'csv_url'   => $upload_dir['baseurl'] . '/rabbit-bd-master.csv',
    ]);
}

// Genera el CSV de importación para WooCommerce
// FIX: se eliminó la llamada redundante a rebuild_csv_with_absolute_urls
// que causaba double-encoding de URLs. generate_woo_csv ya produce URLs absolutas.
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

    $master_ext = strtolower(pathinfo($_FILES['master_file']['name'], PATHINFO_EXTENSION));
    if (!in_array($master_ext, ['csv', 'txt'], true)) {
        wp_send_json_error(['message' => 'El MASTER debe ser un archivo CSV o TXT.']);
    }

    $delimiter   = Rabbit_CSV_Processor::detect_delimiter($master_file);
    $master_rows = Rabbit_CSV_Processor::parse_csv($master_file, $delimiter);

    if (empty($master_rows)) {
        wp_send_json_error(['message' => 'El archivo MASTER esta vacio o no tiene el formato esperado.']);
    }

    $sku_dict = ($presta_csv && file_exists($presta_csv))
        ? Rabbit_CSV_Processor::build_sku_dictionary($presta_csv)
        : [];

    $upload_dir  = wp_upload_dir();
    $output_path = $upload_dir['basedir'] . '/rabbit-bd-import-URLS.csv';

    // FIX: generate_woo_csv ya produce el CSV final con URLs absolutas correctas
    // y enclosure apropiado. No se necesita pasar por rebuild_csv_with_absolute_urls.
    $count = Rabbit_CSV_Processor::generate_woo_csv(
        $master_rows,
        $sku_dict,
        $images_dir,
        $base_url,
        $output_path
    );

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

// ─────────────────────────────────────────────────────────────────────────────
// NUEVA FUNCIÓN: Exportar base de datos completa (SQL o CSV por tabla)
// ─────────────────────────────────────────────────────────────────────────────

add_action('wp_ajax_rabbit_bd_export_db', 'rabbit_ajax_export_db');
function rabbit_ajax_export_db(): void {
    rabbit_verify_nonce();

    $format = sanitize_text_field($_POST['format'] ?? 'sql'); // 'sql' o 'csv'
    $tables_param = sanitize_text_field($_POST['tables'] ?? 'all'); // 'all' o lista separada por comas

    global $wpdb;

    // Obtener lista de tablas
    if ($tables_param === 'all') {
        $all_tables = $wpdb->get_col("SHOW TABLES");
    } else {
        $all_tables = array_map('trim', explode(',', $tables_param));
        // Validar que las tablas existen
        $existing = $wpdb->get_col("SHOW TABLES");
        $all_tables = array_filter($all_tables, fn($t) => in_array($t, $existing, true));
    }

    if (empty($all_tables)) {
        wp_send_json_error(['message' => 'No se encontraron tablas para exportar.']);
    }

    $upload_dir = wp_upload_dir();
    $timestamp  = date('Y-m-d_H-i-s');

    if ($format === 'sql') {
        $output_path = $upload_dir['basedir'] . "/rabbit-bd-export-{$timestamp}.sql";
        $fp = fopen($output_path, 'w');

        fwrite($fp, "-- Rabbit BD · Exportación SQL\n");
        fwrite($fp, "-- Fecha: {$timestamp}\n");
        fwrite($fp, "-- Base de datos: " . DB_NAME . "\n");
        fwrite($fp, "-- WordPress: " . get_site_url() . "\n\n");
        fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\n");
        fwrite($fp, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
        fwrite($fp, "SET NAMES utf8mb4;\n\n");

        foreach ($all_tables as $table) {
            // Estructura de la tabla
            $create = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
            if (!$create) continue;

            fwrite($fp, "-- ─────────────────────────────────────────────\n");
            fwrite($fp, "-- Tabla: {$table}\n");
            fwrite($fp, "-- ─────────────────────────────────────────────\n");
            fwrite($fp, "DROP TABLE IF EXISTS `{$table}`;\n");
            fwrite($fp, $create[1] . ";\n\n");

            // Datos de la tabla en lotes de 500 filas
            $offset_rows = 0;
            $batch_rows  = 500;

            while (true) {
                $rows = $wpdb->get_results(
                    "SELECT * FROM `{$table}` LIMIT {$batch_rows} OFFSET {$offset_rows}",
                    ARRAY_N
                );

                if (empty($rows)) break;

                // Cabecera INSERT
                $cols_raw = $wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A);
                $col_names = implode('`, `', array_column($cols_raw, 'Field'));
                fwrite($fp, "INSERT INTO `{$table}` (`{$col_names}`) VALUES\n");

                $value_lines = [];
                foreach ($rows as $row) {
                    $values = array_map(function($v) use ($wpdb) {
                        if ($v === null) return 'NULL';
                        return "'" . esc_sql($v) . "'";
                    }, $row);
                    $value_lines[] = '(' . implode(', ', $values) . ')';
                }
                fwrite($fp, implode(",\n", $value_lines) . ";\n\n");

                $offset_rows += $batch_rows;
            }
        }

        fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fp);

        wp_send_json_success([
            'format'   => 'sql',
            'tablas'   => count($all_tables),
            'file_url' => $upload_dir['baseurl'] . "/rabbit-bd-export-{$timestamp}.sql",
            'filename' => "rabbit-bd-export-{$timestamp}.sql",
        ]);

    } elseif ($format === 'csv') {
        // Exportar cada tabla como CSV individual dentro de un ZIP
        // Si ZIP no está disponible, exportar solo la primera tabla o todas concatenadas

        $zip_path = $upload_dir['basedir'] . "/rabbit-bd-export-{$timestamp}.zip";
        $files_created = [];

        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

            foreach ($all_tables as $table) {
                $csv_content = '';
                $cols_raw = $wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A);
                if (!$cols_raw) continue;

                $col_names = array_column($cols_raw, 'Field');

                // Cabecera
                $csv_content .= implode(',', array_map(fn($c) => '"' . str_replace('"', '""', $c) . '"', $col_names)) . "\n";

                // Datos en lotes
                $offset_rows = 0;
                while (true) {
                    $rows = $wpdb->get_results("SELECT * FROM `{$table}` LIMIT 500 OFFSET {$offset_rows}", ARRAY_N);
                    if (empty($rows)) break;
                    foreach ($rows as $row) {
                        $vals = array_map(fn($v) => $v === null ? '' : '"' . str_replace('"', '""', $v) . '"', $row);
                        $csv_content .= implode(',', $vals) . "\n";
                    }
                    $offset_rows += 500;
                }

                $zip->addFromString("{$table}.csv", "\xEF\xBB\xBF" . $csv_content);
                $files_created[] = "{$table}.csv";
            }

            $zip->close();

            wp_send_json_success([
                'format'    => 'csv_zip',
                'tablas'    => count($files_created),
                'file_url'  => $upload_dir['baseurl'] . "/rabbit-bd-export-{$timestamp}.zip",
                'filename'  => "rabbit-bd-export-{$timestamp}.zip",
                'archivos'  => $files_created,
            ]);
        } else {
            // Fallback: exportar todas las tablas en un único CSV con separadores de sección
            $output_path = $upload_dir['basedir'] . "/rabbit-bd-export-{$timestamp}.csv";
            $fp = fopen($output_path, 'w');
            fwrite($fp, "\xEF\xBB\xBF");

            foreach ($all_tables as $table) {
                fwrite($fp, "\"## TABLA: {$table}\"\n");
                $cols_raw = $wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A);
                if (!$cols_raw) continue;
                $col_names = array_column($cols_raw, 'Field');
                fputcsv($fp, $col_names);

                $offset_rows = 0;
                while (true) {
                    $rows = $wpdb->get_results("SELECT * FROM `{$table}` LIMIT 500 OFFSET {$offset_rows}", ARRAY_N);
                    if (empty($rows)) break;
                    foreach ($rows as $row) {
                        fputcsv($fp, array_map(fn($v) => $v ?? '', $row));
                    }
                    $offset_rows += 500;
                }
                fwrite($fp, "\n");
            }
            fclose($fp);

            wp_send_json_success([
                'format'   => 'csv',
                'tablas'   => count($all_tables),
                'file_url' => $upload_dir['baseurl'] . "/rabbit-bd-export-{$timestamp}.csv",
                'filename' => "rabbit-bd-export-{$timestamp}.csv",
            ]);
        }
    } else {
        wp_send_json_error(['message' => 'Formato no válido. Usa "sql" o "csv".']);
    }
}

// Lista las tablas disponibles para la exportación
add_action('wp_ajax_rabbit_bd_list_tables', 'rabbit_ajax_list_tables');
function rabbit_ajax_list_tables(): void {
    rabbit_verify_nonce();

    global $wpdb;
    $tables = $wpdb->get_col("SHOW TABLES");

    // Calcular tamaño aproximado de cada tabla
    $sizes = [];
    foreach ($tables as $table) {
        $row = $wpdb->get_row("SELECT 
            TABLE_ROWS as rows,
            ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) as size_mb
            FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'", ARRAY_A);
        $sizes[$table] = [
            'rows'    => (int)($row['rows'] ?? 0),
            'size_mb' => (float)($row['size_mb'] ?? 0),
        ];
    }

    wp_send_json_success([
        'tables' => $tables,
        'sizes'  => $sizes,
        'total'  => count($tables),
    ]);
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
