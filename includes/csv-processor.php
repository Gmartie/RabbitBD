<?php
/**
 * Procesado del CSV del cliente (hoja MASTER) y generacion del CSV de importacion
 * para WooCommerce con multicategorias y URLs absolutas de imagenes.
 */
if (!defined('ABSPATH')) exit;

class Rabbit_CSV_Processor {

    /**
     * Detecta automaticamente el delimitador de un archivo CSV.
     * Evita la perdida de filas que ocurre al usar fgetcsv con delimitador incorrecto.
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
     * Usa el delimitador pre-detectado para evitar columnas mal alineadas.
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
                // Rellenar columnas faltantes con string vacio
                $row = array_pad($row, count($header), '');
            }
            $rows[] = array_combine($header, $row);
        }
        fclose($handle);
        return $rows;
    }

    /**
     * Lee el CSV exportado de PrestaShop y devuelve un mapa Nombre->SKU.
     * Usa autodeteccion de delimitador para no consumir filas incorrectamente.
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
     * @param array  $master_rows   Filas de la hoja MASTER del cliente
     * @param array  $sku_dict      Mapa nombre->SKU del CSV de PrestaShop
     * @param string $images_dir    Directorio local con subcarpetas por SKU
     * @param string $base_url      URL base publica de las imagenes
     * @param string $output_path   Ruta donde escribir el CSV generado
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

        fputcsv($handle, [
            'Type', 'SKU', 'Name', 'Published', 'Visibility in catalog',
            'Regular price', 'Categories', 'Images', 'In stock?', 'Stock',
        ]);

        $imported = 0;

        foreach ($master_rows as $row) {
            $name  = trim($row['name']       ?? $row['nombre']      ?? '');
            $price = trim($row['price']      ?? $row['precio']      ?? '');
            $cats  = trim($row['categories'] ?? $row['categorias']  ?? '');
            $sku   = trim($row['sku']        ?? $row['referencia']  ?? $sku_dict[$name] ?? '');

            if (empty($name)) continue;

            // Construir URLs absolutas con la carpeta SKU incluida en la ruta
            $images = self::build_image_urls($sku, $images_dir, $base_url);

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
            ]);

            $imported++;
        }

        fclose($handle);
        return $imported;
    }

    /**
     * Construye la lista de URLs absolutas para las imagenes de un SKU.
     *
     * La URL incluye la subcarpeta del SKU: <base_url><SKU>/<archivo>.jpg
     * Este es el fix del bug critico #1: las URLs deben incluir la carpeta SKU.
     */
    public static function build_image_urls(string $sku, string $images_dir, string $base_url): string {
        if (empty($sku)) return '';

        $sku_dir = trailingslashit($images_dir) . $sku;
        if (!is_dir($sku_dir)) return '';

        $extensions = ['jpg', 'jpeg', 'png', 'webp'];
        $files      = [];

        foreach (scandir($sku_dir) as $file) {
            if ($file === '.' || $file === '..') continue;
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, $extensions, true)) {
                $files[] = $file;
            }
        }

        if (empty($files)) return '';

        sort($files);

        // URL correcta: base_url + SKU_codificado + '/' + nombre_archivo_codificado
        $urls = array_map(
            fn($f) => $base_url . rawurlencode($sku) . '/' . rawurlencode($f),
            $files
        );

        return implode(',', $urls);
    }
}
