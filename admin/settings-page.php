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

// Ocultar el footer de WordPress solo en la página del plugin
add_filter('admin_footer_text', function ($text) {
    $screen = get_current_screen();
    if ($screen && $screen->id === 'toplevel_page_rabbit-bd') return '';
    return $text;
});
add_filter('update_footer', function ($text) {
    $screen = get_current_screen();
    if ($screen && $screen->id === 'toplevel_page_rabbit-bd') return '';
    return $text;
}, 99);

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
        'ajax_url'   => admin_url('admin-ajax.php'),
        'nonce'      => wp_create_nonce('rabbit_bd_nonce'),
        'batch_size' => RABBIT_BD_BATCH_SIZE,
        'presta_url' => get_option('rabbit_bd_presta_url', ''),
        'presta_key' => get_option('rabbit_bd_presta_key', ''),
    ]);
});

function rabbit_bd_page(): void {
    $presta_url  = get_option('rabbit_bd_presta_url', '');
    $presta_key  = get_option('rabbit_bd_presta_key', '');
    $images_dir  = get_option('rabbit_bd_images_dir', WP_CONTENT_DIR . '/uploads/rabbit-bd');
    $base_url    = get_option('rabbit_bd_base_url', '');
    $staging_url = get_option('rabbit_bd_staging_url', get_site_url());
    ?>
    <div class="wrap rabbit-bd-wrap">

        <div class="rabbit-header">
            <img src="<?php echo esc_url(RABBIT_BD_URL . 'RabbitBDLogo.svg'); ?>" alt="Rabbit BD" class="rabbit-logo">
            <div class="rabbit-header-text">
                <h1>Rabbit BD</h1>
                <p class="rabbit-subtitle">Migracion de sitios · PrestaShop &rarr; WooCommerce · SKUs · Multicategorias · Galerias</p>
            </div>
        </div>

        <?php settings_errors('rabbit_bd_options'); ?>

        <div class="rabbit-tabs">
            <button class="rabbit-tab active" data-tab="config">Configuracion</button>
            <button class="rabbit-tab" data-tab="step0">0 · Exportar MASTER</button>
            <button class="rabbit-tab" data-tab="step1">1 · Descargar imagenes</button>
            <button class="rabbit-tab" data-tab="step2">2 · Generar CSV</button>
            <button class="rabbit-tab" data-tab="step3">3 · Validar URL</button>
            <button class="rabbit-tab" data-tab="step4">4 · Importar</button>
            <button class="rabbit-tab" data-tab="exportdb"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.66 4.03 3 9 3s9-1.34 9-3V5"/><path d="M3 12c0 1.66 4.03 3 9 3s9-1.34 9-3"/></svg>Exportar BD</button>
            <button class="rabbit-tab" data-tab="log">Log</button>
        </div>

        <!-- CONFIGURACION -->
        <div class="rabbit-panel active" id="tab-config">
            <form method="post" action="options.php">
                <?php settings_fields('rabbit_bd_options'); ?>
                <h2>Conexion PrestaShop</h2>
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
                            <p class="description">Con permisos GET sobre productos e imagenes.</p>
                        </td>
                    </tr>
                </table>

                <h2>Rutas y URLs</h2>
                <table class="form-table">
                    <tr>
                        <th>Directorio local de imagenes</th>
                        <td>
                            <input type="text" name="rabbit_bd_images_dir"
                                   value="<?php echo esc_attr($images_dir); ?>"
                                   class="large-text">
                            <p class="description">Ruta absoluta del servidor donde se descargan las imágenes temporalmente. Las imagenes se organizan en subcarpetas por nombre de producto.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>URL pública de imágenes</th>
                        <td>
                            <?php
                            $upload_info = wp_upload_dir();
                            $auto_public_url = trailingslashit($upload_info['baseurl']) . 'rabbit-bd/';
                            ?>
                            <code><?php echo esc_html($auto_public_url); ?></code>
                            <p class="description">Generada automáticamente. El plugin copia las imágenes aquí tras descargarlas, sin necesidad de FTP.</p>
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

                <?php submit_button('Guardar configuracion'); ?>
            </form>
        </div>

        <!-- PASO 0: Exportar MASTER CSV desde PrestaShop -->
        <div class="rabbit-panel" id="tab-step0">
            <h2>Paso 0 · Exportar MASTER CSV desde PrestaShop</h2>
            <p>Genera automáticamente el archivo MASTER con todos tus productos extraídos directamente desde la API de PrestaShop. El CSV incluye nombre, SKU, precio y categorías, listo para usar en el Paso 2.</p>

            <div class="rabbit-info-box">
                <strong>Columnas generadas:</strong><br>
                <code>name</code> · <code>sku</code> · <code>price</code> · <code>categories</code><br>
                <small>Las categorías raíz (Home, Root) se excluyen automáticamente.</small>
            </div>

            <?php if (empty($presta_url) || empty($presta_key)): ?>
                <div class="rabbit-warning">Configura primero la URL y API Key de PrestaShop en la pestaña Configuracion.</div>
            <?php else: ?>
                <button id="btn-generate-master" class="button button-primary button-large">Exportar MASTER desde PrestaShop</button>
                <div id="master-result" class="rabbit-result" style="display:none"></div>
            <?php endif; ?>
        </div>

        <!-- PASO 1 -->
        <div class="rabbit-panel" id="tab-step1">
            <h2>Paso 1 · Descargar imagenes desde PrestaShop via API Key</h2>
            <p>Consulta todos los productos, obtiene los IDs de imagenes de cada uno y los descarga organizados en subcarpetas por SKU. El proceso se ejecuta en lotes de <?php echo esc_html(RABBIT_BD_BATCH_SIZE); ?> productos para evitar timeouts.</p>

            <div class="rabbit-info-box">
                <strong>Directorio de destino:</strong><br>
                <code><?php echo esc_html($images_dir); ?></code>
                <br><small>Estructura: <code>/SKU/SKU_01.jpg</code>, <code>/SKU/SKU_02.jpg</code>...</small>
            </div>

            <?php if (empty($presta_url) || empty($presta_key)): ?>
                <div class="rabbit-warning">Configura primero la URL y API Key de PrestaShop en la pestana Configuracion.</div>
            <?php else: ?>
                <div class="rabbit-progress-bar" id="download-progress-wrap" style="display:none">
                    <div class="rabbit-progress-inner" id="download-progress-bar"></div>
                    <span id="download-progress-label">0 / 0</span>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
                    <button id="btn-download-images" class="button button-primary button-large">Iniciar descarga de imagenes</button>
                    <button id="btn-resume-download" class="button button-secondary button-large" style="display:none">Reanudar descarga</button>
                    <button id="btn-pause-download" class="button button-secondary button-large" style="display:none">Pausar</button>
                </div>
                <div id="download-result" class="rabbit-result" style="display:none"></div>
            <?php endif; ?>
        </div>

        <!-- PASO 2 -->
        <div class="rabbit-panel" id="tab-step2">
            <h2>Paso 2 · Generar CSV de importacion para WooCommerce</h2>
            <p>Sube el CSV de PrestaShop (opcional, para mapear SKUs) y el CSV MASTER del cliente. El plugin autodetecta el delimitador del archivo y genera el CSV final con galerias y URLs absolutas correctas (incluye la subcarpeta del SKU).</p>

            <div class="rabbit-info-box">
                <strong>Columnas esperadas en el MASTER:</strong><br>
                <code>name</code> · <code>sku</code> (o <code>referencia</code>) · <code>price</code> · <code>categories</code>
                <br><small>Multicategorias separadas por coma: <em>Bancos, Sillones, Sillones madera</em></small>
            </div>

            <form id="form-generate-csv" enctype="multipart/form-data">
                <?php
                $upload_info     = wp_upload_dir();
                $auto_public_url = trailingslashit($upload_info['baseurl']) . 'rabbit-bd/';
                ?>
                <table class="form-table">
                    <tr>
                        <th>CSV de PrestaShop <em>(opcional)</em></th>
                        <td>
                            <input type="file" name="presta_csv" accept=".csv,.txt">
                            <p class="description">Diccionario nombre &harr; SKU si el MASTER no tiene SKU.</p>
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
                                   value="<?php echo esc_attr($auto_public_url); ?>"
                                   class="large-text">
                            <p class="description">Rellena automáticamente con la carpeta pública generada por el plugin. Las URLs finales tendrán formato: <code><?php echo esc_html($auto_public_url); ?>nombre-producto/nombre-producto_01.jpg</code></p>
                        </td>
                    </tr>
                </table>
                <button type="submit" class="button button-primary button-large">Generar CSV de WooCommerce</button>
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
                    <tr><td>Categories</td><td>Categorias</td></tr>
                    <tr><td>Images</td><td>Imagenes</td></tr>
                    <tr><td><em>Gallery images</em></td><td>No importar (evita duplicados)</td></tr>
                </tbody>
            </table>
        </div>

        <!-- PASO 3 -->
        <div class="rabbit-panel" id="tab-step3">
            <h2>Paso 3 · Validar accesibilidad HTTP de imágenes</h2>
            <p>Verifica que las imágenes copiadas automáticamente son accesibles por HTTP antes de importar en WooCommerce.</p>

            <?php
            $upload_info     = wp_upload_dir();
            $auto_public_url = trailingslashit($upload_info['baseurl']) . 'rabbit-bd/';
            // Buscar una imagen real de ejemplo en la carpeta pública
            $public_base_dir = trailingslashit($upload_info['basedir']) . 'rabbit-bd/';
            $example_url     = '';
            if (is_dir($public_base_dir)) {
                foreach (new DirectoryIterator($public_base_dir) as $item) {
                    if ($item->isDot() || !$item->isDir()) continue;
                    $subdir = $item->getPathname();
                    $slug   = $item->getFilename();
                    foreach (glob($subdir . '/*.jpg') as $img) {
                        $example_url = $auto_public_url . $slug . '/' . basename($img);
                        break 2;
                    }
                }
            }
            if (!$example_url) {
                $example_url = $auto_public_url . 'nombre-producto/nombre-producto_01.jpg';
            }
            ?>

            <div class="rabbit-info-box">
                <strong>URL pública generada automáticamente:</strong><br>
                <code><?php echo esc_html($auto_public_url); ?></code><br>
                <small>El Paso 1 ya copió las imágenes aquí. Puedes testear una URL de ejemplo abajo.</small>
            </div>

            <div style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap;margin-top:16px">
                <input type="url" id="test-image-url" class="large-text"
                       value="<?php echo esc_attr($example_url); ?>"
                       style="max-width:560px">
                <button id="btn-test-url" class="button button-secondary">Testear URL</button>
            </div>
            <div id="url-test-result" class="rabbit-result" style="display:none;margin-top:10px"></div>
        </div>

        <!-- PASO 4 -->
        <div class="rabbit-panel" id="tab-step4">
            <h2>Paso 4 · Importar en WooCommerce</h2>
            <ol class="rabbit-steps">
                <li>Ve a <strong>WP Admin &rarr; Productos &rarr; Importar</strong>.</li>
                <li>Sube el archivo <code>rabbit-bd-import-URLS.csv</code> generado en el Paso 2.</li>
                <li>Aplica el mapeo indicado en la tabla del Paso 2.</li>
                <li>Marca <em>"Actualizar productos existentes"</em> si ya hubo un intento previo.</li>
                <li>Valida varios productos: imagen destacada, galeria completa, categorias y SKU.</li>
            </ol>

            <div class="rabbit-info-box">
                <strong>Limpieza post-importacion:</strong><br>
                Una vez verificado, elimina <code>/wp-content/uploads/imports/</code> &mdash; WooCommerce ya habra copiado las imagenes a la biblioteca de medios.<br><br>
                Habilita SSL y fuerza HTTPS cuando la importacion este verificada.
            </div>

            <?php
            $upload_dir = wp_upload_dir();
            $csv_url    = $upload_dir['baseurl'] . '/rabbit-bd-import-URLS.csv';
            $csv_path   = $upload_dir['basedir'] . '/rabbit-bd-import-URLS.csv';
            ?>
            <?php if (file_exists($csv_path)): ?>
                <p style="margin-top:16px">
                    <a href="<?php echo esc_url($csv_url); ?>" class="button button-primary" download>Descargar CSV final</a>
                    <a href="<?php echo esc_url(admin_url('edit.php?post_type=product&page=product_importer')); ?>"
                       class="button button-secondary" target="_blank">Abrir importador de WooCommerce</a>
                </p>
            <?php else: ?>
                <p class="rabbit-warning">Aun no se ha generado el CSV. Completa el Paso 2 primero.</p>
            <?php endif; ?>
        </div>

        <!-- LOG -->
        <!-- EXPORTAR BD -->
        <div class="rabbit-panel" id="tab-exportdb">
            <h2>Exportar Base de Datos</h2>
            <p>Descarga una copia completa de la base de datos de WordPress en formato SQL (restaurable) o CSV (legible en Excel). Útil para hacer backup antes de la migración o para auditar datos.</p>

            <div class="rabbit-info-box">
                <strong>Formatos disponibles:</strong><br>
                <code>SQL</code> — Volcado completo con CREATE TABLE + INSERT, restaurable con phpMyAdmin o MySQL.<br>
                <code>CSV (ZIP)</code> — Una hoja por tabla, comprimidas en ZIP. Ideal para abrir en Excel o Google Sheets.
            </div>

            <div id="db-tables-wrapper" style="margin:16px 0 20px">
                <button id="btn-load-tables" class="button">Cargar lista de tablas</button>
                <div id="db-tables-list" style="margin-top:12px;display:none">
                    <p><strong>Tablas disponibles:</strong> <span id="db-tables-count">—</span></p>
                    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px" id="db-tables-checkboxes"></div>
                    <p style="margin-top:10px">
                        <label><input type="checkbox" id="db-select-all" checked> Seleccionar todas</label>
                    </p>
                </div>
            </div>

            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                <button id="btn-export-sql" class="button button-primary button-large" disabled>⬇ Descargar SQL</button>
                <button id="btn-export-csv" class="button button-secondary button-large" disabled>⬇ Descargar CSV (ZIP)</button>
            </div>

            <div id="export-db-result" class="rabbit-result" style="display:none;margin-top:16px"></div>

            <div class="rabbit-info-box" style="margin-top:20px;background:#fff8e1;border-left-color:#f5a623">
                <strong>⚠ Aviso de seguridad:</strong> Los archivos exportados se guardan temporalmente en <code>wp-content/uploads/</code> y son accesibles públicamente por URL. Descárgalos y elimínalos del servidor inmediatamente después.
            </div>
        </div>

        <div class="rabbit-panel" id="tab-log">
            <h2>Log de operaciones</h2>
            <p>
                <button id="btn-load-log" class="button">Cargar log</button>
                <button id="btn-clear-log" class="button" style="margin-left:8px">Limpiar log</button>
            </p>
            <div id="log-table-wrapper"></div>
        </div>

    </div>

    <!-- ASISTENTE RABBIT -->
    <div id="rabbit-assistant" class="rabbit-assistant-bubble" title="Ayuda Rabbit">
        <img src="<?php echo esc_url(RABBIT_BD_URL . 'RabbitBDLogo.svg'); ?>" alt="Rabbit" width="32" height="32">
    </div>

    <div id="rabbit-chat-panel" class="rabbit-chat-panel" style="display:none">
        <div class="rabbit-chat-header">
            <span>Rabbit — Ayuda</span>
            <button id="rabbit-chat-close" class="rabbit-chat-close">✕</button>
        </div>
        <div id="rabbit-chat-messages" class="rabbit-chat-messages"></div>
    </div>

    <script>
    (function() {
        // ── Árbol de conversación ──────────────────────────────────────────────
        var TREE = {
            start: {
                bot: '¡Hola! Soy Rabbit. ¿En qué puedo ayudarte?',
                opts: [
                    { label: '¿Cómo funciona el plugin?',        next: 'overview'      },
                    { label: 'Error en Paso 0 (Exportar MASTER)', next: 'err_paso0'     },
                    { label: 'Error en Paso 1 (Imágenes)',         next: 'err_paso1'     },
                    { label: 'Error en Paso 2 (Generar CSV)',       next: 'err_paso2'     },
                    { label: 'Error en Paso 3 / 4',                next: 'err_paso34'    },
                    { label: '¿Cómo exporto la base de datos?',    next: 'exportdb'      },
                    { label: 'Configuración inicial',              next: 'config'        },
                    { label: 'El log está vacío / no aparece',     next: 'err_log'       },
                ]
            },

            // ── Visión general ────────────────────────────────────────────────
            overview: {
                bot: 'Rabbit BD migra productos de PrestaShop a WooCommerce en estos pasos:\n\n0️⃣ Exportar MASTER — genera el CSV de productos desde la API de PrestaShop.\n1️⃣ Descargar imágenes — las guarda en carpetas por nombre de producto.\n2️⃣ Generar CSV — produce el archivo listo para WooCommerce.\n3️⃣ Validar URLs — comprueba accesibilidad de imágenes.\n4️⃣ Importar — desde WooCommerce → Productos → Importar.\n🗄 Exportar BD — descarga la base de datos en SQL o CSV.',
                opts: [
                    { label: '¿Qué necesito antes de empezar?', next: 'config'      },
                    { label: 'Explícame el Paso 0',              next: 'detail_p0'  },
                    { label: 'Explícame el Paso 1',              next: 'detail_p1'  },
                    { label: 'Explícame el Paso 2',              next: 'detail_p2'  },
                    { label: '¿Cómo exporto la BD?',             next: 'exportdb'   },
                    { label: '← Volver al inicio',               next: 'start'     },
                ]
            },

            // ── Detalle Paso 0 ───────────────────────────────────────────────
            detail_p0: {
                bot: 'El Paso 0 genera el CSV MASTER automáticamente desde PrestaShop:\n\n• Se conecta a la API y obtiene todos los productos.\n• Exporta: nombre, SKU, precio y categorías (sin Home/Root).\n• Descarga el archivo rabbit-bd-master.csv listo para el Paso 2.\n\nNecesitas URL y API Key configuradas en la pestaña Configuración.',
                opts: [
                    { label: 'No conecta / no encuentra productos', next: 'err_no_products' },
                    { label: 'URL de PrestaShop no válida',          next: 'err_url'         },
                    { label: 'Error de permisos 403',                next: 'err_403'         },
                    { label: '← Volver al inicio',                   next: 'start'          },
                ]
            },

            // ── Errores Paso 0 ───────────────────────────────────────────────
            err_paso0: {
                bot: '¿Qué error tienes en el Paso 0 (Exportar MASTER)?',
                opts: [
                    { label: 'No conecta / no encuentra productos', next: 'err_no_products' },
                    { label: 'URL de PrestaShop no válida',          next: 'err_url'         },
                    { label: 'Error 403 / sin permisos',             next: 'err_403'         },
                    { label: 'No aparece el botón de exportar',      next: 'err_paso0_nobtn' },
                    { label: '← Volver al inicio',                   next: 'start'          },
                ]
            },

            err_paso0_nobtn: {
                bot: 'Si no aparece el botón "Exportar MASTER":\n\n• La URL o la API Key de PrestaShop no están guardadas.\n• Ve a la pestaña Configuración, rellena ambos campos y pulsa "Guardar configuración".\n• Vuelve al Paso 0 y el botón aparecerá.',
                opts: [
                    { label: '¿Cómo creo la API Key?',  next: 'apikey'  },
                    { label: '← Volver al inicio',       next: 'start'  },
                ]
            },

            // ── Exportar BD ─────────────────────────────────────────────────
            exportdb: {
                bot: 'La pestaña "🗄 Exportar BD" descarga toda la base de datos de WordPress:\n\n• SQL — volcado completo restaurable desde phpMyAdmin.\n• CSV (ZIP) — cada tabla como CSV separado en un ZIP para Excel.\n\nPasos:\n1. Pulsa "Cargar lista de tablas".\n2. Selecciona las tablas que quieras.\n3. Elige formato y descarga.\n4. ⚠ Borra el archivo del servidor tras descargarlo — queda accesible públicamente en uploads/.',
                opts: [
                    { label: 'El ZIP no se genera',              next: 'err_export_zip' },
                    { label: 'Cómo restaurar el SQL',             next: 'err_export_sql' },
                    { label: 'Cómo borrar el archivo exportado',  next: 'err_export_sec' },
                    { label: '← Volver al inicio',                next: 'start'         },
                ]
            },

            err_export_zip: {
                bot: 'Si el ZIP no se genera:\n\n• El servidor puede no tener la extensión ZipArchive de PHP.\n• En ese caso el plugin exporta todas las tablas en un único CSV con separadores.\n• Pide a tu hosting que active php-zip (ext-zip).',
                opts: [
                    { label: '← Volver al inicio',  next: 'start' },
                ]
            },

            err_export_sql: {
                bot: 'Para restaurar el SQL exportado:\n\n1. phpMyAdmin → selecciona la base de datos destino.\n2. Pestaña "Importar" → selecciona el archivo .sql.\n3. Codificación: utf8mb4 → Continuar.\n\nEl archivo usa SET FOREIGN_KEY_CHECKS=0 para evitar errores de clave foránea.',
                opts: [
                    { label: '← Volver al inicio',  next: 'start' },
                ]
            },

            err_export_sec: {
                bot: 'Para eliminar el archivo exportado del servidor:\n\n• FTP: navega a wp-content/uploads/ y borra rabbit-bd-export-*.sql o .zip.\n• SSH: rm /ruta/wp-content/uploads/rabbit-bd-export-*.\n\nEs importante hacerlo: el archivo contiene todos los datos de tu BD y es accesible por URL mientras esté en uploads/.',
                opts: [
                    { label: '← Volver al inicio',  next: 'start' },
                ]
            },

            // ── Configuración ────────────────────────────────────────────────
            config: {
                bot: 'Para empezar necesitas:\n\n• URL base de PrestaShop (sin barra final, ej: https://mitienda.com)\n• API Key de PrestaShop con permisos GET en productos e imágenes.\n• Activar el Webservice en PrestaShop: Parámetros avanzados → Webservice → Activar.\n• Directorio de imágenes: ruta absoluta en el servidor donde guardar las imágenes.\n• URL base de imágenes: URL pública de esa carpeta (debe terminar en /).',
                opts: [
                    { label: '¿Cómo activo el Webservice?',     next: 'ws_enable'  },
                    { label: '¿Cómo creo la API Key?',          next: 'apikey'     },
                    { label: '← Volver al inicio',               next: 'start'     },
                ]
            },

            ws_enable: {
                bot: 'Para activar el Webservice de PrestaShop:\n\n1. Entra al Back Office de PrestaShop.\n2. Ve a Parámetros Avanzados → Webservice.\n3. Activa "Habilitar el webservice de PrestaShop".\n4. Guarda.\n\nSin este paso la API no responderá y el Paso 1 fallará siempre.',
                opts: [
                    { label: '¿Cómo creo la API Key?',    next: 'apikey'  },
                    { label: 'Seguir con el Paso 1',       next: 'detail_p1' },
                    { label: '← Volver al inicio',          next: 'start'   },
                ]
            },

            apikey: {
                bot: 'Para crear la API Key en PrestaShop:\n\n1. Webservice → Añadir nueva clave.\n2. Genera la clave con el botón "Generar".\n3. Marca permisos GET en: products, images, combinations.\n4. Guarda y copia la clave en la configuración de Rabbit BD.',
                opts: [
                    { label: 'Ya tengo la clave, ¿qué más?',   next: 'config'    },
                    { label: 'Tengo error al descargar',         next: 'err_paso1' },
                    { label: '← Volver al inicio',               next: 'start'    },
                ]
            },

            // ── Detalle Paso 1 ───────────────────────────────────────────────
            detail_p1: {
                bot: 'El Paso 1 descarga todas las imágenes de tus productos:\n\n• Se conecta a /api/products para obtener los IDs.\n• Por cada producto obtiene sus imágenes asociadas.\n• Las guarda en <directorio>/<SKU>/<SKU>_01.jpg, _02.jpg...\n• Se procesa en lotes de 50 para evitar timeouts.\n• Puedes pausar y reanudar si es necesario.\n\nImportante: los productos deben tener SKU (campo "Referencia") en PrestaShop.',
                opts: [
                    { label: 'Error: no se encuentran productos',  next: 'err_no_products'  },
                    { label: 'Error: imágenes no se descargan',    next: 'err_no_images'    },
                    { label: 'Error de URL inválida',              next: 'err_url'          },
                    { label: '← Volver al inicio',                  next: 'start'           },
                ]
            },

            // ── Detalle Paso 2 ───────────────────────────────────────────────
            detail_p2: {
                bot: 'El Paso 2 genera el CSV para importar en WooCommerce:\n\n• Sube tu CSV MASTER con columnas: name, sku (o referencia), price, categories.\n• Opcionalmente un CSV de PrestaShop para mapear nombres ↔ SKU.\n• El plugin detecta el delimitador automáticamente (; o ,).\n• Genera rabbit-bd-import-URLS.csv listo para importar.\n• Las categorías múltiples van separadas por coma.',
                opts: [
                    { label: 'El CSV MASTER no se reconoce',       next: 'err_csv_master'  },
                    { label: 'Las imágenes no salen en el CSV',    next: 'err_csv_images'  },
                    { label: '← Volver al inicio',                  next: 'start'          },
                ]
            },

            // ── Errores Paso 1 ───────────────────────────────────────────────
            err_paso1: {
                bot: '¿Qué tipo de error tienes en el Paso 1?',
                opts: [
                    { label: 'No se encuentran productos',         next: 'err_no_products' },
                    { label: 'Las imágenes no se descargan',       next: 'err_no_images'   },
                    { label: 'URL de PrestaShop no válida',        next: 'err_url'         },
                    { label: 'Error de permisos / 403',            next: 'err_403'         },
                    { label: 'Timeout / el proceso se cuelga',     next: 'err_timeout'     },
                    { label: '← Volver al inicio',                  next: 'start'          },
                ]
            },

            err_no_products: {
                bot: 'Si la API no devuelve productos, comprueba:\n\n1. El Webservice está activado en PrestaShop.\n2. La URL es correcta y sin barra final (ej: http://mitienda.com).\n3. La API Key tiene permiso GET sobre "products".\n4. Prueba en el navegador: http://mitienda.com/api/products?output_format=JSON&ws_key=TUKEY\n\nSi devuelve JSON con productos, la configuración es correcta.',
                opts: [
                    { label: 'Cómo activo el Webservice',   next: 'ws_enable'  },
                    { label: 'Cómo creo la API Key',         next: 'apikey'     },
                    { label: 'Sigo con error 403',           next: 'err_403'    },
                    { label: '← Volver al inicio',            next: 'start'     },
                ]
            },

            err_no_images: {
                bot: 'Si los productos se encuentran pero las imágenes no se descargan:\n\n1. Comprueba que los productos tienen SKU (campo "Referencia") — sin SKU se omiten.\n2. Verifica que la API Key tiene permiso GET sobre "images".\n3. Mira el Log para ver el error específico por producto.\n4. Prueba manualmente: http://mitienda.com/api/images/products/1/1?ws_key=TUKEY\n\nSi devuelve imagen, el problema es de permisos en el directorio de destino.',
                opts: [
                    { label: 'Problema de permisos de carpeta',  next: 'err_perms'   },
                    { label: 'Los productos no tienen SKU',       next: 'err_no_sku'  },
                    { label: 'Ver el log de errores',             next: 'err_log'     },
                    { label: '← Volver al inicio',                 next: 'start'      },
                ]
            },

            err_no_sku: {
                bot: 'Los productos sin SKU (campo "Referencia" vacío en PrestaShop) son ignorados por el plugin, ya que el SKU es el nombre de la carpeta y del archivo de imagen.\n\nSolución:\n1. En PrestaShop, edita cada producto y rellena el campo "Referencia".\n2. O bien, exporta el catálogo y completa los SKUs en el CSV MASTER antes del Paso 2.',
                opts: [
                    { label: '¿Cómo uso el CSV MASTER?',   next: 'detail_p2'  },
                    { label: '← Volver al inicio',           next: 'start'     },
                ]
            },

            err_url: {
                bot: 'La URL de PrestaShop debe:\n\n• Empezar por http:// o https://\n• No tener barra final (no: http://tienda.com/)\n• Ser la raíz de la tienda, no de la API\n• Ser accesible desde el servidor de WordPress\n\nEjemplo correcto: http://lucentina.palikecomunicacion.com',
                opts: [
                    { label: '¿Y si el servidor no puede acceder?',  next: 'err_network' },
                    { label: '← Volver al inicio',                    next: 'start'      },
                ]
            },

            err_403: {
                bot: 'Un error 403 significa que la API Key no tiene permisos suficientes.\n\nSolución:\n1. PrestaShop → Webservice → edita tu clave.\n2. Activa permisos GET en: products, images, combinations, categories.\n3. Guarda y vuelve a intentarlo.\n\nTambién puede pasar si el servidor tiene restricciones de IP o .htaccess bloqueando /api/.',
                opts: [
                    { label: 'Cómo crear la API Key correctamente',  next: 'apikey'  },
                    { label: '← Volver al inicio',                    next: 'start'  },
                ]
            },

            err_timeout: {
                bot: 'Si el proceso se cuelga o da timeout:\n\n• El plugin procesa en lotes de 50 productos — si el servidor es lento puede tardar.\n• Usa el botón "Pausar" y luego "Reanudar" para continuar desde donde se quedó.\n• Aumenta el tiempo de ejecución máximo en PHP (max_execution_time) si tienes acceso al servidor.\n• Comprueba que la URL de PrestaShop es accesible desde el servidor donde corre WordPress.',
                opts: [
                    { label: 'El servidor no puede acceder a PrestaShop',  next: 'err_network' },
                    { label: '← Volver al inicio',                          next: 'start'      },
                ]
            },

            err_network: {
                bot: 'Si WordPress no puede conectar con PrestaShop:\n\n• Ambos están en el mismo servidor: usa la IP local o localhost en lugar del dominio.\n• PrestaShop está en otro servidor: verifica que no hay firewall bloqueando las peticiones salientes del servidor de WordPress.\n• Si PrestaShop usa HTTP (no HTTPS) y WordPress está en HTTPS, puede haber conflictos — prueba con la URL HTTP directa.',
                opts: [
                    { label: '← Volver al inicio',  next: 'start' },
                ]
            },

            err_perms: {
                bot: 'Si no se pueden escribir las imágenes en el directorio:\n\n• El directorio de destino debe ser escribible por el usuario del servidor web (www-data, apache, nginx...).\n• Comprueba los permisos: chmod 755 o 775 en la carpeta.\n• La ruta debe ser absoluta en el servidor, no una URL.\n• Ejemplo correcto: /home/usuario/apps/wp/wp-content/uploads/rabbit-bd',
                opts: [
                    { label: '← Volver al inicio',  next: 'start' },
                ]
            },

            // ── Errores Paso 2 ───────────────────────────────────────────────
            err_paso2: {
                bot: '¿Qué problema tienes en el Paso 2 (Generar CSV)?',
                opts: [
                    { label: 'El CSV MASTER no se reconoce',        next: 'err_csv_master'  },
                    { label: 'Las imágenes no aparecen en el CSV',  next: 'err_csv_images'  },
                    { label: 'Error al subir el archivo',           next: 'err_upload'      },
                    { label: '← Volver al inicio',                   next: 'start'          },
                ]
            },

            err_csv_master: {
                bot: 'El CSV MASTER debe tener estas columnas (con cabecera):\n\n• name — nombre del producto\n• sku o referencia — el SKU\n• price — precio\n• categories — categorías separadas por coma\n\nEl plugin detecta automáticamente si el delimitador es ; o ,\nFormatos aceptados: .csv y .txt\n\nSi el archivo tiene otra estructura, renombra las columnas antes de subirlo.',
                opts: [
                    { label: '← Volver al inicio',  next: 'start' },
                ]
            },

            err_csv_images: {
                bot: 'Si las imágenes no aparecen en el CSV generado:\n\n1. Comprueba que el Paso 1 se completó y que existen archivos en el directorio de imágenes.\n2. Los nombres de carpeta deben coincidir exactamente con el SKU del producto.\n3. Verifica que la URL base de imágenes termina en /\n4. La estructura esperada es: URL_BASE/SKU/SKU_01.jpg',
                opts: [
                    { label: '← Volver al inicio',  next: 'start' },
                ]
            },

            err_upload: {
                bot: 'Si no puedes subir el archivo:\n\n• El límite de subida de PHP puede ser muy bajo. Edita php.ini:\n  upload_max_filesize = 32M\n  post_max_size = 32M\n• También puedes editarlo en el .htaccess de WordPress:\n  php_value upload_max_filesize 32M\n  php_value post_max_size 32M',
                opts: [
                    { label: '← Volver al inicio',  next: 'start' },
                ]
            },

            // ── Errores Paso 3/4 ─────────────────────────────────────────────
            err_paso34: {
                bot: '¿Qué problema tienes en el Paso 3 o 4?',
                opts: [
                    { label: 'La URL de imagen da 404',           next: 'err_img_404'    },
                    { label: 'El importador de WooCommerce falla', next: 'err_woo_import' },
                    { label: 'Las imágenes no se asignan',        next: 'err_img_assign' },
                    { label: '← Volver al inicio',                 next: 'start'         },
                ]
            },

            err_img_404: {
                bot: 'Si las imágenes dan 404:\n\n1. Verifica que subiste los archivos por FTP a la carpeta correcta.\n2. La estructura debe ser: wp-content/uploads/imports/SKU/SKU_01.jpg\n3. Revisa el .htaccess del subdominio — puede estar redirigiendo todo a WordPress.\n4. Comprueba que el servidor sirve archivos estáticos desde esa ruta.',
                opts: [
                    { label: '← Volver al inicio',  next: 'start' },
                ]
            },

            err_woo_import: {
                bot: 'Para importar correctamente en WooCommerce:\n\n1. WP Admin → Productos → Importar.\n2. Sube rabbit-bd-import-URLS.csv.\n3. Mapea los campos: SKU→SKU, Name→Nombre, Regular price→Precio, Categories→Categorías, Images→Imágenes.\n4. No importes "Gallery images" (evita duplicados).\n5. Marca "Actualizar existentes" si ya hiciste un intento previo.',
                opts: [
                    { label: 'Las imágenes no se asignan',  next: 'err_img_assign' },
                    { label: '← Volver al inicio',           next: 'start'         },
                ]
            },

            err_img_assign: {
                bot: 'Si las imágenes no se asignan durante la importación:\n\n• Las URLs deben ser públicamente accesibles antes de importar — valídalas en el Paso 3.\n• WordPress descarga las imágenes remotas durante la importación, necesita acceso HTTP a ellas.\n• Si usas HTTP (no HTTPS), asegúrate de que WordPress no fuerza HTTPS en las peticiones salientes.',
                opts: [
                    { label: '← Volver al inicio',  next: 'start' },
                ]
            },

            // ── Log ─────────────────────────────────────────────────────────
            err_log: {
                bot: 'Si el Log no muestra nada o no carga:\n\n• Haz clic en "Cargar log" — no se carga automáticamente salvo al abrir la pestaña.\n• Si está vacío es que aún no se ha ejecutado ningún proceso.\n• Si hay errores, el log mostrará el SKU, el estado y el mensaje de error concreto.\n• Puedes limpiar el log antes de una nueva ejecución para ver solo los resultados recientes.',
                opts: [
                    { label: '← Volver al inicio',  next: 'start' },
                ]
            },
        };

        // ── UI ───────────────────────────────────────────────────────────────
        var bubble = document.getElementById('rabbit-assistant');
        var panel  = document.getElementById('rabbit-chat-panel');
        var msgs   = document.getElementById('rabbit-chat-messages');
        var close  = document.getElementById('rabbit-chat-close');

        bubble.addEventListener('click', function() {
            var visible = panel.style.display !== 'none';
            panel.style.display = visible ? 'none' : 'flex';
            if (!visible && msgs.children.length === 0) goTo('start');
        });

        close.addEventListener('click', function() { panel.style.display = 'none'; });

        function addMsg(text, who) {
            var d = document.createElement('div');
            d.className = 'rabbit-msg rabbit-msg-' + who;
            d.textContent = text;
            msgs.appendChild(d);
            msgs.scrollTop = msgs.scrollHeight;
            return d;
        }

        function addOptions(opts) {
            var wrap = document.createElement('div');
            wrap.className = 'rabbit-opts';
            opts.forEach(function(o) {
                var b = document.createElement('button');
                b.className = 'rabbit-opt-btn';
                b.textContent = o.label;
                b.addEventListener('click', function() {
                    // Deshabilitar todos los botones del grupo al elegir
                    wrap.querySelectorAll('.rabbit-opt-btn').forEach(function(btn) {
                        btn.disabled = true;
                        btn.classList.add('rabbit-opt-used');
                    });
                    b.classList.add('rabbit-opt-chosen');
                    addMsg(o.label, 'user');
                    setTimeout(function() { goTo(o.next); }, 180);
                });
                wrap.appendChild(b);
            });
            msgs.appendChild(wrap);
            msgs.scrollTop = msgs.scrollHeight;
        }

        function goTo(nodeId) {
            var node = TREE[nodeId];
            if (!node) return;
            setTimeout(function() {
                addMsg(node.bot, 'bot');
                if (node.opts && node.opts.length) {
                    setTimeout(function() { addOptions(node.opts); }, 120);
                }
            }, 80);
        }
    })();
    </script>

    </div>
    <?php
}

/**
 * Genera el icono del menu lateral usando el SVG del logo de Rabbit BD.
 *
 * WordPress no procesa CSS sobre iconos data URI en el menu lateral,
 * por lo que hay que forzar fill="white" directamente en el SVG.
 * Sustituimos cualquier fill existente por blanco para que el icono
 * sea visible sobre el fondo oscuro del menu de WordPress.
 */
function rabbit_bd_menu_icon(): string {
    $svg_path = RABBIT_BD_PATH . 'RabbitBDLogo.svg';

    if (file_exists($svg_path)) {
        $svg = file_get_contents($svg_path);

        // Reemplazar todos los fills de color por blanco
        $svg_menu = preg_replace('/\sfill="[^"]*"/', ' fill="white"', $svg);

        // Asegurar que el SVG raiz tiene fill="white" si no tenia ninguno
        if (!str_contains($svg_menu, 'fill="white"')) {
            $svg_menu = str_replace('<svg ', '<svg fill="white" ', $svg_menu);
        }

        return 'data:image/svg+xml;base64,' . base64_encode($svg_menu);
    }

    // Fallback minimo si no se encuentra el archivo
    $fallback = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><rect width="20" height="20" rx="3" fill="white"/></svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($fallback);
}