<?php
/**
 * Validacion HTTP de imagenes y reconstruccion del CSV con URLs absolutas correctas.
 */
if (!defined('ABSPATH')) exit;

class Rabbit_Image_Builder {

    /**
     * Comprueba si una imagen es accesible publicamente por HTTP.
     */
    public static function is_image_accessible(string $url): bool {
        if (!filter_var($url, FILTER_VALIDATE_URL)) return false;

        $response = wp_remote_head($url, ['timeout' => 10, 'sslverify' => false]);
        if (is_wp_error($response)) return false;

        return wp_remote_retrieve_response_code($response) === 200;
    }

    /**
     * Recorre el CSV generado y reemplaza nombres de archivo por URLs absolutas
     * que incluyen la carpeta SKU en la ruta.
     *
     * Ejemplo de transformacion:
     *   "SKU_01.jpg"  ->  "http://staging.com/wp-content/uploads/imports/SKU/SKU_01.jpg"
     *
     * @param string $input_csv  CSV sin URLs absolutas
     * @param string $output_csv CSV con URLs absolutas
     * @param string $base_url   URL base publica (debe terminar en /)
     * @return int  Filas procesadas
     */
    public static function rebuild_csv_with_absolute_urls(
        string $input_csv,
        string $output_csv,
        string $base_url
    ): int {
        $base_url = trailingslashit($base_url);
        $in       = fopen($input_csv, 'r');
        $out      = fopen($output_csv, 'w');

        $header  = fgetcsv($in);
        $img_col = array_search('Images', $header);
        $sku_col = array_search('SKU', $header);
        fputcsv($out, $header);

        $count = 0;
        while (($row = fgetcsv($in)) !== false) {
            if ($img_col !== false && !empty($row[$img_col])) {
                $sku       = ($sku_col !== false) ? trim($row[$sku_col]) : '';
                $filenames = array_map('trim', explode(',', $row[$img_col]));
                $urls      = [];

                foreach ($filenames as $f) {
                    if (self::is_absolute_url($f)) {
                        $urls[] = $f;
                    } elseif ($sku !== '') {
                        // Reconstruir URL con la carpeta SKU incluida
                        $urls[] = $base_url . rawurlencode($sku) . '/' . rawurlencode(basename($f));
                    } else {
                        $urls[] = $base_url . rawurlencode(basename($f));
                    }
                }

                $row[$img_col] = implode(',', $urls);
            }

            fputcsv($out, $row);
            $count++;
        }

        fclose($in);
        fclose($out);
        return $count;
    }

    /**
     * Valida un archivo de imagen local: existencia, tamano y MIME.
     */
    public static function validate_local_image(string $filepath): bool {
        if (!file_exists($filepath))   return false;
        if (filesize($filepath) === 0) return false;

        $mime = mime_content_type($filepath);
        return in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);
    }

    private static function is_absolute_url(string $str): bool {
        return str_starts_with($str, 'http://') || str_starts_with($str, 'https://');
    }
}
