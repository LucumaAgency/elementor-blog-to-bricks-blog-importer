<?php
/**
 * Script de prueba para verificar la codificación UTF-8 del CSV
 */

// Configurar codificación UTF-8
header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');
ini_set('default_charset', 'UTF-8');

$csv_file = 'Entradas-Export-2025-September-17-1253.csv';

if (!file_exists($csv_file)) {
    die("Error: No se encuentra el archivo CSV.\n");
}

echo "<h2>Prueba de Codificación del CSV</h2>\n";
echo "<pre>\n";

// Abrir archivo
$handle = fopen($csv_file, 'r');

// Detectar BOM
$bom = fread($handle, 3);
if ($bom === "\xEF\xBB\xBF") {
    echo "✓ BOM UTF-8 detectado\n";
} else {
    echo "✗ No se detectó BOM UTF-8\n";
    rewind($handle);
}

// Leer headers
$headers = fgetcsv($handle, 0, ',', '"', '\\');
if ($headers) {
    // Convertir encoding si es necesario
    $headers = array_map(function($header) {
        return mb_convert_encoding($header, 'UTF-8', 'auto');
    }, $headers);

    echo "\n<strong>Encabezados detectados (" . count($headers) . " columnas):</strong>\n";

    // Mostrar solo los primeros 10 headers
    for ($i = 0; $i < min(10, count($headers)); $i++) {
        echo "  [$i] " . htmlspecialchars($headers[$i], ENT_QUOTES, 'UTF-8') . "\n";
    }

    // Verificar headers requeridos
    $has_title = false;
    $has_content = false;
    $title_index = -1;
    $content_index = -1;

    foreach ($headers as $index => $header) {
        if (stripos($header, 'Title') !== false && strlen($header) < 10) {
            $has_title = true;
            $title_index = $index;
        }
        if (stripos($header, 'Content') !== false) {
            $has_content = true;
            $content_index = $index;
        }
    }

    echo "\n<strong>Validación de estructura:</strong>\n";
    echo $has_title ? "✓ Columna 'Title' encontrada en índice $title_index\n" : "✗ Columna 'Title' NO encontrada\n";
    echo $has_content ? "✓ Columna 'Content' encontrada en índice $content_index\n" : "✗ Columna 'Content' NO encontrada\n";

    // Leer las primeras 5 filas para verificar títulos
    echo "\n<strong>Primeros 5 títulos del CSV (verificación de codificación):</strong>\n";
    $row_count = 0;
    while ($row_count < 5 && ($row = fgetcsv($handle, 0, ',', '"', '\\'))) {
        $row = array_map(function($value) {
            return mb_convert_encoding($value, 'UTF-8', 'auto');
        }, $row);

        if ($title_index >= 0 && isset($row[$title_index]) && !empty(trim($row[$title_index]))) {
            $row_count++;
            $title = $row[$title_index];

            // Mostrar título original
            echo "\nFila $row_count:\n";
            echo "  Original: " . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "\n";

            // Mostrar después de decodificar entidades HTML
            $decoded = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            echo "  Decodificado: " . htmlspecialchars($decoded, ENT_QUOTES, 'UTF-8') . "\n";

            // Verificar si contiene caracteres especiales
            if (preg_match('/[áéíóúñÑÁÉÍÓÚ¡¿""]/u', $decoded)) {
                echo "  ✓ Caracteres especiales detectados correctamente\n";
            }
        }
    }

} else {
    echo "Error: No se pudieron leer los encabezados del CSV\n";
}

fclose($handle);

echo "\n<strong>Prueba de las funciones nuevas:</strong>\n";

// Probar la función de validación
include_once('import-elementor-blog-to-bricks.php');

if (function_exists('fospibay_validate_csv_structure')) {
    $is_valid = fospibay_validate_csv_structure($csv_file);
    echo $is_valid ? "✓ El CSV pasó la validación de estructura\n" : "✗ El CSV NO pasó la validación\n";
} else {
    echo "✗ La función fospibay_validate_csv_structure no está disponible\n";
}

echo "</pre>\n";
echo "<p><strong>Prueba completada.</strong> Revise los resultados arriba.</p>\n";
?>