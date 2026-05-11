/* Rabbit Migrator — Admin JS */
(function ($) {
    'use strict';

    const { ajax_url, nonce } = rabbitBD;

    // ── Tab switching ─────────────────────────────────────────────────────
    $(document).on('click', '.rabbit-tab', function () {
        const tab = $(this).data('tab');
        $('.rabbit-tab').removeClass('active');
        $(this).addClass('active');
        $('.rabbit-panel').removeClass('active');
        $('#tab-' + tab).addClass('active');
    });

    // ── Helper: mostrar resultado ─────────────────────────────────────────
    function showResult($el, type, html) {
        $el.removeClass('success error info')
           .addClass(type)
           .html(html)
           .show();
    }

    function spinner() {
        return '<span class="rabbit-spinner"></span>';
    }

    // ── Paso 1: Descargar imágenes de PrestaShop ──────────────────────────
    $('#btn-download-images').on('click', function () {
        const $btn    = $(this);
        const $result = $('#download-result');

        $btn.prop('disabled', true);
        showResult($result, 'info', spinner() + 'Descargando imágenes… esto puede tardar varios minutos.');

        $.post(ajax_url, {
            action : 'rabbit_bd_download_images',
            nonce  : nonce,
            presta_url     : $('[name="rabbit_migrator_presta_url"]').val() || '',
            presta_api_key : $('[name="rabbit_migrator_presta_key"]').val() || '',
        })
        .done(function (res) {
            if (res.success) {
                showResult($result, 'success',
                    '✅ Descarga completada.<br>' +
                    '<strong>Productos OK:</strong> ' + res.data.productos_ok + '<br>' +
                    '<strong>Errores:</strong> ' + res.data.productos_error + '<br>' +
                    '<strong>Directorio:</strong> <code>' + res.data.directorio + '</code>'
                );
            } else {
                showResult($result, 'error', '❌ Error: ' + (res.data.message || JSON.stringify(res.data)));
            }
        })
        .fail(function () {
            showResult($result, 'error', '❌ Error de red o timeout del servidor.');
        })
        .always(function () { $btn.prop('disabled', false); });
    });

    // ── Paso 2: Generar CSV ───────────────────────────────────────────────
    $('#form-generate-csv').on('submit', function (e) {
        e.preventDefault();
        const $result = $('#csv-result');
        const fd      = new FormData(this);
        fd.append('action', 'rabbit_bd_generate_csv');
        fd.append('nonce',  nonce);

        showResult($result, 'info', spinner() + 'Generando CSV de importación…');

        $.ajax({
            url         : ajax_url,
            type        : 'POST',
            data        : fd,
            processData : false,
            contentType : false,
        })
        .done(function (res) {
            if (res.success) {
                showResult($result, 'success',
                    '✅ CSV generado con <strong>' + res.data.productos + ' productos</strong>.<br>' +
                    'Archivo: <code>' + res.data.csv_filename + '</code><br>' +
                    '<a href="' + res.data.csv_url + '" download class="button button-small" style="margin-top:8px">⬇️ Descargar CSV</a>'
                );
            } else {
                showResult($result, 'error', '❌ ' + (res.data.message || JSON.stringify(res.data)));
            }
        })
        .fail(function () {
            showResult($result, 'error', '❌ Error de red al generar el CSV.');
        });
    });

    // ── Paso 3: Testear URL de imagen ─────────────────────────────────────
    $('#btn-test-url').on('click', function () {
        const url     = $('#test-image-url').val().trim();
        const $result = $('#url-test-result');

        if (!url) {
            showResult($result, 'error', '⚠️ Introduce una URL antes de testear.');
            return;
        }

        showResult($result, 'info', spinner() + 'Comprobando accesibilidad HTTP…');

        $.post(ajax_url, { action: 'rabbit_bd_test_image_url', nonce, url })
        .done(function (res) {
            showResult($result, res.success ? 'success' : 'error',
                res.data.message || (res.success ? '✅ OK' : '❌ Error'));
        })
        .fail(function () {
            showResult($result, 'error', '❌ Error de red al testear la URL.');
        });
    });

    // ── Log: cargar ───────────────────────────────────────────────────────
    $('#btn-load-log').on('click', function () {
        $.post(ajax_url, { action: 'rabbit_bd_get_log', nonce })
        .done(function (res) {
            if (!res.success || !res.data.log.length) {
                $('#log-table-wrapper').html('<p>El log está vacío.</p>');
                return;
            }
            let html = '<table class="widefat striped"><thead><tr>' +
                '<th>SKU</th><th>Producto</th><th>Estado</th><th>Mensaje</th><th>Fecha</th>' +
                '</tr></thead><tbody>';
            res.data.log.forEach(function (r) {
                const status = r.status === 'error' ? '❌' : r.status === 'downloaded' ? '✅' : '⏳';
                html += `<tr>
                    <td><code>${r.sku}</code></td>
                    <td>${r.product_name}</td>
                    <td>${status} ${r.status}</td>
                    <td>${r.message}</td>
                    <td>${r.created_at}</td>
                </tr>`;
            });
            html += '</tbody></table>';
            $('#log-table-wrapper').html(html);
        });
    });

    // ── Log: limpiar ──────────────────────────────────────────────────────
    $('#btn-clear-log').on('click', function () {
        if (!confirm('¿Limpiar todo el log?')) return;
        $.post(ajax_url, { action: 'rabbit_bd_clear_log', nonce })
        .done(function () { $('#log-table-wrapper').html('<p>Log limpiado.</p>'); });
    });

})(jQuery);
