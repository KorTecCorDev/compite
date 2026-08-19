<?php

declare(strict_types=1);

/**
 * Ejecuta todas las pruebas y devuelve un resumen.
 *
 *     php scripts/pruebas/todas.php
 *
 * Sale con código 0 si todas pasan, 1 si alguna falla. Cada prueba corre en su
 * **propio proceso**: comparten la misma base y cada una abre y revierte su
 * transacción, así que ejecutarlas juntas en un solo proceso las enredaría —y
 * además varias terminan con `exit()`, que cortaría el runner en la primera.
 *
 * Qué son estas pruebas y qué no:
 *
 *   · Corren contra la **base real de trabajo**, no contra una maqueta. Por eso
 *     detectan cosas que un doble no detecta: que MariaDB rellena un ENUM
 *     NOT NULL en vez de rechazar el INSERT, que la colación española ordena la
 *     Ñ donde debe, que el esquema tiene las columnas que el código espera.
 *   · **No dejan nada**: cada una revierte su transacción, y `_comun.php` la
 *     deshace igual si la prueba se cae a mitad.
 *   · **No sustituyen la comprobación en navegador.** Renderizan las vistas y
 *     miran el HTML, así que ven si falta un rótulo o si un enlace aparece a
 *     quien no debe; no ven si algo se sale de la pantalla ni si un botón queda
 *     debajo del teclado. Para eso está `docs/protocolo-pruebas.html`.
 */

if (PHP_SAPI !== 'cli') {
    exit("Las pruebas solo se ejecutan desde la consola.\n");
}

$archivos = glob(__DIR__ . '/*.php') ?: [];

// `_comun.php` es el arranque y este mismo archivo es el runner.
$archivos = array_values(array_filter(
    $archivos,
    static fn (string $f): bool => !in_array(basename($f), ['_comun.php', 'todas.php'], true)
));

sort($archivos);

$fallidas = [];
$inicio   = microtime(true);

foreach ($archivos as $archivo) {
    $nombre = basename($archivo, '.php');

    echo "\n" . str_repeat('=', 70) . "\n{$nombre}\n" . str_repeat('=', 70) . "\n";

    $salida = null;
    $codigo = 0;
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($archivo), $codigo);

    if ($codigo !== 0) {
        $fallidas[] = $nombre;
    }
}

$segundos = round(microtime(true) - $inicio, 1);

echo "\n" . str_repeat('=', 70) . "\n";
echo 'Resumen: ' . count($archivos) . " pruebas en {$segundos}s\n";

if ($fallidas === []) {
    echo "Todas pasan.\n";
    exit(0);
}

echo 'FALLAN ' . count($fallidas) . ": " . implode(', ', $fallidas) . "\n";
exit(1);
