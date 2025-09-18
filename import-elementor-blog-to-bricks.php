<?php
/**
 * Plugin Name: Fospibay CSV Import Plugin
 * Description: Imports posts, categories, and images from a CSV in batches, cleans content, downloads images, sets featured image, saves galleries to ACF field, handles duplicates, and avoids re-uploading existing images using filename. Includes a feature to import only featured images for posts without them, using post title as identifier.
 * Version: 2.10.2
 * Author: Grok
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Add admin menu for CSV upload and featured image import
add_action('admin_menu', 'fospibay_add_admin_menu');
function fospibay_add_admin_menu() {
    add_menu_page(
        'Fospibay CSV Import',
        'Fospibay Import',
        'manage_options',
        'fospibay-csv-import',
        'fospibay_csv_import_page',
        'dashicons-upload'
    );
    add_submenu_page(
        'fospibay-csv-import',
        'Importar Imágenes Destacadas',
        'Importar Imágenes Destacadas',
        'manage_options',
        'fospibay-featured-image-import',
        'fospibay_featured_image_import_page'
    );
}

// Admin page for uploading CSV (main import)
function fospibay_csv_import_page() {
    ?>
    <div class="wrap">
        <h1>Fospibay CSV Import</h1>
        <div class="notice notice-info">
            <p><strong>Archivo CSV detectado:</strong> Entradas-Export-2025-September-17-1253.csv</p>
            <p>Este importador es compatible con el formato de exportación de WordPress/Elementor.</p>
            <p><strong>Nota:</strong> Se ha mejorado el manejo de caracteres especiales y codificación UTF-8.</p>
        </div>

        <!-- Progress Bar Section -->
        <div id="import-progress-container" style="display: none; margin: 20px 0; padding: 20px; background: #fff; border: 1px solid #ccc; border-radius: 5px;">
            <h3>Progreso de Importación</h3>
            <div style="background: #f0f0f0; height: 30px; border-radius: 15px; overflow: hidden; margin: 10px 0;">
                <div id="progress-bar" style="background: #0073aa; height: 100%; width: 0%; transition: width 0.3s; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold;">
                    <span id="progress-text">0%</span>
                </div>
            </div>
            <div id="import-stats" style="margin-top: 15px;">
                <p><strong>Estado:</strong> <span id="import-status">Iniciando...</span></p>
                <p><strong>Filas procesadas:</strong> <span id="rows-processed">0</span> / <span id="total-rows">0</span></p>
                <p><strong>Entradas creadas:</strong> <span id="posts-created">0</span></p>
                <p><strong>Entradas actualizadas:</strong> <span id="posts-updated">0</span></p>
                <p><strong>Omitidas:</strong> <span id="posts-skipped">0</span></p>
                <p><strong>Imágenes descargadas:</strong> <span id="images-downloaded">0</span></p>
                <p><strong>Último título procesado:</strong> <span id="last-title" style="font-style: italic;">-</span></p>
            </div>
            <div id="debug-output" style="margin-top: 15px; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 3px; max-height: 300px; overflow-y: auto; font-family: monospace; font-size: 12px; display: none;">
                <strong>Debug Output:</strong>
                <div id="debug-messages"></div>
            </div>
            <button type="button" class="button" onclick="location.reload();">Recargar Página</button>
        </div>
        <?php
        $state = get_option('fospibay_import_state', false);
        if ($state && file_exists($state['file'])) {
            echo '<div class="notice notice-info"><p>Importación previa interrumpida en fila ' . esc_html($state['row_index']) . '. <a href="' . esc_url(add_query_arg('resume_import', '1')) . '">Reanudar</a> o <a href="' . esc_url(add_query_arg('cancel_import', '1')) . '">Cancelar</a></p></div>';
        }
        if (isset($_GET['cancel_import']) && wp_verify_nonce($_GET['_wpnonce'], 'fospibay_cancel_import')) {
            unlink($state['file']);
            delete_option('fospibay_import_state');
            echo '<div class="updated"><p>Importación cancelada.</p></div>';
        }

        // Auto-import if file exists
        $csv_file_path = dirname(__FILE__) . '/Entradas-Export-2025-September-17-1253.csv';
        if (file_exists($csv_file_path) && !$state) {
            echo '<div class="notice notice-warning"><p>Se encontró el archivo CSV en el directorio del plugin. <form method="post" style="display:inline;">
                <input type="hidden" name="auto_import" value="1">
                <input type="hidden" name="csv_path" value="' . esc_attr($csv_file_path) . '">
                ' . wp_nonce_field('fospibay_csv_import', 'fospibay_csv_nonce', true, false) . '
                <input type="submit" class="button button-primary" value="Importar archivo encontrado">
            </form></p></div>';
        }
        ?>
        <form method="post" enctype="multipart/form-data">
            <p>
                <label for="csv_file">Selecciona el archivo CSV:</label><br>
                <input type="file" name="csv_file" id="csv_file" accept=".csv">
            </p>
            <p>
                <label for="skip_existing">
                    <input type="checkbox" name="skip_existing" id="skip_existing" value="1">
                    Ignorar entradas existentes (no actualizar ni importar duplicados)
                </label>
            </p>
            <p>
                <label for="batch_size">Tamaño del lote (filas por lote):</label><br>
                <input type="number" name="batch_size" id="batch_size" value="5" min="1" max="1000">
                <small>Valores más bajos permiten mejor seguimiento del progreso</small>
            </p>
            <p>
                <label for="debug_mode">
                    <input type="checkbox" name="debug_mode" id="debug_mode" value="1" checked>
                    Activar modo debug (muestra información detallada)
                </label>
            </p>
            <p>
                <label for="delimiter">Delimitador CSV:</label><br>
                <select name="delimiter" id="delimiter">
                    <option value=",">Coma (,)</option>
                    <option value=";">Punto y coma (;)</option>
                </select>
            </p>
            <?php wp_nonce_field('fospibay_csv_import', 'fospibay_csv_nonce'); ?>
            <p>
                <input type="submit" class="button button-primary" value="Importar CSV">
                <button type="button" class="button" onclick="processDirectly();">Procesar Directamente (Sin WP-Cron)</button>
            </p>
        </form>
        <?php
        // Handle auto-import from existing file
        if (isset($_POST['auto_import']) && isset($_POST['csv_path']) && isset($_POST['fospibay_csv_nonce']) && wp_verify_nonce($_POST['fospibay_csv_nonce'], 'fospibay_csv_import')) {
            $csv_path = sanitize_text_field($_POST['csv_path']);
            if (file_exists($csv_path)) {
                // Validate CSV structure first
                if (!fospibay_validate_csv_structure($csv_path)) {
                    echo '<div class="error"><p>Error: El archivo CSV no tiene la estructura esperada.</p></div>';
                    return;
                }

                $skip_existing = isset($_POST['skip_existing']) && $_POST['skip_existing'] == '1';
                $batch_size = isset($_POST['batch_size']) ? max(1, min(1000, absint($_POST['batch_size']))) : 50;
                $delimiter = ','; // Default to comma for export files

                $total_rows = fospibay_count_csv_rows($csv_path, $delimiter);
                update_option('fospibay_import_state', [
                    'file' => $csv_path,
                    'row_index' => 2,
                    'imported' => 0,
                    'updated' => 0,
                    'skipped' => 0,
                    'batch_size' => $batch_size,
                    'skip_existing' => $skip_existing,
                    'delimiter' => $delimiter,
                    'offset' => 0,
                    'debug_mode' => isset($_POST['debug_mode']) && $_POST['debug_mode'] == '1',
                    'total_rows' => $total_rows,
                    'images_downloaded' => 0,
                    'current_title' => '',
                    'debug_messages' => []
                ]);

                // Check if we should process directly
                if (isset($_POST['process_direct']) && $_POST['process_direct'] == '1') {
                    echo '<div class="updated"><p>Procesando directamente... Total de filas: ' . $total_rows . '</p></div>';
                    echo '<script>document.getElementById("import-progress-container").style.display = "block";</script>';

                    // Process directly via AJAX calls
                    echo '<script>
                        setTimeout(() => { processNextBatchDirectly(); }, 1000);
                    </script>';
                } else {
                    // Schedule first batch immediately
                    wp_schedule_single_event(time(), 'fospibay_process_batch');

                    // Also try to process immediately if possible
                    if (!defined('DOING_CRON')) {
                        spawn_cron();
                    }

                    echo '<div class="updated"><p>Importación del archivo local iniciada. Total de filas a procesar: ' . $total_rows . '</p></div>';
                }
                echo '<script>
                    document.getElementById("import-progress-container").style.display = "block";
                    document.getElementById("total-rows").textContent = "' . $total_rows . '";
                    if (document.getElementById("debug_mode") && document.getElementById("debug_mode").checked) {
                        document.getElementById("debug-output").style.display = "block";
                    }
                    startProgressMonitoring();
                </script>';
            }
        }
        // Handle file upload
        elseif (isset($_POST['fospibay_csv_nonce']) && wp_verify_nonce($_POST['fospibay_csv_nonce'], 'fospibay_csv_import') && !empty($_FILES['csv_file']['tmp_name'])) {
            $skip_existing = isset($_POST['skip_existing']) && $_POST['skip_existing'] == '1';
            $batch_size = isset($_POST['batch_size']) ? max(1, min(1000, absint($_POST['batch_size']))) : 50;
            $delimiter = isset($_POST['delimiter']) && $_POST['delimiter'] === ';' ? ';' : ',';
            $upload_dir = wp_upload_dir();
            $target_file = $upload_dir['path'] . '/fospibay-import-' . time() . '.csv';
            move_uploaded_file($_FILES['csv_file']['tmp_name'], $target_file);

            // Validate CSV structure
            if (!fospibay_validate_csv_structure($target_file)) {
                unlink($target_file);
                echo '<div class="error"><p>Error: El archivo CSV no tiene la estructura esperada. Verifique que contenga las columnas Title y Content.</p></div>';
                return;
            }
            $total_rows = fospibay_count_csv_rows($target_file, $delimiter);
            update_option('fospibay_import_state', [
                'file' => $target_file,
                'row_index' => 2,
                'imported' => 0,
                'updated' => 0,
                'skipped' => 0,
                'batch_size' => $batch_size,
                'skip_existing' => $skip_existing,
                'delimiter' => $delimiter,
                'offset' => 0,
                'debug_mode' => isset($_POST['debug_mode']) && $_POST['debug_mode'] == '1',
                'total_rows' => $total_rows,
                'images_downloaded' => 0,
                'current_title' => '',
                'debug_messages' => []
            ]);

            // Schedule first batch immediately
            wp_schedule_single_event(time(), 'fospibay_process_batch');

            // Also try to process immediately if possible
            if (!defined('DOING_CRON')) {
                spawn_cron();
            }

            echo '<div class="updated"><p>Importación iniciada. Total de filas a procesar: ' . $total_rows . '</p></div>';
            echo '<script>
                document.getElementById("import-progress-container").style.display = "block";
                document.getElementById("total-rows").textContent = "' . $total_rows . '";
                if (document.getElementById("debug_mode") && document.getElementById("debug_mode").checked) {
                    document.getElementById("debug-output").style.display = "block";
                }
                startProgressMonitoring();
            </script>';
        }
        ?>
        <p>Consulta el archivo de log en <code><?php echo esc_html(WP_CONTENT_DIR . '/fospibay-import-log.txt'); ?></code> para detalles de la importación.</p>
    </div>

    <script>
    function startProgressMonitoring() {
        let retryCount = 0;
        const maxRetries = 5;

        const checkProgress = setInterval(function() {
            fetch('<?php echo admin_url('admin-ajax.php'); ?>?action=fospibay_check_import_progress&_wpnonce=<?php echo wp_create_nonce('fospibay_progress'); ?>')
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                console.log('Progress data:', data); // Debug log
                retryCount = 0; // Reset retry count on success

                if (data.success) {
                    updateProgressDisplay(data.data);
                    if (data.data.completed) {
                        clearInterval(checkProgress);
                        document.getElementById('import-status').innerHTML = '<span style="color: green;">✓ Importación completada</span>';
                        showImportSummary(data.data);
                    }
                } else {
                    console.warn('Progress check returned success: false', data);
                }
            })
            .catch(error => {
                console.error('Error checking progress:', error);
                retryCount++;

                if (retryCount >= maxRetries) {
                    clearInterval(checkProgress);
                    document.getElementById('import-status').innerHTML = '<span style="color: red;">Error: No se pudo conectar con el servidor</span>';
                }
            });
        }, 2000); // Check every 2 seconds

        // Initial immediate check
        setTimeout(() => {
            fetch('<?php echo admin_url('admin-ajax.php'); ?>?action=fospibay_check_import_progress&_wpnonce=<?php echo wp_create_nonce('fospibay_progress'); ?>')
            .then(response => response.json())
            .then(data => {
                console.log('Initial progress check:', data);
                if (data.success) {
                    updateProgressDisplay(data.data);
                }
            });
        }, 500);
    }

    function updateProgressDisplay(data) {
        console.log('Updating display with:', data); // Debug log

        const progress = data.total_rows > 0 ? ((data.row_index - 2) / (data.total_rows - 1)) * 100 : 0;
        const progressBar = document.getElementById('progress-bar');
        const progressText = document.getElementById('progress-text');

        if (progressBar && progressText) {
            progressBar.style.width = Math.min(100, Math.max(0, progress)) + '%';
            progressText.textContent = Math.round(Math.min(100, Math.max(0, progress))) + '%';
        }

        // Update all stats
        const elements = {
            'rows-processed': Math.max(0, data.row_index - 2),
            'total-rows': Math.max(0, data.total_rows - 1),
            'posts-created': data.imported || 0,
            'posts-updated': data.updated || 0,
            'posts-skipped': data.skipped || 0,
            'images-downloaded': data.images_downloaded || 0,
            'import-status': data.status || 'Procesando...',
            'last-title': data.current_title || '-'
        };

        for (const [id, value] of Object.entries(elements)) {
            const element = document.getElementById(id);
            if (element) {
                element.textContent = value;
            }
        }

        // Add debug messages
        if (data.debug_messages && data.debug_messages.length > 0) {
            const debugDiv = document.getElementById('debug-messages');
            if (debugDiv) {
                data.debug_messages.forEach(msg => {
                    const msgElement = document.createElement('div');
                    msgElement.innerHTML = msg;
                    debugDiv.appendChild(msgElement);
                    debugDiv.scrollTop = debugDiv.scrollHeight;
                });
            }
        }
    }

    function showImportSummary(data) {
        const summaryHtml = `
            <div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; margin-top: 20px; border-radius: 5px;">
                <h3 style="color: #155724; margin-top: 0;">✓ Resumen de Importación Completada</h3>
                <ul style="color: #155724;">
                    <li><strong>${data.imported}</strong> entradas creadas</li>
                    <li><strong>${data.updated}</strong> entradas actualizadas</li>
                    <li><strong>${data.skipped}</strong> entradas omitidas</li>
                    <li><strong>${data.images_downloaded || 0}</strong> imágenes descargadas</li>
                </ul>
            </div>
        `;
        document.getElementById('import-progress-container').insertAdjacentHTML('beforeend', summaryHtml);
    }

    function processDirectly() {
        const form = document.querySelector('form');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'process_direct';
        input.value = '1';
        form.appendChild(input);
        form.submit();
    }

    function processNextBatchDirectly() {
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=fospibay_process_batch_ajax&_wpnonce=<?php echo wp_create_nonce('fospibay_batch'); ?>'
        })
        .then(response => response.json())
        .then(data => {
            console.log('Batch processed:', data);

            // Check progress
            fetch('<?php echo admin_url('admin-ajax.php'); ?>?action=fospibay_check_import_progress&_wpnonce=<?php echo wp_create_nonce('fospibay_progress'); ?>')
            .then(response => response.json())
            .then(progressData => {
                if (progressData.success) {
                    updateProgressDisplay(progressData.data);

                    if (!progressData.data.completed) {
                        // Process next batch
                        setTimeout(() => { processNextBatchDirectly(); }, 1000);
                    } else {
                        document.getElementById('import-status').innerHTML = '<span style="color: green;">✓ Importación completada</span>';
                        showImportSummary(progressData.data);
                    }
                }
            });
        })
        .catch(error => {
            console.error('Error processing batch:', error);
            document.getElementById('import-status').innerHTML = '<span style="color: red;">Error al procesar lote</span>';
        });
    }
    </script>
    <?php
}

// Admin page for importing featured images only
function fospibay_featured_image_import_page() {
    ?>
    <div class="wrap">
        <h1>Importar Imágenes Destacadas</h1>
        <p>Sube un CSV con las columnas 'Title' (título de la entrada) y 'Featured' o 'URL_2' (URL de la imagen destacada). El plugin buscará entradas por título y asignará las imágenes a las entradas sin imagen destacada.</p>
        <form method="post" enctype="multipart/form-data">
            <p>
                <label for="csv_file">Selecciona el archivo CSV:</label><br>
                <input type="file" name="csv_file" id="csv_file" accept=".csv">
            </p>
            <p>
                <label for="delimiter">Delimitador CSV:</label><br>
                <select name="delimiter" id="delimiter">
                    <option value=",">Coma (,)</option>
                    <option value=";">Punto y coma (;)</option>
                </select>
            </p>
            <?php wp_nonce_field('fospibay_featured_image_import', 'fospibay_featured_image_nonce'); ?>
            <p>
                <input type="submit" class="button button-primary" value="Importar Imágenes Destacadas">
            </p>
        </form>
        <?php
        if (isset($_POST['fospibay_featured_image_nonce']) && wp_verify_nonce($_POST['fospibay_featured_image_nonce'], 'fospibay_featured_image_import') && !empty($_FILES['csv_file']['tmp_name'])) {
            $delimiter = isset($_POST['delimiter']) && $_POST['delimiter'] === ';' ? ';' : ',';
            fospibay_import_featured_images($_FILES['csv_file']['tmp_name'], $delimiter);
        }
        ?>
        <p>Consulta el archivo de log en <code><?php echo esc_html(WP_CONTENT_DIR . '/fospibay-import-log.txt'); ?></code> para detalles de la importación.</p>
    </div>
    <?php
}

// Log errors to a file for debugging
function fospibay_log_error($message) {
    $log_file = WP_CONTENT_DIR . '/fospibay-import-log.txt';
    $timestamp = current_time('mysql');
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND | LOCK_EX);
}

// Check if an image already exists by filename
function fospibay_check_existing_image($image_url) {
    global $wpdb;
    $filename = basename($image_url);
    fospibay_log_error('Verificando imagen existente para nombre de archivo: ' . $filename);
    $attachment = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND post_name = %s",
            sanitize_title(pathinfo($filename, PATHINFO_FILENAME))
        )
    );
    if ($attachment) {
        fospibay_log_error('Imagen existente encontrada para nombre de archivo: ' . $filename . ' - ID: ' . $attachment->ID);
        return $attachment->ID;
    }
    fospibay_log_error('No se encontró imagen existente para nombre de archivo: ' . $filename);
    return false;
}

// Import only featured images for posts without them, using post title
function fospibay_import_featured_images($file_path, $delimiter) {
    if (!file_exists($file_path) || !is_readable($file_path)) {
        echo '<div class="error"><p>Error: No se pudo leer el archivo CSV.</p></div>';
        fospibay_log_error('No se pudo leer el archivo CSV: ' . $file_path);
        return;
    }

    fospibay_log_error('Iniciando importación de imágenes destacadas. Archivo: ' . $file_path . ', Delimitador: ' . $delimiter);

    // Ensure UTF-8 encoding
    ini_set('default_charset', 'UTF-8');

    // Read CSV headers
    $file_handle = fopen($file_path, 'r');

    // Detect and handle BOM
    $bom = fread($file_handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($file_handle);
    }

    $raw_headers = fgetcsv($file_handle, 0, $delimiter, '"', '\\');
    if ($raw_headers) {
        // Convert encoding if necessary
        $raw_headers = array_map(function($header) {
            return mb_convert_encoding($header, 'UTF-8', 'auto');
        }, $raw_headers);
    }
    fospibay_log_error('Encabezados crudos del CSV: ' . print_r($raw_headers, true));
    if (empty($raw_headers) || count($raw_headers) < 2) {
        echo '<div class="error"><p>Error: No se encontraron encabezados válidos en el CSV.</p></div>';
        fospibay_log_error('No se encontraron encabezados válidos en el CSV.');
        fclose($file_handle);
        return;
    }

    // Handle duplicate headers
    $header_map = [];
    $used_headers = [];
    foreach ($raw_headers as $index => $header) {
        $original_header = trim($header);
        $new_header = $original_header;
        $suffix = 1;
        while (isset($used_headers[$new_header])) {
            $new_header = $original_header . '_' . $suffix;
            $suffix++;
        }
        $header_map[$new_header] = $index;
        $used_headers[$new_header] = true;
    }
    $headers = array_keys($header_map);
    fospibay_log_error('Encabezados del CSV procesados: ' . implode(', ', $headers) . ' (Total: ' . count($headers) . ')');

    // Find title and featured image headers
    $title_header = null;
    if (in_array('Title', $headers)) {
        $title_header = 'Title';
        fospibay_log_error('Encabezado de título encontrado: Title (índice ' . $header_map['Title'] . ')');
    } else {
        echo '<div class="error"><p>Error: No se encontró la columna "Title" en el CSV.</p></div>';
        fospibay_log_error('No se encontró la columna "Title" en el CSV.');
        fclose($file_handle);
        return;
    }

    $featured_image_header = null;
    $possible_image_headers = ['Featured', 'URL', 'url', 'Featured Image', 'featured_image', 'image_url', 'Image URL', 'URL_2'];
    foreach ($possible_image_headers as $header) {
        if (in_array($header, $headers)) {
            $featured_image_header = $header;
            fospibay_log_error('Encabezado de imagen destacada encontrado: ' . $header . ' (índice ' . $header_map[$header] . ')');
            break;
        }
    }
    if (!$featured_image_header) {
        echo '<div class="error"><p>Error: No se encontró una columna de imagen destacada en el CSV.</p></div>';
        fospibay_log_error('No se encontró encabezado de imagen destacada.');
        fclose($file_handle);
        return;
    }

    // Process CSV rows
    $updated = 0;
    $skipped = 0;
    $row_index = 2;
    while (($row = fgetcsv($file_handle, 0, $delimiter, '"', '\\')) !== false) {
        // Convert encoding for each row
        $row = array_map(function($value) {
            return mb_convert_encoding($value, 'UTF-8', 'auto');
        }, $row);
        fospibay_log_error('Datos crudos de la fila ' . $row_index . ': ' . print_r($row, true));
        if (empty(array_filter($row, function($value) { return trim($value) !== ''; }))) {
            fospibay_log_error('Fila ' . $row_index . ' está vacía o inválida, omitiendo.');
            $skipped++;
            $row_index++;
            continue;
        }

        if (count($row) !== count($headers)) {
            fospibay_log_error('Advertencia en fila ' . $row_index . ': Número de columnas (' . count($row) . ') no coincide con encabezados (' . count($headers) . '). Ajustando fila.');
            $row = array_slice($row, 0, count($headers));
            $row = array_pad($row, count($headers), '');
            fospibay_log_error('Fila ajustada ' . $row_index . ': ' . print_r($row, true));
        }

        $data = array_combine($headers, $row);
        if ($data === false) {
            fospibay_log_error('Error al combinar fila ' . $row_index . ' con encabezados. Raw data: ' . print_r($row, true));
            $skipped++;
            $row_index++;
            continue;
        }

        $post_title = trim($data[$title_header]);
        if (empty($post_title)) {
            fospibay_log_error('Fila ' . $row_index . ' omitida: Título vacío.');
            $skipped++;
            $row_index++;
            continue;
        }

        // Preserve original title formatting
        $post_title = html_entity_decode($post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Find post by title
        $post = get_page_by_title($post_title, OBJECT, 'post');
        if (!$post || $post->post_type !== 'post') {
            fospibay_log_error('Fila ' . $row_index . ' omitida: Entrada con título "' . $post_title . '" no encontrada o no es una entrada válida.');
            $skipped++;
            $row_index++;
            continue;
        }

        // Check if post already has a featured image
        if (has_post_thumbnail($post->ID)) {
            fospibay_log_error('Fila ' . $row_index . ' omitida: Entrada con título "' . $post_title . '" (ID: ' . $post->ID . ') ya tiene imagen destacada.');
            $skipped++;
            $row_index++;
            continue;
        }

        $image_url = trim($data[$featured_image_header]);
        if (empty($image_url) || !filter_var($image_url, FILTER_VALIDATE_URL)) {
            fospibay_log_error('Fila ' . $row_index . ' omitida: URL de imagen destacada inválida o vacía: ' . $image_url);
            $skipped++;
            $row_index++;
            continue;
        }

        fospibay_log_error('Procesando imagen destacada para entrada con título "' . $post_title . '" (ID: ' . $post->ID . '): ' . $image_url);
        $existing_image_id = fospibay_check_existing_image($image_url);
        $featured_image_id = $existing_image_id ?: fospibay_download_and_attach_image($image_url, $post->ID);
        if ($featured_image_id && !is_wp_error($featured_image_id)) {
            $attachment = get_post($featured_image_id);
            if ($attachment && strpos($attachment->post_mime_type, 'image/') === 0) {
                $file_path = get_attached_file($featured_image_id);
                if ($file_path) {
                    $attachment_data = wp_generate_attachment_metadata($featured_image_id, $file_path);
                    wp_update_attachment_metadata($featured_image_id, $attachment_data);
                    fospibay_log_error('Metadatos de imagen destacada generados para ID ' . $featured_image_id);
                }
                $result = set_post_thumbnail($post->ID, $featured_image_id);
                if ($result) {
                    fospibay_log_error('Imagen destacada asignada correctamente a entrada con título "' . $post_title . '" (ID: ' . $post->ID . '): ID ' . $featured_image_id);
                    $updated++;
                } else {
                    fospibay_log_error('Error al asignar imagen destacada a entrada con título "' . $post_title . '" (ID: ' . $post->ID . ')');
                    $skipped++;
                }
            } else {
                fospibay_log_error('Error: El ID ' . $featured_image_id . ' no corresponde a una imagen válida para entrada con título "' . $post_title . '" (ID: ' . $post->ID . ')');
                $skipped++;
            }
        } else {
            fospibay_log_error('Error al procesar imagen destacada para entrada con título "' . $post_title . '" (ID: ' . $post->ID . '): ' . ($featured_image_id ? $featured_image_id->get_error_message() : 'URL inválida o fallo en la descarga'));
            $skipped++;
        }

        $row_index++;
    }

    fclose($file_handle);
    echo '<div class="updated"><p>Importación de imágenes destacadas completada. Entradas actualizadas: ' . $updated . ', omitidas: ' . $skipped . '</p></div>';
    fospibay_log_error('Importación de imágenes destacadas completada. Entradas actualizadas: ' . $updated . ', omitidas: ' . $skipped);
}

// AJAX handler for progress checking
add_action('wp_ajax_fospibay_check_import_progress', 'fospibay_ajax_check_progress');
add_action('wp_ajax_nopriv_fospibay_check_import_progress', 'fospibay_ajax_check_progress'); // For non-logged in users if needed
function fospibay_ajax_check_progress() {
    // Temporarily disable nonce check for debugging
    // if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'fospibay_progress')) {
    //     wp_die('Nonce verification failed');
    // }

    $state = get_option('fospibay_import_state', false);
    if (!$state) {
        wp_send_json_success([
            'completed' => true,
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'row_index' => 0,
            'total_rows' => 0,
            'status' => 'No hay importación activa'
        ]);
        return;
    }

    $completed = !file_exists($state['file']) || ($state['row_index'] >= ($state['total_rows'] ?? 0));

    // Get recent debug messages
    $debug_messages = [];
    if (!empty($state['debug_mode']) && !empty($state['debug_messages'])) {
        $debug_messages = array_slice($state['debug_messages'], -5); // Last 5 messages
    }

    $response_data = [
        'completed' => $completed,
        'imported' => $state['imported'] ?? 0,
        'updated' => $state['updated'] ?? 0,
        'skipped' => $state['skipped'] ?? 0,
        'row_index' => $state['row_index'] ?? 2,
        'total_rows' => $state['total_rows'] ?? 0,
        'images_downloaded' => $state['images_downloaded'] ?? 0,
        'current_title' => $state['current_title'] ?? '',
        'status' => $completed ? 'Completado' : 'Procesando fila ' . ($state['row_index'] ?? 0) . ' de ' . ($state['total_rows'] ?? 0),
        'debug_messages' => $debug_messages,
        'file_exists' => file_exists($state['file'] ?? ''),
        'batch_size' => $state['batch_size'] ?? 0
    ];

    // Log for debugging
    error_log('FOSPIBAY Progress Check: ' . json_encode($response_data));

    wp_send_json_success($response_data);
}

// Count total rows in CSV
function fospibay_count_csv_rows($file_path, $delimiter = ',') {
    if (!file_exists($file_path) || !is_readable($file_path)) {
        return 0;
    }

    $row_count = 0;
    $handle = fopen($file_path, 'r');

    // Detect and skip BOM
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($handle);
    }

    while (fgetcsv($handle, 0, $delimiter, '"', '\\') !== false) {
        $row_count++;
    }

    fclose($handle);
    return $row_count;
}

// AJAX handler for direct batch processing
add_action('wp_ajax_fospibay_process_batch_ajax', 'fospibay_process_batch_ajax');
function fospibay_process_batch_ajax() {
    // Verify nonce
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'fospibay_batch')) {
        wp_send_json_error('Nonce verification failed');
        return;
    }

    // Call the batch processor
    fospibay_process_batch();

    wp_send_json_success(['message' => 'Batch processed']);
}

// Process a batch of CSV rows (main import)
add_action('fospibay_process_batch', 'fospibay_process_batch');
function fospibay_process_batch() {
    $state = get_option('fospibay_import_state', false);
    if (!$state || !file_exists($state['file'])) {
        fospibay_log_error('Estado de importación inválido o archivo no encontrado.');
        delete_option('fospibay_import_state');
        return;
    }

    $file_path = $state['file'];
    $row_index = $state['row_index'];
    $imported = $state['imported'];
    $updated = $state['updated'];
    $skipped = $state['skipped'];
    $batch_size = $state['batch_size'] ?? 5;
    $skip_existing = $state['skip_existing'] ?? false;
    $delimiter = $state['delimiter'] ?? ',';
    $offset = $state['offset'] ?? 0;
    $batch_number = floor(($row_index - 2) / $batch_size) + 1;
    $start_time = microtime(true);

    fospibay_log_error('Iniciando procesamiento de lote ' . $batch_number . ' desde offset ' . $offset . ', tamaño de lote: ' . $batch_size . ', fila actual: ' . $row_index . ' de ' . ($state['total_rows'] ?? 'desconocido'));

    if (!file_exists($file_path) || !is_readable($file_path)) {
        fospibay_log_error('No se pudo leer el archivo CSV: ' . $file_path);
        delete_option('fospibay_import_state');
        return;
    }

    // Ensure UTF-8 encoding
    ini_set('default_charset', 'UTF-8');

    $file_handle = fopen($file_path, 'r');
    if ($offset === 0) {
        // Detect and handle BOM
        $bom = fread($file_handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($file_handle);
        }

        $raw_headers = fgetcsv($file_handle, 0, $delimiter, '"', '\\');
        if ($raw_headers) {
            // Convert encoding if necessary
            $raw_headers = array_map(function($header) {
                return mb_convert_encoding($header, 'UTF-8', 'auto');
            }, $raw_headers);
        }
        fospibay_log_error('Encabezados crudos del CSV: ' . print_r($raw_headers, true));
        if (empty($raw_headers) || count($raw_headers) < 2) {
            fospibay_log_error('No se encontraron encabezados válidos en el CSV.');
            fclose($file_handle);
            delete_option('fospibay_import_state');
            return;
        }

        $header_map = [];
        $used_headers = [];
        foreach ($raw_headers as $index => $header) {
            $original_header = trim($header);
            $new_header = $original_header;
            $suffix = 1;
            while (isset($used_headers[$new_header])) {
                $new_header = $original_header . '_' . $suffix;
                $suffix++;
            }
            $header_map[$new_header] = $index;
            $used_headers[$new_header] = true;
        }
        $headers = array_keys($header_map);
        fospibay_log_error('Encabezados del CSV procesados: ' . implode(', ', $headers) . ' (Total: ' . count($headers) . ')');

        $required_headers = ['Title', 'Content'];
        foreach ($required_headers as $req_header) {
            if (!in_array($req_header, $headers)) {
                fospibay_log_error('Falta el encabezado requerido "' . $req_header . '" en el CSV.');
                fclose($file_handle);
                delete_option('fospibay_import_state');
                return;
            }
        }

        $featured_image_header = null;
        $possible_image_headers = ['Featured', 'URL_2', 'URL', 'url', 'Featured Image', 'featured_image', 'image_url', 'Image URL'];
        foreach ($possible_image_headers as $header) {
            if (in_array($header, $headers)) {
                $featured_image_header = $header;
                fospibay_log_error('Encabezado de imagen destacada encontrado: ' . $header . ' (índice ' . $header_map[$header] . ')');
                break;
            }
        }

        $elementor_data_header = null;
        $possible_elementor_headers = ['_elementor_data', 'elementor_data', 'Elementor Data'];
        foreach ($possible_elementor_headers as $header) {
            if (in_array($header, $headers)) {
                $elementor_data_header = $header;
                fospibay_log_error('Encabezado de datos Elementor encontrado: ' . $header);
                break;
            }
        }

        $categories_header = null;
        $possible_categories_headers = ['Categorías', 'Categories', 'category'];
        foreach ($possible_categories_headers as $header) {
            if (in_array($header, $headers)) {
                $categories_header = $header;
                fospibay_log_error('Encabezado de categorías encontrado: ' . $header);
                break;
            }
        }


        $state['headers'] = $headers;
        $state['header_map'] = $header_map;
        $state['featured_image_header'] = $featured_image_header;
        $state['elementor_data_header'] = $elementor_data_header;
        $state['categories_header'] = $categories_header;
        update_option('fospibay_import_state', $state);
    } else {
        fseek($file_handle, $offset);
        $headers = $state['headers'];
        $header_map = $state['header_map'];
        $featured_image_header = $state['featured_image_header'];
        $elementor_data_header = $state['elementor_data_header'];
        $categories_header = $state['categories_header'];
    }

    $batch = [];
    $processed = 0;
    $max_execution_time = ini_get('max_execution_time') ?: 30;

    while ($processed < $batch_size && ($row = fgetcsv($file_handle, 0, $delimiter, '"', '\\')) !== false) {
        // Check execution time (use 80% of max to be safe)
        if (microtime(true) - $start_time > ($max_execution_time * 0.8)) {
            fospibay_log_error('Tiempo de ejecución cercano al límite, deteniendo lote ' . $batch_number);
            break;
        }

        // Convert encoding for each row
        $row = array_map(function($value) {
            return mb_convert_encoding($value, 'UTF-8', 'auto');
        }, $row);

        fospibay_log_error('Datos crudos de la fila ' . $row_index . ': ' . print_r($row, true));
        if (empty(array_filter($row, function($value) { return trim($value) !== ''; }))) {
            fospibay_log_error('Fila ' . $row_index . ' está vacía o inválida, omitiendo.');
            $skipped++;
            $row_index++;
            continue;
        }

        if (count($row) !== count($headers)) {
            fospibay_log_error('Advertencia en fila ' . $row_index . ': Número de columnas (' . count($row) . ') no coincide con encabezados (' . count($headers) . '). Ajustando fila.');
            $row = array_slice($row, 0, count($headers));
            $row = array_pad($row, count($headers), '');
            fospibay_log_error('Fila ajustada ' . $row_index . ': ' . print_r($row, true));
        }

        $data = array_combine($headers, $row);
        if ($data === false) {
            fospibay_log_error('Error al combinar fila ' . $row_index . ' con encabezados. Raw data: ' . print_r($row, true));
            $skipped++;
            $row_index++;
            continue;
        }
        fospibay_log_error('Datos de la fila ' . $row_index . ': ' . wp_json_encode($data));

        // Update current title for progress display
        if (isset($data['Title'])) {
            $state['current_title'] = substr($data['Title'], 0, 100);
        }

        $title = isset($data['Title']) ? trim($data['Title']) : '';
        $content = isset($data['Content']) ? trim($data['Content']) : '';
        $title_empty = empty($title);
        $content_empty = empty($content);
        if ($title_empty || $content_empty) {
            $reason = $title_empty && $content_empty ? 'Título y contenido vacíos' : ($title_empty ? 'Título vacío' : 'Contenido vacío');
            fospibay_log_error('Fila ' . $row_index . ' omitida: ' . $reason . '.');
            $skipped++;
            $row_index++;
            continue;
        }

        // Preserve original title formatting
        $post_title = sanitize_text_field(wp_check_invalid_utf8($title, true));
        $post_title = html_entity_decode($post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        fospibay_log_error('Título procesado para la fila ' . $row_index . ': ' . $post_title);
        $post_id_from_csv = !empty($data['ID']) ? absint($data['ID']) : 0;
        fospibay_log_error('ID proporcionado en CSV para la fila ' . $row_index . ': ' . $post_id_from_csv);

        $existing_post_id = 0;
        if ($post_id_from_csv) {
            $existing_post = get_post($post_id_from_csv);
            if ($existing_post && $existing_post->post_type === ($data['Post Type'] ?? 'post')) {
                $existing_post_id = $post_id_from_csv;
                fospibay_log_error('Entrada existente encontrada por ID: ' . $existing_post_id . ' en fila ' . $row_index);
            }
        }
        if (!$existing_post_id) {
            $existing_post = get_page_by_title($post_title, OBJECT, $data['Post Type'] ?? 'post');
            if ($existing_post) {
                $existing_post_id = $existing_post->ID;
                fospibay_log_error('Entrada existente encontrada por título: ' . $existing_post_id . ' en fila ' . $row_index);
            }
        }

        if ($existing_post_id && $skip_existing) {
            fospibay_log_error('Entrada existente encontrada (ID: ' . $existing_post_id . ') en fila ' . $row_index . ', omitiendo por configuración.');
            $skipped++;
            $row_index++;
            continue;
        }

        $post_date = !empty($data['Date']) ? $data['Date'] : current_time('mysql');
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $post_date)) {
            fospibay_log_error('Fecha inválida en fila ' . $row_index . ': ' . $post_date . '. Usando fecha actual: ' . current_time('mysql'));
            $post_date = current_time('mysql');
        }

        // Ensure content is properly encoded
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $post_data = [
            'post_title' => $post_title,
            'post_content' => $content,
            'post_type' => !empty($data['Post Type']) ? $data['Post Type'] : 'post',
            'post_status' => !empty($data['Status']) ? $data['Status'] : 'publish',
            'post_date' => $post_date,
        ];

        if ($existing_post_id) {
            $post_data['ID'] = $existing_post_id;
        }

        $post_id = wp_insert_post($post_data, true);
        if (is_wp_error($post_id)) {
            fospibay_log_error('Error al crear/actualizar entrada en fila ' . $row_index . ': ' . $post_id->get_error_message());
            $skipped++;
            $row_index++;
            continue;
        }

        if ($existing_post_id) {
            $updated++;
        } else {
            $imported++;
            fospibay_log_error('NUEVA ENTRADA CREADA: ID ' . $post_id . ' - Título: ' . $post_title);

            // Add debug message
            if (!empty($state['debug_mode'])) {
                $state['debug_messages'][] = '<span style="color: green;">✓ Creada entrada ID ' . $post_id . ': ' . htmlspecialchars($post_title) . '</span>';
                // Keep only last 20 messages
                $state['debug_messages'] = array_slice($state['debug_messages'], -20);
            }
        }

        // Process categories
        if ($categories_header && !empty($data[$categories_header])) {
            $categories = array_filter(array_map('trim', explode('|', $data[$categories_header])));
            $category_ids = [];
            foreach ($categories as $category_name) {
                $category = get_term_by('name', $category_name, 'category');
                if ($category && !is_wp_error($category)) {
                    $category_ids[] = $category->term_id;
                } else {
                    $new_category = wp_insert_term($category_name, 'category');
                    if (!is_wp_error($new_category)) {
                        $category_ids[] = $new_category['term_id'];
                    }
                }
            }
            if (!empty($category_ids)) {
                wp_set_post_terms($post_id, $category_ids, 'category', false);
            }
        }

        $images_count = fospibay_clean_and_import_images($post_id, $data, $featured_image_header, $elementor_data_header, $state);
        if ($images_count > 0) {
            $state['images_downloaded'] = ($state['images_downloaded'] ?? 0) + $images_count;
        }
        $row_index++;
        $processed++;
        $batch[] = $row;

        $state['row_index'] = $row_index;
        $state['imported'] = $imported;
        $state['updated'] = $updated;
        $state['skipped'] = $skipped;
        $state['offset'] = ftell($file_handle);
        update_option('fospibay_import_state', $state);
    }

    fospibay_log_error('Lote ' . $batch_number . ' procesado. Filas procesadas en este lote: ' . $processed . ', Total acumulado - Creadas: ' . $imported . ', Actualizadas: ' . $updated . ', Omitidas: ' . $skipped . ', Posición actual: ' . $row_index . '/' . ($state['total_rows'] ?? 'desconocido'));
    wp_cache_flush();

    if (!feof($file_handle)) {
        wp_schedule_single_event(time() + 1, 'fospibay_process_batch');

        // Try to trigger cron immediately
        if (!defined('DOING_CRON')) {
            spawn_cron();
        }
    } else {
        fospibay_log_error('Importación completada. Entradas creadas: ' . $imported . ', actualizadas: ' . $updated . ', omitidas: ' . $skipped);
        unlink($file_path);
        delete_option('fospibay_import_state');
    }

    fclose($file_handle);
}

// Clean content and import images
function fospibay_clean_and_import_images($post_id, $data, $featured_image_header, $elementor_data_header, &$state = null) {
    $images_downloaded = 0;
    $content = get_post_field('post_content', $post_id);
    $content = preg_replace('/<!-- wp:[a-z\/]+ -->/', '', $content);
    $content = preg_replace('/<!-- \/wp:[a-z\/]+ -->/', '', $content);
    $content = preg_replace_callback(
        '/<img\s+[^>]*src="https:\/\/static\.xx\.fbcdn\.net\/images\/emoji\.php\/[^"]+"[^>]*alt="([^"]+)"[^>]*>/i',
        function ($matches) {
            return $matches[1];
        },
        $content
    );
    wp_update_post(['ID' => $post_id, 'post_content' => $content], true);

    if ($featured_image_header && !empty($data[$featured_image_header])) {
        $image_url = trim($data[$featured_image_header]);
        if (filter_var($image_url, FILTER_VALIDATE_URL)) {
            $existing_image_id = fospibay_check_existing_image($image_url);
            $featured_image_id = $existing_image_id ?: fospibay_download_and_attach_image($image_url, $post_id);
            if ($featured_image_id && !is_wp_error($featured_image_id)) {
                $attachment = get_post($featured_image_id);
                if ($attachment && strpos($attachment->post_mime_type, 'image/') === 0) {
                    $file_path = get_attached_file($featured_image_id);
                    if ($file_path) {
                        $attachment_data = wp_generate_attachment_metadata($featured_image_id, $file_path);
                        wp_update_attachment_metadata($featured_image_id, $attachment_data);
                    }
                    set_post_thumbnail($post_id, $featured_image_id);
                    $images_downloaded++;
                    fospibay_log_error('IMAGEN DESTACADA ASIGNADA: ID ' . $featured_image_id . ' para entrada ID ' . $post_id);
                }
            } else {
                fospibay_log_error('ERROR: No se pudo descargar/asignar imagen destacada para entrada ID ' . $post_id);
            }
        } else {
            fospibay_log_error('URL de imagen destacada inválida o vacía para entrada ID ' . $post_id);
        }
    }

    if ($elementor_data_header && !empty($data[$elementor_data_header])) {
        $elementor_data = json_decode($data[$elementor_data_header], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($elementor_data)) {
            $all_gallery_image_ids = [];
            foreach ($elementor_data as $section) {
                if (!isset($section['elements'])) continue;
                foreach ($section['elements'] as $column) {
                    if (!isset($column['elements'])) continue;
                    foreach ($column['elements'] as $widget) {
                        if (isset($widget['widgetType']) && $widget['widgetType'] === 'gallery' && !empty($widget['settings']['gallery'])) {
                            foreach ($widget['settings']['gallery'] as $image) {
                                if (!isset($image['url'])) continue;
                                $existing_image_id = fospibay_check_existing_image($image['url']);
                                $image_id = $existing_image_id ?: fospibay_download_and_attach_image($image['url'], $post_id);
                                if ($image_id && !is_wp_error($image_id)) {
                                    $all_gallery_image_ids[] = $image_id;
                                    if (!$existing_image_id) {
                                        $images_downloaded++;
                                    }
                                }
                            }
                        }
                    }
                }
            }
            if (!empty($all_gallery_image_ids) && function_exists('update_field')) {
                update_field('field_686ea8c997852', $all_gallery_image_ids, $post_id);
            }
        }
    }

    return $images_downloaded;
}

// Download and attach an image to the media library
function fospibay_download_and_attach_image($image_url, $post_id) {
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    fospibay_log_error('Iniciando descarga de imagen para entrada ID ' . $post_id . ': ' . $image_url);
    if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
        fospibay_log_error('URL de imagen inválida: ' . $image_url . ' para entrada ID ' . $post_id);
        return new WP_Error('invalid_url', 'URL de imagen inválida: ' . $image_url);
    }

    $existing_image_id = fospibay_check_existing_image($image_url);
    if ($existing_image_id) {
        fospibay_log_error('Imagen existente encontrada, omitiendo descarga para entrada ID ' . $post_id . ': ID ' . $existing_image_id);
        return $existing_image_id;
    }

    $image_data = wp_safe_remote_get($image_url, ['timeout' => 60]);
    if (is_wp_error($image_data)) {
        fospibay_log_error('Error al descargar imagen: ' . $image_url . ' - ' . $image_data->get_error_message());
        return $image_data;
    }

    $image_content = wp_remote_retrieve_body($image_data);
    if (empty($image_content)) {
        fospibay_log_error('Error: Contenido de imagen vacío para: ' . $image_url);
        return new WP_Error('empty_content', 'Contenido de imagen vacío para: ' . $image_url);
    }
    fospibay_log_error('Imagen descargada correctamente, tamaño: ' . strlen($image_content) . ' bytes para: ' . $image_url);

    $filename = basename($image_url);
    if (empty($filename) || !preg_match('/\.(jpg|jpeg|png|gif)$/i', $filename)) {
        $filename = 'image-' . $post_id . '-' . time() . '.jpg';
        fospibay_log_error('Nombre de archivo generado para imagen sin nombre: ' . $filename);
    }

    $upload_dir = wp_upload_dir();
    if (!is_writable($upload_dir['path'])) {
        fospibay_log_error('Error: El directorio de subidas no es escribible: ' . $upload_dir['path']);
        return new WP_Error('upload_dir_not_writable', 'El directorio de subidas no es escribible: ' . $upload_dir['path']);
    }
    fospibay_log_error('Directorio de subidas verificado: ' . $upload_dir['path']);

    $upload = wp_upload_bits($filename, null, $image_content);
    if ($upload['error']) {
        fospibay_log_error('Error al subir imagen: ' . $image_url . ' - ' . $upload['error']);
        return new WP_Error('upload_failed', 'Error al subir imagen: ' . $upload['error']);
    }
    fospibay_log_error('Imagen subida al servidor: ' . $upload['file']);

    $file_path = $upload['file'];
    $file_type = wp_check_filetype($filename, null);
    fospibay_log_error('Tipo de archivo detectado: ' . ($file_type['type'] ? $file_type['type'] : 'image/jpeg'));

    $attachment = [
        'post_mime_type' => $file_type['type'] ? $file_type['type'] : 'image/jpeg',
        'post_title' => sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)),
        'post_content' => '',
        'post_status' => 'inherit',
        'guid' => $image_url,
    ];
    fospibay_log_error('Datos de adjunto preparados: ' . wp_json_encode($attachment));

    $attachment_id = wp_insert_attachment($attachment, $file_path, $post_id);
    if (is_wp_error($attachment_id)) {
        fospibay_log_error('Error al crear adjunto para imagen: ' . $image_url . ' - ' . $attachment_id->get_error_message());
        return $attachment_id;
    }
    fospibay_log_error('Adjunto creado con ID: ' . $attachment_id);

    $attachment_data = wp_generate_attachment_metadata($attachment_id, $file_path);
    wp_update_attachment_metadata($attachment_id, $attachment_data);
    fospibay_log_error('Metadatos de adjunto generados: ' . wp_json_encode($attachment_data));

    fospibay_log_error('Imagen subida correctamente: ' . $image_url . ' - ID: ' . $attachment_id);
    return $attachment_id;
}

// Add custom field to Bricks dynamic data
add_filter('bricks/dynamic_data/post_fields', 'fospibay_add_custom_fields_to_bricks', 10, 1);
function fospibay_add_custom_fields_to_bricks($fields) {
    $fields['field_686ea8c997852'] = 'Acf Gallery Images';
    return $fields;
}

// Validate CSV structure before processing
function fospibay_validate_csv_structure($file_path) {
    if (!file_exists($file_path) || !is_readable($file_path)) {
        fospibay_log_error('CSV validation failed: File not readable - ' . $file_path);
        return false;
    }

    ini_set('default_charset', 'UTF-8');
    $file_handle = fopen($file_path, 'r');

    // Detect and handle BOM
    $bom = fread($file_handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($file_handle);
    }

    // Try to read headers with different delimiters
    $delimiters = [',', ';', '\t', '|'];
    $valid_structure = false;

    foreach ($delimiters as $test_delimiter) {
        rewind($file_handle);
        // Skip BOM again if necessary
        if ($bom === "\xEF\xBB\xBF") {
            fread($file_handle, 3);
        }

        $headers = fgetcsv($file_handle, 0, $test_delimiter, '"', '\\');
        if ($headers && count($headers) > 10) {
            // Convert encoding
            $headers = array_map(function($header) {
                return mb_convert_encoding(trim($header), 'UTF-8', 'auto');
            }, $headers);

            // Check for required headers
            $has_title = false;
            $has_content = false;

            foreach ($headers as $header) {
                if (stripos($header, 'Title') !== false && strlen($header) < 10) {
                    $has_title = true;
                }
                if (stripos($header, 'Content') !== false) {
                    $has_content = true;
                }
            }

            if ($has_title && $has_content) {
                $valid_structure = true;
                fospibay_log_error('CSV structure validated with delimiter: ' . $test_delimiter);
                break;
            }
        }
    }

    fclose($file_handle);

    if (!$valid_structure) {
        fospibay_log_error('CSV validation failed: Required headers (Title, Content) not found');
    }

    return $valid_structure;
}

// Improved CSV reading function for handling multiline content
function fospibay_read_csv_with_multiline($file_handle, $delimiter = ',') {
    $enclosure = '"';
    $escape = '\\';

    // Use a larger length limit for fgetcsv to handle long content
    $row = fgetcsv($file_handle, 0, $delimiter, $enclosure, $escape);

    if ($row === false) {
        return false;
    }

    // Clean and convert encoding for each field
    $row = array_map(function($value) {
        // Remove any lingering UTF-8 BOM
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
        // Convert encoding
        $value = mb_convert_encoding($value, 'UTF-8', 'auto');
        // Normalize line endings
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        return $value;
    }, $row);

    return $row;
}
?>