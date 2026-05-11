<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_menu_page(
        'Rabbit BD',
        'Rabbit BD',
        'manage_woocommerce',
        'rabbit-bd',
        'rabbit_bd_page',
        rabbit_bd_menu_icon(),
        56
    );
});

add_action('admin_init', function () {
    register_setting('rabbit_bd_options', 'rabbit_bd_presta_url');
    register_setting('rabbit_bd_options', 'rabbit_bd_presta_key');
    register_setting('rabbit_bd_options', 'rabbit_bd_images_dir');
    register_setting('rabbit_bd_options', 'rabbit_bd_base_url');
    register_setting('rabbit_bd_options', 'rabbit_bd_staging_url');
});

add_action('admin_enqueue_scripts', function (string $hook) {
    if ($hook !== 'toplevel_page_rabbit-bd') return;

    wp_enqueue_style(
        'rabbit-bd-admin',
        RABBIT_BD_URL . 'public/css/admin.css',
        [],
        RABBIT_BD_VERSION
    );

    wp_enqueue_script(
        'rabbit-bd-admin',
        RABBIT_BD_URL . 'public/js/admin.js',
        ['jquery'],
        RABBIT_BD_VERSION,
        true
    );

    wp_localize_script('rabbit-bd-admin', 'rabbitBD', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('rabbit_bd_nonce'),
    ]);
});

