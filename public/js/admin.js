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
        // Auto-cargar log al abrir la pestaña
        if (tab === 'log') loadLog();
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

    // Paso 0: Exportar MASTER CSV desde PrestaShop
    $('#btn-generate-master').on('click', function () {
        const $btn    = $(this);
        const $result = $('#master-result');

        $btn.prop('disabled', true);
        showResult($result, 'info', spinner() + ' Conectando con PrestaShop y extrayendo productos... Esto puede tardar unos segundos.');

        $.post(ajax_url, {
            action         : 'rabbit_bd_generate_master',
            nonce          : nonce,
            presta_url     : $('[name="rabbit_bd_presta_url"]').val() || rabbitBD.presta_url,
            presta_api_key : $('[name="rabbit_bd_presta_key"]').val() || rabbitBD.presta_key,
        })
        .done(function (res) {
            if (res.success) {
                showResult($result, 'success',
                    'MASTER generado con <strong>' + res.data.productos + ' productos</strong>.<br>' +
                    '<a href="' + res.data.csv_url + '" download class="button button-primary button-small" style="margin-top:8px">Descargar MASTER CSV</a>' +
                    '<p style="margin-top:10px;color:#555">Ahora ve al <strong>Paso 2</strong> y sube este archivo como CSV MASTER.</p>'
                );
            } else {
                showResult($result, 'error', res.data.message || 'Error desconocido.');
            }
        })
        .fail(function () {
            showResult($result, 'error', 'Error de red al generar el MASTER.');
        })
        .always(function () { $btn.prop('disabled', false); });
    });

    // Paso 1: Descarga por lotes con barra de progreso y pausa/reanudacion
    function runDownloadBatch() {
        if (!downloadState.running || downloadState.paused) return;

        $.post(ajax_url, {
            action     : 'rabbit_bd_download_images',
            nonce      : nonce,
            presta_url     : $('[name="rabbit_bd_presta_url"]').val() || rabbitBD.presta_url,
            presta_api_key : $('[name="rabbit_bd_presta_key"]').val() || rabbitBD.presta_key,
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
                var html = 'Descarga completada.<br>' +
                    '<strong>Productos OK:</strong> ' + downloadState.ok + '<br>' +
                    '<strong>Errores:</strong> ' + downloadState.errors + '<br>' +
                    '<strong>Directorio:</strong> <code>' + d.directorio + '</code><br>' +
                    '<strong>URL pública:</strong> <code>' + d.public_url + '</code>';
                if (d.error_details && d.error_details.length) {
                    html += '<br><br><strong>Detalle de errores:</strong><ul style="margin:6px 0 0;padding-left:18px">';
                    d.error_details.forEach(function(e) { html += '<li>' + e + '</li>'; });
                    html += '</ul>';
                }
                showResult($('#download-result'), downloadState.errors > 0 && downloadState.ok === 0 ? 'error' : 'success', html);
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

    // Log: cargar (función reutilizable para carga automática y manual)
    function loadLog() {
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
    }

    $('#btn-load-log').on('click', loadLog);

    // Log: limpiar
    $('#btn-clear-log').on('click', function () {
        if (!confirm('Limpiar todo el log?')) return;
        $.post(ajax_url, { action: 'rabbit_bd_clear_log', nonce })
        .done(function () { $('#log-table-wrapper').html('<p>Log limpiado.</p>'); });
    });

    // Exportar BD: cargar lista de tablas
    $('#btn-load-tables').on('click', function () {
        const $btn = $(this);
        $btn.prop('disabled', true).text('Cargando...');

        $.post(ajax_url, { action: 'rabbit_bd_list_tables', nonce })
        .done(function (res) {
            $btn.prop('disabled', false).text('Recargar tablas');
            if (!res.success) {
                alert('Error al cargar tablas: ' + (res.data.message || 'desconocido'));
                return;
            }
            const { tables, sizes } = res.data;
            $('#db-tables-count').text(tables.length + ' tablas encontradas');

            let html = '';
            tables.forEach(function (t) {
                const info = sizes[t] || {};
                const label = t + ' (' + (info.rows || 0) + ' filas, ' + (info.size_mb || 0) + ' MB)';
                html += '<label style="display:flex;align-items:center;gap:4px;padding:4px 8px;background:#f6f7f7;border-radius:4px;font-size:12px;cursor:pointer">' +
                    '<input type="checkbox" class="db-table-check" value="' + escHtml(t) + '" checked> ' + escHtml(label) + '</label>';
            });
            $('#db-tables-checkboxes').html(html);
            $('#db-tables-list').show();
            $('#btn-export-sql, #btn-export-csv').prop('disabled', false);
        })
        .fail(function () {
            $btn.prop('disabled', false).text('Cargar lista de tablas');
            alert('Error de red al cargar tablas.');
        });
    });

    // Seleccionar/deseleccionar todas
    $('#db-select-all').on('change', function () {
        $('.db-table-check').prop('checked', $(this).is(':checked'));
    });

    function getSelectedTables() {
        const checked = $('.db-table-check:checked').map(function () { return $(this).val(); }).get();
        return checked.length === $('.db-table-check').length ? 'all' : checked.join(',');
    }

    function doExport(format) {
        const $result = $('#export-db-result');
        const tables  = getSelectedTables();

        if ($('.db-table-check').length && tables === '' && format !== 'all') {
            showResult($result, 'error', 'Selecciona al menos una tabla para exportar.');
            return;
        }

        showResult($result, 'info', spinner() + ' Generando exportación ' + format.toUpperCase() + '... puede tardar unos segundos.');

        $.post(ajax_url, { action: 'rabbit_bd_export_db', nonce, format: format, tables: tables || 'all' })
        .done(function (res) {
            if (!res.success) {
                showResult($result, 'error', res.data.message || 'Error al exportar.');
                return;
            }
            const d = res.data;
            const tablas = d.tablas + ' tabla(s)';
            showResult($result, 'success',
                '✅ Exportación completada · ' + tablas +
                ' · <a href="' + escHtml(d.file_url) + '" download="' + escHtml(d.filename) + '" class="button button-primary" style="margin-left:8px">⬇ Descargar ' + escHtml(d.filename) + '</a>' +
                '<p style="margin-top:8px;color:#b32d2e"><strong>⚠ Recuerda borrar este archivo del servidor una vez descargado.</strong></p>'
            );
        })
        .fail(function () {
            showResult($result, 'error', 'Error de red al exportar la base de datos.');
        });
    }

    $('#btn-export-sql').on('click', function () { doExport('sql'); });
    $('#btn-export-csv').on('click', function () { doExport('csv'); });

    // Escapar HTML para evitar XSS al insertar datos del servidor en el DOM
    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

})(jQuery);