<?php

declare(strict_types=1);

$sqlFile = dirname(__DIR__) . '/database/rh_demo.sql';
$sql = file_get_contents($sqlFile);

if (!is_string($sql) || $sql === '') {
    fwrite(STDERR, "No se pudo leer el archivo SQL.\n");
    exit(1);
}

$mysqli = new mysqli('127.0.0.1', 'root', '', '', 3306);

if ($mysqli->connect_errno) {
    fwrite(STDERR, "Error de conexion: {$mysqli->connect_error}\n");
    exit(1);
}

if (!$mysqli->multi_query($sql)) {
    fwrite(STDERR, "Error ejecutando seed: {$mysqli->error}\n");
    exit(1);
}

do {
    if ($result = $mysqli->store_result()) {
        $result->free();
    }
} while ($mysqli->more_results() && $mysqli->next_result());

if ($mysqli->errno) {
    fwrite(STDERR, "Error posterior al seed: {$mysqli->error}\n");
    exit(1);
}

echo "Base de datos botlocal_rh_demo creada correctamente.\n";
