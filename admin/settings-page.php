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
        'ajax_url'   => admin_url('admin-ajax.php'),
        'nonce'      => wp_create_nonce('rabbit_bd_nonce'),
        'batch_size' => RABBIT_BD_BATCH_SIZE,
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
            <button class="rabbit-tab" data-tab="step1">1 · Descargar imagenes</button>
            <button class="rabbit-tab" data-tab="step2">2 · Generar CSV</button>
            <button class="rabbit-tab" data-tab="step3">3 · Validar URL</button>
            <button class="rabbit-tab" data-tab="step4">4 · Importar</button>
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
                            <p class="description">Ruta absoluta del servidor. Las imagenes se organizan en subcarpetas por SKU: <code>/SKU/SKU_01.jpg</code></p>
                        </td>
                    </tr>
                    <tr>
                        <th>URL base de imagenes (FTP)</th>
                        <td>
                            <input type="url" name="rabbit_bd_base_url"
                                   value="<?php echo esc_attr($base_url); ?>"
                                   class="large-text"
                                   placeholder="http://staging.ejemplo.com/wp-content/uploads/imports/">
                            <p class="description">Debe terminar en /. URL publica donde se subieron las imagenes por FTP. Las URLs finales incluiran la subcarpeta del SKU.</p>
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
                        <th>URL base de imagenes</th>
                        <td>
                            <input type="url" id="gen-base-url" name="base_url"
                                   value="<?php echo esc_attr($base_url); ?>"
                                   class="large-text">
                            <p class="description">Las URLs finales tendran formato: <code>base_url/SKU/SKU_01.jpg</code></p>
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
            <h2>Paso 3 · Validar accesibilidad HTTP de imagenes</h2>
            <p>Verifica que el servidor sirve correctamente los estaticos subidos por FTP antes de importar en WooCommerce.</p>

            <div style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap">
                <input type="url" id="test-image-url" class="large-text"
                       placeholder="<?php echo esc_attr(rtrim($base_url, '/') . '/EJEMPLO/EJEMPLO_01.jpg'); ?>"
                       style="max-width:520px">
                <button id="btn-test-url" class="button button-secondary">Testear URL</button>
            </div>
            <div id="url-test-result" class="rabbit-result" style="display:none;margin-top:10px"></div>

            <div class="rabbit-info-box" style="margin-top:20px">
                <strong>Que buscamos:</strong> que la URL devuelva HTTP 200. Si devuelve 404, comprueba:
                <ul style="list-style:disc;padding-left:20px">
                    <li>Que WordPress este en el directorio correcto (<code>wp-admin</code>, <code>wp-content</code>).</li>
                    <li>La configuracion de <code>.htaccess</code> / rewrite del subdominio.</li>
                    <li>Que las imagenes esten en <code>/wp-content/uploads/imports/SKU/</code>.</li>
                </ul>
            </div>
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
    <div id="rabbit-assistant" class="rabbit-assistant-bubble" title="Pregunta a Rabbit">
        <div id="rabbit-assistant-icon">
            <img src="<?php echo esc_url(RABBIT_BD_URL . 'RabbitBDLogo.svg'); ?>" alt="Rabbit" width="38" height="38">
        </div>
    </div>

    <div id="rabbit-chat-panel" class="rabbit-chat-panel" style="display:none">
        <div class="rabbit-chat-header">
            <span>🐇 Rabbit — Asistente</span>
            <button id="rabbit-chat-close" class="rabbit-chat-close">✕</button>
        </div>
        <div id="rabbit-chat-messages" class="rabbit-chat-messages">
            <div class="rabbit-msg rabbit-msg-bot">¡Hola! Soy Rabbit, tu asistente de migración. Puedo ayudarte con la configuración, errores, o cualquier duda sobre el proceso PrestaShop → WooCommerce. ¿En qué te ayudo?</div>
        </div>
        <div class="rabbit-chat-input-row">
            <input type="text" id="rabbit-chat-input" class="rabbit-chat-input" placeholder="Escribe tu pregunta..." autocomplete="off">
            <button id="rabbit-chat-send" class="rabbit-chat-send">➤</button>
        </div>
    </div>

    <script>
    (function() {
        var cfg = {
            ajaxUrl : '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
            nonce   : '<?php echo esc_js(wp_create_nonce('rabbit_bd_nonce')); ?>',
            siteUrl : '<?php echo esc_js(get_site_url()); ?>',
            prestaUrl : '<?php echo esc_js(get_option('rabbit_bd_presta_url','')); ?>',
        };

        var bubble = document.getElementById('rabbit-assistant');
        var panel  = document.getElementById('rabbit-chat-panel');
        var msgs   = document.getElementById('rabbit-chat-messages');
        var input  = document.getElementById('rabbit-chat-input');
        var send   = document.getElementById('rabbit-chat-send');
        var close  = document.getElementById('rabbit-chat-close');
        var busy   = false;

        bubble.addEventListener('click', function() {
            panel.style.display = panel.style.display === 'none' ? 'flex' : 'none';
            if (panel.style.display === 'flex') input.focus();
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

        function askRabbit() {
            var q = input.value.trim();
            if (!q || busy) return;
            busy = true;
            addMsg(q, 'user');
            input.value = '';
            var thinking = addMsg('...', 'bot');

            var systemPrompt = 'Eres Rabbit, un asistente experto en el plugin WordPress "Rabbit BD" que migra tiendas de PrestaShop a WooCommerce. ' +
                'Responde siempre en español, de forma concisa y práctica. ' +
                'El sitio WordPress actual es: ' + cfg.siteUrl + '. ' +
                'La URL de PrestaShop configurada es: ' + (cfg.prestaUrl || '(no configurada)') + '. ' +
                'El plugin tiene 4 pasos: 1) Descargar imágenes via API de PrestaShop, 2) Generar CSV para WooCommerce, 3) Validar URLs de imágenes, 4) Importar en WooCommerce. ' +
                'Si el usuario tiene errores de descarga de imágenes, pregúntale si los productos tienen SKU (campo "Referencia") y si el Webservice de PrestaShop está activado. ' +
                'Sé amable y usa emojis ocasionalmente.';

            fetch('https://api.anthropic.com/v1/messages', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    model: 'claude-sonnet-4-20250514',
                    max_tokens: 1000,
                    system: systemPrompt,
                    messages: [{ role: 'user', content: q }]
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var text = (data.content && data.content[0] && data.content[0].text)
                    ? data.content[0].text
                    : 'Lo siento, no pude obtener respuesta. Inténtalo de nuevo.';
                thinking.textContent = text;
            })
            .catch(function() {
                thinking.textContent = 'Error de conexión. Revisa tu acceso a internet e inténtalo de nuevo.';
            })
            .finally(function() { busy = false; });
        }

        send.addEventListener('click', askRabbit);
        input.addEventListener('keydown', function(e) { if (e.key === 'Enter') askRabbit(); });
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
