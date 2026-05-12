/* Rabbit BD — Admin JS */
(function ($) {
    'use strict';

    const { ajax_url, nonce, batch_size } = rabbitBD;

    // Estado interno del proceso de descarga por lotes
    let downloadState = {
        running  : false,
        paused   : false,
        offset   : 0,
        total    : 0,
        ok       : 0,
        errors   : 0,
    };

    // Tab switching
    $(document).on('click', '.rabbit-tab', function () {
        const tab = $(this).data('tab');
        $('.rabbit-tab').removeClass('active');
        $(this).addClass('active');
        $('.rabbit-panel').removeClass('active');
        $('#tab-' + tab).addClass('active');
    });

    function showResult($el, type, html) {
        $el.removeClass('success error info')
           .addClass(type)
           .html(html)
           .show();
    }

    function spinner() {
        return '<span class="rabbit-spinner"></span>';
    }

    function updateProgressBar(offset, total) {
        const pct = total > 0 ? Math.round((offset / total) * 100) : 0;
        $('#download-progress-bar').css('width', pct + '%');
        $('#download-progress-label').text(offset + ' / ' + total + ' (' + pct + '%)');
        $('#download-progress-wrap').show();
    }

    // Paso 1: Descarga por lotes con barra de progreso y pausa/reanudacion
    function runDownloadBatch() {
        if (!downloadState.running || downloadState.paused) return;

        $.post(ajax_url, {
            action     : 'rabbit_bd_download_images',
            nonce      : nonce,
            presta_url     : $('[name="rabbit_bd_presta_url"]').val() || '',
            presta_api_key : $('[name="rabbit_bd_presta_key"]').val() || '',
            offset     : downloadState.offset,
        })
        .done(function (res) {
            if (!res.success) {
                downloadState.running = false;
                showResult($('#download-result'), 'error', 'Error: ' + (res.data.message || JSON.stringify(res.data)));
                resetDownloadButtons();
                return;
            }

            const d = res.data;
            downloadState.offset = d.offset;
            downloadState.total  = d.total;
            downloadState.ok    += d.lote_ok;
            downloadState.errors += d.lote_error;

            updateProgressBar(d.offset, d.total);

            if (d.finished) {
                downloadState.running = false;
                showResult($('#download-result'), 'success',
                    'Descarga completada.<br>' +
                    '<strong>Productos OK:</strong> ' + downloadState.ok + '<br>' +
                    '<strong>Errores:</strong> ' + downloadState.errors + '<br>' +
                    '<strong>Directorio:</strong> <code>' + d.directorio + '</code>'
                );
                resetDownloadButtons();
            } else {
                // Siguiente lote de forma asincrona (evita stack overflow en listas largas)
                setTimeout(runDownloadBatch, 200);
            }
        })
        .fail(function () {
            downloadState.running = false;
            showResult($('#download-result'), 'error', 'Error de red o timeout. Puedes reanudar desde el ultimo punto.');
            $('#btn-resume-download').show();
            $('#btn-pause-download').hide();
            $('#btn-download-images').prop('disabled', false);
        });
    }

    function resetDownloadButtons() {
        $('#btn-download-images').prop('disabled', false).show();
        $('#btn-pause-download').hide();
        $('#btn-resume-download').hide();
    }

    $('#btn-download-images').on('click', function () {
        downloadState = { running: true, paused: false, offset: 0, total: 0, ok: 0, errors: 0 };
        $(this).prop('disabled', true);
        $('#btn-pause-download').show();
        $('#download-result').hide();
        runDownloadBatch();
    });

    $('#btn-pause-download').on('click', function () {
        downloadState.paused = true;
        $(this).hide();
        $('#btn-resume-download').show();
        showResult($('#download-result'), 'info', 'Descarga pausada en el producto ' + downloadState.offset + ' de ' + downloadState.total + '.');
    });

    $('#btn-resume-download').on('click', function () {
        downloadState.paused  = false;
        downloadState.running = true;
        $(this).hide();
        $('#btn-pause-download').show();
        runDownloadBatch();
    });

    // Paso 2: Generar CSV
    $('#form-generate-csv').on('submit', function (e) {
        e.preventDefault();
        const $result = $('#csv-result');
        const fd      = new FormData(this);
        fd.append('action', 'rabbit_bd_generate_csv');
        fd.append('nonce',  nonce);

        showResult($result, 'info', spinner() + ' Generando CSV de importacion...');

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
                    'CSV generado con <strong>' + res.data.productos + ' productos</strong>.<br>' +
                    'Archivo: <code>' + res.data.csv_filename + '</code><br>' +
                    '<a href="' + res.data.csv_url + '" download class="button button-small" style="margin-top:8px">Descargar CSV</a>'
                );
            } else {
                showResult($result, 'error', res.data.message || JSON.stringify(res.data));
            }
        })
        .fail(function () {
            showResult($result, 'error', 'Error de red al generar el CSV.');
        });
    });

    // Paso 3: Testear URL de imagen
    $('#btn-test-url').on('click', function () {
        const url     = $('#test-image-url').val().trim();
        const $result = $('#url-test-result');

        if (!url) {
            showResult($result, 'error', 'Introduce una URL antes de testear.');
            return;
        }

        showResult($result, 'info', spinner() + ' Comprobando accesibilidad HTTP...');

        $.post(ajax_url, { action: 'rabbit_bd_test_image_url', nonce, url })
        .done(function (res) {
            showResult($result, res.success ? 'success' : 'error',
                res.data.message || (res.success ? 'OK' : 'Error'));
        })
        .fail(function () {
            showResult($result, 'error', 'Error de red al testear la URL.');
        });
    });

    // Log: cargar
    $('#btn-load-log').on('click', function () {
        $.post(ajax_url, { action: 'rabbit_bd_get_log', nonce })
        .done(function (res) {
            if (!res.success || !res.data.log.length) {
                $('#log-table-wrapper').html('<p>El log esta vacio.</p>');
                return;
            }
            let html = '<table class="widefat striped"><thead><tr>' +
                '<th>SKU</th><th>Producto</th><th>Estado</th><th>Mensaje</th><th>Fecha</th>' +
                '</tr></thead><tbody>';

            res.data.log.forEach(function (r) {
                const statusLabel = {
                    error      : 'Error',
                    downloaded : 'Descargado',
                    warning    : 'Aviso',
                }[r.status] || r.status;

                html += '<tr>' +
                    '<td><code>' + escHtml(r.sku) + '</code></td>' +
                    '<td>' + escHtml(r.product_name) + '</td>' +
                    '<td class="log-status-' + escHtml(r.status) + '">' + statusLabel + '</td>' +
                    '<td>' + escHtml(r.message) + '</td>' +
                    '<td>' + escHtml(r.created_at) + '</td>' +
                    '</tr>';
            });

            html += '</tbody></table>';
            $('#log-table-wrapper').html(html);
        });
    });

    // Log: limpiar
    $('#btn-clear-log').on('click', function () {
        if (!confirm('Limpiar todo el log?')) return;
        $.post(ajax_url, { action: 'rabbit_bd_clear_log', nonce })
        .done(function () { $('#log-table-wrapper').html('<p>Log limpiado.</p>'); });
    });

    // Escapar HTML para evitar XSS al insertar datos del servidor en el DOM
    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

})(jQuery);
