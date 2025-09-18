<?php
require_once('wp-load.php');

$state = get_option('fospibay_import_state');
if ($state) {
    echo "Estado de importación actual:\n";
    echo "Archivo: " . ($state['file'] ?? 'no definido') . "\n";
    echo "Fila actual: " . ($state['row_index'] ?? 0) . "\n";
    echo "Total de filas: " . ($state['total_rows'] ?? 0) . "\n";
    echo "Offset: " . ($state['offset'] ?? 0) . "\n";
    echo "Importados: " . ($state['imported'] ?? 0) . "\n";
    echo "Actualizados: " . ($state['updated'] ?? 0) . "\n";
    echo "Omitidos: " . ($state['skipped'] ?? 0) . "\n";

    if (isset($state['file']) && file_exists($state['file'])) {
        echo "\n--- Verificación del archivo ---\n";
        echo "El archivo existe\n";
        $lines = count(file($state['file']));
        echo "Número de líneas en el archivo: " . $lines . "\n";

        // Lee las primeras 3 líneas
        $handle = fopen($state['file'], 'r');
        echo "\nPrimeras 3 líneas del archivo:\n";
        for ($i = 0; $i < 3 && !feof($handle); $i++) {
            $line = fgetcsv($handle, 0, $state['delimiter'] ?? ',');
            echo "Línea " . ($i + 1) . ": " . count($line) . " columnas\n";
            if ($i == 0) {
                echo "Headers: " . implode(', ', array_slice($line, 0, 10)) . "...\n";
            }
        }
        fclose($handle);
    } else {
        echo "\nEl archivo no existe o no está definido en el estado\n";
    }
} else {
    echo "No hay estado de importación guardado\n";
}