<?php
/**
 * Validación HTTP de imágenes subidas por FTP (paso 5 del doc) y
 * regeneración del CSV con URLs absolutas (paso 6.2 — fix definitivo).
 */
if (!defined('ABSPATH')) exit;

class Rabbit_Image_Builder {

    /**
     * Comprueba si una imagen es accesible públicamente por HTTP.
     * Es el "test definitivo" descrito en el documento.
     *
     * @param string $url URL absoluta de la imagen a testear
     * @return bool
     */
    public static function is_image_accessible(string $url): bool {
        $response = wp_remote_head($url, ['timeout' => 10, 'sslverify' => false]);
        if (is_wp_error($response)) return false;

        $code = wp_remote_retrieve_response_code($response);
        return $code === 200;
    }

    /**
     * Itera sobre el CSV de importación y sustituye nombres de archivo
     * por URLs absolutas (columna "Images").
     * Implementa exactamente el fix del paso 6.2 del documento.
     *
     * @param string $input_csv  CSV sin URLs absolutas
     * @param string $output_csv CSV con URLs absolutas
     * @param string $base_url   p.ej. http://staging.com/wp-content/uploads/imports/
     * @return int  Número de filas procesadas
     */
    public static function rebuild_csv_with_absolute_urls(
        string $input_csv,
        string $output_csv,
        string $base_url
    ): int {
        $base_url = trailingslashit($base_url);
        $in       = fopen($input_csv, 'r');
        $out      = fopen($output_csv, 'w');

        $header    = fgetcsv($in);
        $img_col   = array_search('Images', $header);
        fputcsv($out, $header);

        $count = 0;
        while (($row = fgetcsv($in)) !== false) {
            if ($img_col !== false && !empty($row[$img_col])) {
                $filenames    = array_map('trim', explode(',', $row[$img_col]));
                $urls         = array_map(fn($f) => self::is_relative($f) ? $base_url . $f : $f, $filenames);
                $row[$img_col] = implode(',', $urls);
            }
            fputcsv($out, $row);
            $count++;
        }

        fclose($in);
        fclose($out);
        return $count;
    }

    // ------------------------------------------------------------------ //

    private static function is_relative(string $str): bool {
        return !str_starts_with($str, 'http://') && !str_starts_with($str, 'https://');
    }
}
