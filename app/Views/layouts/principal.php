<?php

declare(strict_types=1);

use Core\Auth;
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
    <link rel="stylesheet" href="<?= View::e(View::url('build/css/app.css')) ?>">
</head>
<body>

<header class="barra">
    <a class="barra__marca" href="<?= View::e(View::url('/panel')) ?>">COCIAP&nbsp;2026</a>

    <?php
    $rutaActual = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '';
    $enlaces = [
        '/panel'          => 'Panel',
        '/inscripciones'  => 'Inscripciones',
        '/instituciones'  => 'Instituciones',
        '/apoderados'     => 'Apoderados',
        '/control'        => 'Control de ingreso',
    ];
    ?>
    <nav class="barra__menu">
        <?php foreach ($enlaces as $ruta => $texto): ?>
            <a class="barra__enlace<?= str_contains($rutaActual, $ruta) ? ' barra__enlace--activo' : '' ?>"
               href="<?= View::e(View::url($ruta)) ?>"><?= View::e($texto) ?></a>
        <?php endforeach; ?>
    </nav>

    <nav class="barra__nav">
        <span class="barra__usuario">
            <?= View::e(Auth::nombres()) ?>
            <em class="etiqueta"><?= View::e(Auth::rol()) ?></em>
        </span>

        <form method="post" action="<?= View::e(View::url('/salir')) ?>">
            <input type="hidden" name="_csrf" value="<?= View::e(Sesion::tokenCsrf()) ?>">
            <button type="submit" class="boton boton--tenue">Salir</button>
        </form>
    </nav>
</header>

<main class="contenido">
    <?php /*
       Los avisos del resultado de una acción van en una franja pegajosa: al
       desplazarse por una tabla larga o un formulario de veinte campos, el
       mensaje seguía existiendo pero fuera de la pantalla. Se cierran a mano y
       no solos: aquí se confirman cobros, y un mensaje de dinero que se
       desvanece a los tres segundos es un mensaje que alguien no leyó. Ver D-30.
    */ ?>
    <?php if ($flash !== []): ?>
        <div class="avisos" id="avisos-flash">
            <?php foreach ($flash as $tipo => $mensajes): ?>
                <?php foreach ($mensajes as $mensaje): ?>
                    <div class="aviso aviso--<?= View::e($tipo) ?>"
                         role="<?= $tipo === 'error' ? 'alert' : 'status' ?>">
                        <span class="aviso__texto"><?= View::e($mensaje) ?></span>
                        <button type="button" class="aviso__cerrar" aria-label="Cerrar aviso">&times;</button>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?= $contenido ?>
</main>

<footer class="pie">
    I.E. Víctor Valenzuela Guardia · IV Concurso Regional de Conocimientos
</footer>

<script src="<?= View::e(View::url('build/js/avisos.js')) ?>" defer></script>

</body>
</html>
