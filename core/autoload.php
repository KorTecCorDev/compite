<?php

declare(strict_types=1);

/**
 * Autoloader PSR-4 de respaldo.
 *
 * Composer es el autoloader oficial del proyecto. Este archivo existe solo
 * para que el sistema arranque cuando `vendor/` todavía no está instalado
 * (por ejemplo, en un clon recién bajado o mientras se resuelven extensiones
 * de PHP pendientes).
 *
 * Cubre únicamente el código propio (App\ y Core\). Las librerías externas
 * — PhpSpreadsheet, Dompdf, endroid/qr-code — requieren Composer sí o sí.
 */

spl_autoload_register(static function (string $clase): void {
    $prefijos = [
        'App\\'  => __DIR__ . '/../app/',
        'Core\\' => __DIR__ . '/',
    ];

    foreach ($prefijos as $prefijo => $directorio) {
        if (!str_starts_with($clase, $prefijo)) {
            continue;
        }

        $relativa = substr($clase, strlen($prefijo));
        $archivo  = $directorio . str_replace('\\', '/', $relativa) . '.php';

        if (is_file($archivo)) {
            require $archivo;
        }

        return;
    }
});
