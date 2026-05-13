<?php
/**
 * Procesado del CSV del cliente (hoja MASTER) y generacion del CSV de importacion
 * para WooCommerce con multicategorias y URLs absolutas de imagenes.
 *
 * FIX v1.2:
 *  - Bug crítico: fputcsv ahora usa enclosure=" para que las URLs con comas
 *    queden correctamente entre comillas en el CSV de WooCommerce.
 *  - Bug carpetas: build_image_urls busca por SKU Y por name_slug como fallback,
 *    ya que download_all_images_for_product guarda por nombre de producto.
 *  - Se elimina la doble llamada a rebuild_csv_with_absolute_urls (redundante).
 */
if (!defined('ABSPATH')) exit;

class Rabbit_CSV_Processor {

    /**
     * Detecta automaticamente el delimitador de un archivo CSV.
     */
    public static function detect_delimiter(string $filepath): string {
        $handle = fopen($filepath, 'r');
        $line   = fgets($handle);
        fclose($handle);

        $delimiters = [';' => 0, ',' => 0, "\t" => 0, '|' => 0];
        foreach (array_keys($delimiters) as $d) {
            $delimiters[$d] = substr_count($line, $d);
        }
        arsort($delimiters);
        return array_key_first($delimiters);
    }

    /**
     * Lee un CSV completo en un array de filas asociativas.
     *
     * @return array<int, array<string, string>>
     */
    public static function parse_csv(string $filepath, string $delimiter = ','): array {
        $rows   = [];
        $handle = fopen($filepath, 'r');
        // BOM UTF-8
        $bom    = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $header = fgetcsv($handle, 0, $delimiter);
        if (!$header) {
            fclose($handle);
            return [];
        }
        $header = array_map('strtolower', array_map('trim', $header));

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (count($row) < count($header)) {
                $row = array_pad($row, count($header), '');
            }
            $rows[] = array_combine($header, $row);
        }
        fclose($handle);
        return $rows;
    }

    /**
     * Lee el CSV exportado de PrestaShop y devuelve un mapa Nombre->SKU.
     *
     * @return array<string,string>  ['Nombre producto' => 'SKU']
     */
    public static function build_sku_dictionary(string $presta_csv): array {
        if (!file_exists($presta_csv)) return [];

        $delimiter = self::detect_delimiter($presta_csv);
        $rows      = self::parse_csv($presta_csv, $delimiter);
        $map       = [];

        foreach ($rows as $row) {
            $name = trim($row['name'] ?? $row['nombre'] ?? '');
            $sku  = trim($row['reference'] ?? $row['referencia'] ?? $row['sku'] ?? '');
            if ($name !== '' && $sku !== '') {
                $map[$name] = $sku;
            }
        }

        return $map;
    }

    /**
     * Genera el CSV de importacion para WooCommerce.
     *
     * FIX: usa enclosure='"' en fputcsv para que los campos con comas
     * (como la lista de URLs de imagenes) queden correctamente entrecomillados.
     * Sin esto WooCommerce interpreta cada URL como una columna separada,
     * causando el error "No hay productos que actualizar".
     *
     * @return int  Numero de productos procesados
     */
    public static function generate_woo_csv(
        array  $master_rows,
        array  $sku_dict,
        string $images_dir,
        string $base_url,
        string $output_path
    ): int {
        $base_url = trailingslashit($base_url);
        $handle   = fopen($output_path, 'w');

        // BOM UTF-8 para compatibilidad con Excel/WooCommerce
        fwrite($handle, "\xEF\xBB\xBF");

        // IMPORTANTE: enclosure='"' garantiza que campos con comas queden
        // correctamente entre comillas dobles en el CSV final.
        fputcsv($handle, [
            'Type', 'SKU', 'Name', 'Published', 'Visibility in catalog',
            'Regular price', 'Categories', 'Images', 'In stock?', 'Stock',
        ], ',', '"');

        $imported = 0;

        foreach ($master_rows as $row) {
            $name  = trim($row['name']       ?? $row['nombre']      ?? '');
            $price = trim($row['price']      ?? $row['precio']      ?? '');
            $cats  = trim($row['categories'] ?? $row['categorias']  ?? '');
            $sku   = trim($row['sku']        ?? $row['referencia']  ?? $sku_dict[$name] ?? '');

            if (empty($name)) continue;

            // FIX: buscar imágenes por SKU primero, luego por name_slug como fallback
            // (las imágenes se descargan en carpeta por nombre-de-producto)
            $images = self::build_image_urls($sku, $name, $images_dir, $base_url);

            // IMPORTANTE: fputcsv con enclosure='"' entrecomillará automáticamente
            // la columna Images si contiene comas (múltiples URLs).
            fputcsv($handle, [
                'simple',
                $sku,
                $name,
                1,
                'visible',
                $price,
                $cats,
                $images,
                1,
                '',
            ], ',', '"');

            $imported++;
        }

        fclose($handle);
        return $imported;
    }

    /**
     * Construye la lista de URLs absolutas para las imagenes de un producto.
     *
     * FIX: Acepta tanto $sku como $name para localizar la carpeta correcta,
     * ya que download_all_images_for_product guarda las imágenes usando el
     * name_slug (slug del nombre del producto), no el SKU directamente.
     *
     * Orden de búsqueda:
     *  1. <images_dir>/<sku>/
     *  2. <images_dir>/<name_slug>/
     */
    public static function build_image_urls(
        string $sku,
        string $name,
        string $images_dir,
        string $base_url
    ): string {
        $extensions = ['jpg', 'jpeg', 'png', 'webp'];

        // Intentar primero con el SKU
        $found_dir  = '';
        $found_slug = '';

        if (!empty($sku)) {
            $dir = trailingslashit($images_dir) . sanitize_file_name($sku);
            if (is_dir($dir)) {
                $found_dir  = $dir;
                $found_slug = rawurlencode($sku);
            }
        }

        // Fallback: buscar por name_slug (como los guarda prestashop-api.php)
        if ($found_dir === '' && !empty($name)) {
            $name_slug = sanitize_file_name(strtolower(str_replace([' ', '/'], '-', $name)));
            $dir = trailingslashit($images_dir) . $name_slug;
            if (is_dir($dir)) {
                $found_dir  = $dir;
                $found_slug = rawurlencode($name_slug);
            }
        }

        if ($found_dir === '') return '';

        $files = [];
        foreach (scandir($found_dir) as $file) {
            if ($file === '.' || $file === '..') continue;
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, $extensions, true)) {
                $files[] = $file;
            }
        }

        if (empty($files)) return '';

        sort($files);

        $urls = array_map(
            fn($f) => $base_url . $found_slug . '/' . rawurlencode($f),
            $files
        );

        return implode(',', $urls);
    }
}
