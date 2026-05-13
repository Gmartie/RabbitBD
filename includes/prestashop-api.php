<?php
/**
 * Conexion con la API de PrestaShop para descarga de productos e imagenes.
 * Implementa reintentos con backoff exponencial y validacion de imagenes.
 */
if (!defined('ABSPATH')) exit;

class Rabbit_Prestashop_API {

    private string $base_url;
    private string $api_key;

    // Numero de reintentos ante errores HTTP transitorios
    private const MAX_RETRIES = 3;

    public function __construct(string $base_url, string $api_key) {
        $this->base_url = rtrim($base_url, '/');
        $this->api_key  = $api_key;
    }

    public function get_product_ids(): array {
        $url  = "{$this->base_url}/api/products?output_format=JSON&display=[id]";
        $body = $this->request($url);
        if (is_wp_error($body)) return [];

        $data = json_decode($body, true);
        if (!isset($data['products']) || !is_array($data['products'])) return [];

        return array_column($data['products'], 'id');
    }

    public function get_product(int $product_id): array|false {
        $url  = "{$this->base_url}/api/products/{$product_id}?output_format=JSON";
        $body = $this->request($url);
        if (is_wp_error($body)) return false;

        $data = json_decode($body, true);
        if (!isset($data['product']) || !is_array($data['product'])) return false;

        return $data['product'];
    }

    public function download_image(int $product_id, int $image_id): string|false {
        $url  = "{$this->base_url}/api/images/products/{$product_id}/{$image_id}";
        $body = $this->request($url, true);
        if (is_wp_error($body) || empty($body)) return false;
        return $body;
    }

    /**
     * Descarga todas las imagenes de un producto y las guarda en <base_dir>/<SKU>/.
     * Valida el MIME real del archivo antes de guardarlo.
     *
     * @return string[]  Lista de nombres de archivo guardados
     */
    public function download_all_images_for_product(array $product, string $base_dir): array {
        $sku = sanitize_file_name($product['reference'] ?? '');

        if (empty($sku)) {
            $name = self::extract_multilang_field($product, 'name');
            rabbit_bd_log('', $name, 'error', 'Producto sin SKU, se omite.');
            return [];
        }

        $dir = trailingslashit($base_dir) . $sku;
        wp_mkdir_p($dir);

        // Validar que la asociacion de imagenes existe y es un array
        $image_ids = [];
        if (isset($product['associations']['images']) && is_array($product['associations']['images'])) {
            $image_ids = array_column($product['associations']['images'], 'id');
        }

        $saved = [];
        $index = 1;

        foreach ($image_ids as $img_id) {
            $bytes = $this->download_image((int)$product['id'], (int)$img_id);

            if ($bytes === false) {
                rabbit_bd_log($sku, '', 'error', "Imagen {$img_id} no descargada (404 o timeout).");
                continue;
            }

            // Validar MIME real del binario descargado
            if (!self::is_valid_image_bytes($bytes)) {
                rabbit_bd_log($sku, '', 'warning', "Imagen {$img_id} descartada: MIME invalido o datos corruptos.");
                continue;
            }

            $filename = sprintf('%s_%02d.jpg', $sku, $index);
            $filepath = "{$dir}/{$filename}";

            $result = file_put_contents($filepath, $bytes);

            // Verificar que la escritura fue exitosa
            if ($result === false) {
                rabbit_bd_log($sku, '', 'error', "No se pudo escribir {$filename}. Revisa permisos o espacio en disco.");
                continue;
            }

            // Verificar que el archivo no esta vacio tras escribir
            if (filesize($filepath) === 0) {
                rabbit_bd_log($sku, '', 'warning', "Imagen {$filename} guardada con 0 bytes, se elimina.");
                unlink($filepath);
                continue;
            }

            $saved[] = $filename;
            $index++;

            // Liberar memoria de la imagen procesada
            unset($bytes);
        }

        return $saved;
    }

    /**
     * Extrae un campo multilenguaje de la respuesta de PrestaShop de forma robusta.
     * PrestaShop puede devolver el campo como string, array indexado o array asociativo
     * segun la version y configuracion de idiomas.
     */
    public static function extract_multilang_field(array $product, string $field, int $lang_id = 1): string {
        $value = $product[$field] ?? '';

        if (is_string($value)) {
            return $value;
        }

        if (!is_array($value) || empty($value)) {
            return '';
        }

        // Buscar por id de idioma especifico
        foreach ($value as $entry) {
            if (is_array($entry) && isset($entry['id']) && (int)$entry['id'] === $lang_id) {
                return (string)($entry['value'] ?? '');
            }
        }

        // Fallback: primer elemento disponible
        $first = reset($value);
        if (is_array($first)) {
            return (string)($first['value'] ?? '');
        }

        return (string)$first;
    }

    /**
     * Peticion HTTP con reintentos y backoff exponencial.
     * Gestiona correctamente 404, 500, timeouts y rate limits.
     */
    private function request(string $url, bool $binary = false): string|\WP_Error {
        $args = [
            'headers' => ['Authorization' => 'Basic ' . base64_encode($this->api_key . ':')],
            'timeout' => 30,
        ];

        $last_error = null;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $response = wp_remote_get($url, $args);

            if (is_wp_error($response)) {
                $last_error = $response;
                // Espera antes del siguiente intento: 1s, 2s, 4s
                sleep(2 ** ($attempt - 1));
                continue;
            }

            $code = wp_remote_retrieve_response_code($response);

            // 429 Rate limit: esperar mas tiempo
            if ($code === 429) {
                sleep(10 * $attempt);
                continue;
            }

            // Errores de servidor: reintentar
            if ($code >= 500) {
                $last_error = new \WP_Error('http_error', "HTTP {$code} en {$url}");
                sleep(2 ** ($attempt - 1));
                continue;
            }

            // 404 o 403: no tiene sentido reintentar
            if ($code === 404 || $code === 403) {
                return new \WP_Error('http_' . $code, "HTTP {$code}: recurso no encontrado o sin acceso.");
            }

            if ($code !== 200) {
                return new \WP_Error('http_error', "HTTP {$code} inesperado en {$url}");
            }

            return wp_remote_retrieve_body($response);
        }

        return $last_error ?? new \WP_Error('max_retries', "Agotados {$attempt} intentos para {$url}");
    }

    /**
     * Comprueba que los bytes descargados corresponden a una imagen valida
     * verificando la firma magica del archivo (magic bytes).
     */
    private static function is_valid_image_bytes(string $bytes): bool {
        if (strlen($bytes) < 4) return false;

        $magic = substr($bytes, 0, 4);

        // JPEG: FF D8 FF
        if (str_starts_with($magic, "\xFF\xD8\xFF")) return true;
        // PNG:  89 50 4E 47
        if ($magic === "\x89PNG")                    return true;
        // WebP: 52 49 46 46 ... 57 45 42 50
        if (str_starts_with($magic, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') return true;

        return false;
    }
}
