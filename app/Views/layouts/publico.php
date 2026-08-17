<?php

declare(strict_types=1);

use Core\View;

/**
 * Layout de las páginas públicas (la vista digital del carné).
 *
 * Deliberadamente sin barra de navegación ni referencia al sistema interno:
 * quien llega aquí escaneó un QR y no debe encontrar puertas al backoffice.
 */

/** @var string $contenido */
/** @var string $titulo */
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= View::e($titulo ?? '') ?></title>
    <link rel="stylesheet" href="<?= View::e(View::url('css/app.css')) ?>">
</head>
<body class="centrado">
    <main class="carne-publico">
        <?= $contenido ?>
    </main>
</body>
</html>
