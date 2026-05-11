<?php
/**
 * Procesado del Excel del cliente (hoja MASTER) y generación del CSV de importación
 * para WooCommerce con multicategorías y URLs absolutas de imágenes.
 * Implementa los pasos 2 y 3 del documento.
 */
if (!defined('ABSPATH')) exit;

class Rabbit_CSV_Processor {

    /**
     * Lee el CSV exportado de PrestaShop y devuelve un mapa Nombre→SKU
     * para enriquecer la hoja MASTER del cliente.
     *
     * @param string $presta_csv Ruta al CSV de PrestaShop
     * @return array<string,string>  ['Nombre producto' => 'SKU']
     */
    public static function build_sku_dictionary(string $presta_csv): array {
        $map = [];
        if (!file_exists($presta_csv)) return $map;

        $handle = fopen($presta_csv, 'r');
        $header = fgetcsv($handle, 0, ';') ?: fgetcsv($handle, 0, ',');

        // Intentar detectar columnas de nombre y referencia
        $col_name = self::find_column($header, ['name', 'nombre', 'Name']);
        $col_sku  = self::find_column($header, ['reference', 'referencia', 'sku', 'SKU']);

        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            if (count($row) <= max($col_name, $col_sku)) continue;
            $map[trim($row[$col_name])] = trim($row[$col_sku]);
        }
        fclose($handle);

        return $map;
    }

    /**
     * Genera el CSV de importación para WooCommerce (paso 2 + 3 del doc).
     *
     * @param array  $master_rows   Filas de la hoja MASTER del cliente
     * @param array  $sku_dict      Mapa nombre→SKU del CSV de PrestaShop
     * @param string $images_dir    Directorio local con subcarpetas por SKU
     * @param string $base_url      URL base donde se sirven las imágenes
     *                              p.ej. http://confortfurniture.palikecomunicacion.com/wp-content/uploads/imports/
     * @param string $output_path   Ruta donde escribir el CSV final
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

        // Cabecera WooCommerce
        fputcsv($handle, [
            'Type', 'SKU', 'Name', 'Published', 'Visibility in catalog',
            'Regular price', 'Categories', 'Images', 'In stock?', 'Stock',
        ]);

        $imported = 0;

        foreach ($master_rows as $row) {
            $name  = trim($row['name']      ?? $row['nombre']     ?? '');
            $price = trim($row['price']     ?? $row['precio']     ?? '');
            $cats  = trim($row['categories'] ?? $row['categorias'] ?? '');
            $sku   = trim($row['sku']       ?? $row['referencia'] ?? $sku_dict[$name] ?? '');

            if (empty($name)) continue;

            // Construir lista de imágenes con URLs absolutas (fix definitivo del doc)
            $images = self::build_image_urls($sku, $images_dir, $base_url);

            fputcsv($handle, [
                'simple',          // Type
                $sku,              // SKU
                $name,             // Name
                1,                 // Published
                'visible',         // Visibility in catalog
                $price,            // Regular price
                $cats,             // Categories (multicategoría separada por coma)
                $images,           // Images — URLs absolutas, primera = destacada
                1,                 // In stock?
                '',                // Stock (vacío si no se controla)
            ]);

            $imported++;
        }

        fclose($handle);
        return $imported;
    }

    /**
     * Construye la lista de URLs absolutas de imágenes para un SKU dado.
     * Busca en <images_dir>/<SKU>/ y ordena los archivos por nombre.
     * La primera imagen será la destacada en WooCommerce.
     */
    public static function build_image_urls(string $sku, string $images_dir, string $base_url): string {
        $sku_dir = trailingslashit($images_dir) . $sku;
        if (empty($sku) || !is_dir($sku_dir)) return '';

        $extensions = ['jpg', 'jpeg', 'png', 'webp'];
        $files      = [];

        foreach (scandir($sku_dir) as $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, $extensions, true)) {
                $files[] = $file;
            }
        }

        sort($files); // Ordenación consistente por nombre

        $urls = array_map(fn($f) => $base_url . $f, $files);
        return implode(',', $urls);
    }

    // ------------------------------------------------------------------ //

    private static function find_column(array $header, array $candidates): int {
        foreach ($candidates as $name) {
            $idx = array_search(strtolower($name), array_map('strtolower', $header));
            if ($idx !== false) return (int)$idx;
        }
        return 0;
    }
}
