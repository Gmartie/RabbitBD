<?php
if (!defined('ABSPATH')) exit;

function rabbit_bd_create_tables(): void {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    // Tabla principal de log por producto
    $sql_log = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rabbit_bd_log (
        id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        sku          VARCHAR(100)  NOT NULL,
        product_name VARCHAR(255),
        status       VARCHAR(50)   NOT NULL DEFAULT 'pending',
        message      TEXT,
        created_at   DATETIME      DEFAULT CURRENT_TIMESTAMP
    ) $charset;";

    // Tabla de estado del batch (permite reanudar migraciones interrumpidas)
    $sql_state = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rabbit_bd_state (
        id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        batch_key      VARCHAR(100) NOT NULL UNIQUE,
        last_processed INT UNSIGNED NOT NULL DEFAULT 0,
        total          INT UNSIGNED NOT NULL DEFAULT 0,
        status         VARCHAR(50)  NOT NULL DEFAULT 'running',
        updated_at     DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql_log);
    dbDelta($sql_state);
}

/**
 * Registra una línea en el log de operaciones.
 */
function rabbit_bd_log(string $sku, string $name, string $status, string $message): void {
    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . 'rabbit_bd_log',
        [
            'sku'          => $sku,
            'product_name' => $name,
            'status'       => $status,
            'message'      => $message,
        ],
        ['%s', '%s', '%s', '%s']
    );
}

/**
 * Guarda el progreso del batch para poder reanudar si el proceso se interrumpe.
 */
function rabbit_bd_save_state(string $key, int $last_processed, int $total, string $status = 'running'): void {
    global $wpdb;
    $wpdb->replace(
        $wpdb->prefix . 'rabbit_bd_state',
        [
            'batch_key'      => $key,
            'last_processed' => $last_processed,
            'total'          => $total,
            'status'         => $status,
        ],
        ['%s', '%d', '%d', '%s']
    );
}

/**
 * Recupera el estado guardado de un batch.
 *
 * @return array{last_processed:int,total:int,status:string}|null
 */
function rabbit_bd_get_state(string $key): ?array {
    global $wpdb;
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT last_processed, total, status FROM {$wpdb->prefix}rabbit_bd_state WHERE batch_key = %s",
            $key
        ),
        ARRAY_A
    );
    return $row ?: null;
}
