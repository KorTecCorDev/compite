<?php

declare(strict_types=1);

use Core\Sesion;
use Core\View;

/** @var string $contenido */
/** @var string $titulo */

$flash = Sesion::tomarFlash();
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($titulo ?? '') ?> · COCIAP 2026</title>
    <link rel="stylesheet" href="<?= View::e(View::url('css/app.css')) ?>">
</head>
<body class="centrado">

<main class="tarjeta">
    <?php foreach ($flash as $tipo => $mensajes): ?>
        <?php foreach ($mensajes as $mensaje): ?>
            <div class="aviso aviso--<?= View::e($tipo) ?>"><?= View::e($mensaje) ?></div>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <?= $contenido ?>
</main>

</body>
</html>
