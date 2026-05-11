<?php
/**
 * Conexión con la API de PrestaShop para descarga de productos e imágenes.
 */
if (!defined('ABSPATH')) exit;

class Rabbit_Prestashop_API {

    private string $base_url;
    private string $api_key;

    public function __construct(string $base_url, string $api_key) {
        $this->base_url = rtrim($base_url, '/');
        $this->api_key  = $api_key;
    }

    public function get_product_ids(): array {
        $url  = "{$this->base_url}/api/products?output_format=JSON&display=[id]";
        $body = $this->request($url);
        if (is_wp_error($body)) return [];
        $data = json_decode($body, true);
        return array_column($data['products'] ?? [], 'id');
    }

    public function get_product(int $product_id): array|false {
        $url  = "{$this->base_url}/api/products/{$product_id}?output_format=JSON&display=[id,reference,name,associations]";
        $body = $this->request($url);
        if (is_wp_error($body)) return false;
        $data = json_decode($body, true);
        return $data['product'] ?? false;
    }

    public function download_image(int $product_id, int $image_id): string|false {
        $url  = "{$this->base_url}/api/images/products/{$product_id}/{$image_id}";
        $body = $this->request($url, true);
        if (is_wp_error($body) || empty($body)) return false;
        return $body;
    }

    public function download_all_images_for_product(array $product, string $base_dir): array {
        $sku = sanitize_file_name($product['reference'] ?? '');
        if (empty($sku)) {
            rabbit_bd_log('', $product['name'][0]['value'] ?? '?', 'error', 'Producto sin SKU, se omite.');
            return [];
        }

        $dir = trailingslashit($base_dir) . $sku;
        wp_mkdir_p($dir);

        $image_ids = array_column($product['associations']['images'] ?? [], 'id');
        $saved     = [];
        $index     = 1;

        foreach ($image_ids as $img_id) {
            $bytes = $this->download_image((int)$product['id'], (int)$img_id);
            if ($bytes === false) {
                rabbit_bd_log($sku, '', 'error', "Imagen {$img_id} no descargada (404 o timeout).");
                continue;
            }
            $filename = sprintf('%s_%02d.jpg', $sku, $index);
            file_put_contents("{$dir}/{$filename}", $bytes);
            $saved[]  = $filename;
            $index++;
        }

        return $saved;
    }

    private function request(string $url, bool $binary = false): string|\WP_Error {
        $response = wp_remote_get($url, [
            'headers' => ['Authorization' => 'Basic ' . base64_encode($this->api_key . ':')],
            'timeout'  => 30,
        ]);
        if (is_wp_error($response)) return $response;
        return wp_remote_retrieve_body($response);
    }
}

function rabbit_bd_log(string $sku, string $name, string $status, string $message): void {
    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . 'rabbit_bd_log',
        ['sku' => $sku, 'product_name' => $name, 'status' => $status, 'message' => $message],
        ['%s', '%s', '%s', '%s']
    );
}
