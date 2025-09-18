<?php
/**
 * Script de prueba para verificar el estado de la importación
 */

// Simular entorno WordPress mínimo
if (!defined('ABSPATH')) {
    define('ABSPATH', '/');
}

// Funciones simuladas de WordPress
if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        $file = __DIR__ . '/wp_options_' . $name . '.txt';
        if (file_exists($file)) {
            return unserialize(file_get_contents($file));
        }
        return $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($name, $value) {
        $file = __DIR__ . '/wp_options_' . $name . '.txt';
        file_put_contents($file, serialize($value));
        return true;
    }
}

// Obtener estado actual
$state = get_option('fospibay_import_state', false);

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Test Import Status</title>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .status-box {
            background: white;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .progress-bar {
            background: #e0e0e0;
            height: 30px;
            border-radius: 15px;
            overflow: hidden;
            margin: 10px 0;
        }
        .progress-fill {
            background: #4CAF50;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            transition: width 0.3s;
        }
        .stat {
            display: inline-block;
            margin: 10px 20px 10px 0;
            padding: 10px;
            background: #f9f9f9;
            border-radius: 3px;
        }
        .stat strong {
            color: #333;
        }
        pre {
            background: #f4f4f4;
            padding: 10px;
            border-radius: 3px;
            overflow-x: auto;
        }
        .error {
            color: red;
            font-weight: bold;
        }
        .success {
            color: green;
            font-weight: bold;
        }
        button {
            background: #0073aa;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            margin-right: 10px;
        }
        button:hover {
            background: #005177;
        }
    </style>
</head>
<body>
    <h1>Estado de Importación CSV</h1>

    <?php if (!$state): ?>
        <div class="status-box">
            <p class="error">No hay importación activa o no se puede leer el estado.</p>
            <button onclick="createTestState()">Crear Estado de Prueba</button>
        </div>
    <?php else: ?>
        <div class="status-box">
            <h2>Progreso Actual</h2>
            <?php
            $progress = 0;
            if (isset($state['total_rows']) && $state['total_rows'] > 0) {
                $progress = (($state['row_index'] - 2) / ($state['total_rows'] - 1)) * 100;
            }
            ?>
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?php echo min(100, max(0, $progress)); ?>%">
                    <?php echo round($progress); ?>%
                </div>
            </div>

            <div>
                <div class="stat">
                    <strong>Archivo:</strong> <?php echo basename($state['file'] ?? 'Desconocido'); ?>
                </div>
                <div class="stat">
                    <strong>Existe:</strong> <?php echo (isset($state['file']) && file_exists($state['file'])) ? '<span class="success">Sí</span>' : '<span class="error">No</span>'; ?>
                </div>
                <div class="stat">
                    <strong>Fila Actual:</strong> <?php echo $state['row_index'] ?? 0; ?> / <?php echo $state['total_rows'] ?? 0; ?>
                </div>
                <div class="stat">
                    <strong>Creadas:</strong> <?php echo $state['imported'] ?? 0; ?>
                </div>
                <div class="stat">
                    <strong>Actualizadas:</strong> <?php echo $state['updated'] ?? 0; ?>
                </div>
                <div class="stat">
                    <strong>Omitidas:</strong> <?php echo $state['skipped'] ?? 0; ?>
                </div>
                <div class="stat">
                    <strong>Imágenes:</strong> <?php echo $state['images_downloaded'] ?? 0; ?>
                </div>
                <div class="stat">
                    <strong>Tamaño Lote:</strong> <?php echo $state['batch_size'] ?? 'N/A'; ?>
                </div>
                <div class="stat">
                    <strong>Debug Mode:</strong> <?php echo (!empty($state['debug_mode'])) ? '<span class="success">ON</span>' : 'OFF'; ?>
                </div>
            </div>

            <?php if (!empty($state['current_title'])): ?>
                <div class="stat" style="width: 100%; margin-top: 10px;">
                    <strong>Último Título:</strong> <?php echo htmlspecialchars($state['current_title']); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($state['debug_messages'])): ?>
                <h3>Últimos Mensajes Debug:</h3>
                <pre><?php
                foreach (array_slice($state['debug_messages'], -10) as $msg) {
                    echo strip_tags($msg) . "\n";
                }
                ?></pre>
            <?php endif; ?>
        </div>

        <div class="status-box">
            <h2>Estado Completo (Debug)</h2>
            <pre><?php print_r($state); ?></pre>
        </div>

        <div class="status-box">
            <button onclick="location.reload()">Actualizar</button>
            <button onclick="resetState()">Reiniciar Estado</button>
            <button onclick="simulateProgress()">Simular Progreso</button>
        </div>
    <?php endif; ?>

    <div class="status-box">
        <h2>Verificar CSV</h2>
        <?php
        $csv_file = __DIR__ . '/Entradas-Export-2025-September-17-1253.csv';
        if (file_exists($csv_file)):
            $handle = fopen($csv_file, 'r');
            $bom = fread($handle, 3);
            rewind($handle);
            if ($bom === "\xEF\xBB\xBF") {
                fread($handle, 3); // Skip BOM
            }
            $headers = fgetcsv($handle, 0, ',');
            $row_count = 1;
            while (fgetcsv($handle, 0, ',') !== false && $row_count < 6) {
                $row_count++;
            }
            fclose($handle);
        ?>
            <p class="success">✓ Archivo CSV encontrado</p>
            <p><strong>Encabezados:</strong> <?php echo count($headers); ?> columnas</p>
            <p><strong>Primeras columnas:</strong> <?php echo implode(', ', array_slice($headers, 0, 5)); ?>...</p>
        <?php else: ?>
            <p class="error">✗ Archivo CSV no encontrado</p>
        <?php endif; ?>
    </div>

    <script>
    function resetState() {
        if (confirm('¿Seguro que quieres reiniciar el estado de importación?')) {
            // En un entorno real, esto haría una llamada AJAX
            alert('En producción, esto llamaría a un endpoint para reiniciar el estado');
        }
    }

    function simulateProgress() {
        alert('Simulando progreso - en producción esto actualizaría el estado');
    }

    function createTestState() {
        alert('Creando estado de prueba - en producción esto inicializaría una importación');
    }

    // Auto-refresh every 3 seconds
    setTimeout(() => {
        location.reload();
    }, 3000);
    </script>
</body>
</html>