function rabbit_bd_page(): void {
    $presta_url  = get_option('rabbit_bd_presta_url', '');
    $presta_key  = get_option('rabbit_bd_presta_key', '');
    $images_dir  = get_option('rabbit_bd_images_dir', WP_CONTENT_DIR . '/uploads/rabbit-bd');
    $base_url    = get_option('rabbit_bd_base_url', '');
    $staging_url = get_option('rabbit_bd_staging_url', '');
    ?>
    <div class="wrap rabbit-bd-wrap">

        <div class="rabbit-header">
            <img src="<?php echo esc_url(RABBIT_BD_URL . 'RabbitBDLogo.svg'); ?>" alt="Rabbit BD" class="rabbit-logo">
            <div class="rabbit-header-text">
                <h1>Rabbit BD</h1>
                <p class="rabbit-subtitle">Migración de sitios · PrestaShop → WooCommerce · SKUs · Multicategorías · Galerías</p>
            </div>
        </div>

        <?php settings_errors('rabbit_bd_options'); ?>

        <div class="rabbit-tabs">
            <button class="rabbit-tab active" data-tab="config">⚙️ Configuración</button>
            <button class="rabbit-tab" data-tab="step1">1 · Descargar imágenes</button>
            <button class="rabbit-tab" data-tab="step2">2 · Generar CSV</button>
            <button class="rabbit-tab" data-tab="step3">3 · Validar URL</button>
            <button class="rabbit-tab" data-tab="step4">4 · Importar</button>
            <button class="rabbit-tab" data-tab="log">📋 Log</button>
        </div>

        <!-- CONFIGURACIÓN -->
        <div class="rabbit-panel active" id="tab-config">
            <form method="post" action="options.php">
                <?php settings_fields('rabbit_bd_options'); ?>
                <h2>Conexión PrestaShop</h2>
                <table class="form-table">
                    <tr>
                        <th>URL base de PrestaShop</th>
                        <td>
                            <input type="url" name="rabbit_bd_presta_url"
                                   value="<?php echo esc_attr($presta_url); ?>"
                                   class="regular-text" placeholder="https://mi-tienda.com">
                            <p class="description">Sin barra final.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>API Key de PrestaShop</th>
                        <td>
                            <input type="text" name="rabbit_bd_presta_key"
                                   value="<?php echo esc_attr($presta_key); ?>"
                                   class="regular-text" autocomplete="off">
                            <p class="description">Con permisos GET sobre productos e imágenes.</p>
                        </td>
                    </tr>
                </table>

                <h2>Rutas y URLs</h2>
                <table class="form-table">
                    <tr>
                        <th>Directorio local de imágenes</th>
                        <td>
                            <input type="text" name="rabbit_bd_images_dir"
                                   value="<?php echo esc_attr($images_dir); ?>"
                                   class="large-text">
                            <p class="description">Ruta absoluta del servidor. Las carpetas se organizan por SKU.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>URL base de imágenes (FTP)</th>
                        <td>
                            <input type="url" name="rabbit_bd_base_url"
                                   value="<?php echo esc_attr($base_url); ?>"
                                   class="large-text"
                                   placeholder="http://staging.ejemplo.com/wp-content/uploads/imports/">
                            <p class="description">Debe terminar en /. Ruta pública donde se subieron las imágenes por FTP.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>URL del staging de WordPress</th>
                        <td>
                            <input type="url" name="rabbit_bd_staging_url"
                                   value="<?php echo esc_attr($staging_url); ?>"
                                   class="large-text"
                                   placeholder="http://mi-sitio.staging.com">
                        </td>
                    </tr>
                </table>

                <?php submit_button('Guardar configuración'); ?>
            </form>
        </div>

        <!-- PASO 1 -->
        <div class="rabbit-panel" id="tab-step1">
            <h2>Paso 1 · Descargar imágenes desde PrestaShop via API Key</h2>
            <p>Consulta todos los productos, obtiene los IDs de imágenes de cada uno y los descarga organizados en subcarpetas por SKU.</p>

            <div class="rabbit-info-box">
                <strong>Directorio de destino:</strong><br>
                <code><?php echo esc_html($images_dir); ?></code>
                <br><small>Estructura: <code>/SKU/SKU_01.jpg</code>, <code>/SKU/SKU_02.jpg</code>…</small>
            </div>

            <?php if (empty($presta_url) || empty($presta_key)): ?>
                <div class="rabbit-warning">⚠️ Configura primero la URL y API Key de PrestaShop en la pestaña Configuración.</div>
            <?php else: ?>
                <button id="btn-download-images" class="button button-primary button-large">
                    ⬇️ Iniciar descarga de imágenes
                </button>
                <div id="download-result" class="rabbit-result" style="display:none"></div>
            <?php endif; ?>
        </div>

        <!-- PASO 2 -->
        <div class="rabbit-panel" id="tab-step2">
            <h2>Paso 2 · Generar CSV de importación para WooCommerce</h2>
            <p>Sube el CSV de PrestaShop (opcional, para mapear SKUs) y el CSV MASTER del cliente. Se generará el CSV final con galerías y URLs absolutas.</p>

            <div class="rabbit-info-box">
                <strong>Columnas esperadas en el MASTER:</strong><br>
                <code>name</code> · <code>sku</code> (o <code>referencia</code>) · <code>price</code> · <code>categories</code>
                <br><small>Multicategorías separadas por coma: <em>Bancos, Sillones, Sillones madera</em></small>
            </div>

            <form id="form-generate-csv" enctype="multipart/form-data">
                <table class="form-table">
                    <tr>
                        <th>CSV de PrestaShop <em>(opcional)</em></th>
                        <td>
                            <input type="file" name="presta_csv" accept=".csv,.txt">
                            <p class="description">Diccionario nombre ↔ SKU si el MASTER no tiene SKU.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>CSV / MASTER del cliente <em>(obligatorio)</em></th>
                        <td><input type="file" name="master_file" accept=".csv,.txt" required></td>
                    </tr>
                    <tr>
                        <th>URL base de imágenes</th>
                        <td>
                            <input type="url" id="gen-base-url" name="base_url"
                                   value="<?php echo esc_attr($base_url); ?>"
                                   class="large-text">
                            <p class="description">URLs absolutas en columna Images (fix definitivo).</p>
                        </td>
                    </tr>
                </table>
                <button type="submit" class="button button-primary button-large">🔧 Generar CSV de WooCommerce</button>
            </form>
            <div id="csv-result" class="rabbit-result" style="display:none"></div>

            <hr>
            <h3>Mapeo recomendado al importar en WooCommerce</h3>
            <table class="widefat striped" style="max-width:500px">
                <thead><tr><th>Campo CSV</th><th>Campo WooCommerce</th></tr></thead>
                <tbody>
                    <tr><td>SKU</td><td>SKU</td></tr>
                    <tr><td>Name</td><td>Nombre</td></tr>
                    <tr><td>Regular price</td><td>Precio normal</td></tr>
                    <tr><td>Categories</td><td>Categorías</td></tr>
                    <tr><td>Images</td><td>Imágenes</td></tr>
                    <tr><td><em>Gallery images</em></td><td>⛔ No importar (evita duplicados)</td></tr>
                </tbody>
            </table>
        </div>

        <!-- PASO 3 -->
        <div class="rabbit-panel" id="tab-step3">
            <h2>Paso 3 · Validar accesibilidad HTTP de imágenes</h2>
            <p>Verifica que el servidor sirve correctamente los estáticos subidos por FTP antes de importar en WooCommerce.</p>

            <div style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap">
                <input type="url" id="test-image-url" class="large-text"
                       placeholder="<?php echo esc_attr(rtrim($base_url, '/') . '/EJEMPLO_01.jpg'); ?>"
                       style="max-width:480px">
                <button id="btn-test-url" class="button button-secondary">🔍 Testear URL</button>
            </div>
            <div id="url-test-result" class="rabbit-result" style="display:none;margin-top:10px"></div>

            <div class="rabbit-info-box" style="margin-top:20px">
                <strong>¿Qué buscamos?</strong> Que la URL devuelva HTTP 200. Si devuelve 404, comprueba:
                <ul style="list-style:disc;padding-left:20px">
                    <li>Que WordPress esté en el directorio correcto (<code>wp-admin</code>, <code>wp-content</code>).</li>
                    <li>La configuración de <code>.htaccess</code> / rewrite del subdominio.</li>
                    <li>Que las imágenes estén en <code>/wp-content/uploads/imports/</code>.</li>
                </ul>
            </div>
        </div>

        <!-- PASO 4 -->
        <div class="rabbit-panel" id="tab-step4">
            <h2>Paso 4 · Importar en WooCommerce</h2>
            <ol class="rabbit-steps">
                <li>Ve a <strong>WP Admin → Productos → Importar</strong>.</li>
                <li>Sube el archivo <code>rabbit-bd-import-URLS.csv</code> generado en el Paso 2.</li>
                <li>Aplica el mapeo indicado en la tabla del Paso 2.</li>
                <li>Marca <em>"Actualizar productos existentes"</em> si ya hubo un intento previo.</li>
                <li>Valida varios productos: imagen destacada, galería completa, categorías y SKU.</li>
            </ol>

            <div class="rabbit-info-box">
                <strong>Limpieza post-importación:</strong><br>
                Una vez verificado, elimina <code>/wp-content/uploads/imports/</code> — WooCommerce ya habrá copiado las imágenes a la biblioteca de medios.<br><br>
                Habilita SSL y fuerza HTTPS cuando la importación esté verificada.
            </div>

            <?php
            $upload_dir = wp_upload_dir();
            $csv_url    = $upload_dir['baseurl'] . '/rabbit-bd-import-URLS.csv';
            $csv_path   = $upload_dir['basedir'] . '/rabbit-bd-import-URLS.csv';
            ?>
            <?php if (file_exists($csv_path)): ?>
                <p>
                    <a href="<?php echo esc_url($csv_url); ?>" class="button button-primary" download>⬇️ Descargar CSV final</a>
                    <a href="<?php echo esc_url(admin_url('edit.php?post_type=product&page=product_importer')); ?>"
                       class="button button-secondary" target="_blank">🚀 Abrir importador de WooCommerce</a>
                </p>
            <?php else: ?>
                <p class="rabbit-warning">⚠️ Aún no se ha generado el CSV. Completa el Paso 2 primero.</p>
            <?php endif; ?>
        </div>

        <!-- LOG -->
        <div class="rabbit-panel" id="tab-log">
            <h2>📋 Log de operaciones</h2>
            <p>
                <button id="btn-load-log" class="button">🔄 Cargar log</button>
                <button id="btn-clear-log" class="button" style="margin-left:8px">🗑️ Limpiar log</button>
            </p>
            <div id="log-table-wrapper"></div>
        </div>

    </div>
    <?php
}

function rabbit_bd_menu_icon(): string {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><circle cx="10" cy="10" r="9" fill="white" opacity="0.8"/></svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}
