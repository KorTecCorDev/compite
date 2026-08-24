<?php

declare(strict_types=1);

use Core\View;

/**
 * La única pantalla que responde con el sitio cerrado (D-65).
 *
 * Va SIN layout a propósito. `layouts/limpio` arranca llamando a
 * `Sesion::tomarFlash()`, y el cierre ocurre antes de que la sesión exista: un
 * visitante bloqueado no tiene por qué llevarse una cookie. Envolverla en ese
 * layout obligaría a abrir sesión solo para poder decir que no hay servicio.
 *
 * Tampoco enlaza a ninguna parte: con el bloqueo total no queda ni una ruta
 * viva a la que mandar a nadie, y un botón que devuelve a esta misma página es
 * peor que ningún botón.
 *
 * @var string $titulo
 */
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($titulo ?? 'Sitio en mantenimiento') ?> · COCIAP 2026</title>

    <?php /*
        Estilos mínimos EN LÍNEA, antes de la hoja compilada y no en su lugar.

        `public/build/` está versionado y Apache lo sirve como archivo estático,
        sin pasar por el front controller, así que con el sitio cerrado la hoja
        sigue cargando y estas reglas quedan sobrescritas por las de verdad.

        Están igual porque esta es la página que no puede salir mal: si algún
        día se despliega sin compilar los assets, o el CDN devuelve un 404 de su
        caché, lo que se ve sigue siendo un aviso legible y centrado y no texto
        negro pegado a la esquina. Son cuatro reglas de encuadre; el aspecto lo
        pone `app.css`.
    */ ?>
    <style>
        body { margin: 0; background: #f4f6f9; color: #1b2430;
               font: 16px/1.55 "Segoe UI", system-ui, -apple-system, sans-serif;
               display: flex; align-items: center; justify-content: center;
               min-height: 100vh; padding: 1.5rem; }
        main { background: #fff; border: 1px solid #dfe4ea; border-radius: 10px;
               padding: 1.5rem; width: 100%; max-width: 380px; }
        img  { height: 3.4rem; width: auto; }
    </style>

    <link rel="stylesheet" href="<?= View::e(View::asset('build/css/app.css')) ?>">
</head>
<body class="centrado">

<main class="tarjeta">
    <img src="<?= View::e(View::url('img/logo-cociap.png')) ?>" alt="" style="margin-bottom:1rem">

    <h1 class="titulo">Sitio en mantenimiento</h1>

    <p class="subtitulo">
        El sistema de inscripciones del COCIAP 2026 no se encuentra disponible
        por el momento.
    </p>

    <p class="nota">
        Estamos realizando tareas de mantenimiento. Vuelve a intentarlo más
        tarde.
    </p>
</main>

</body>
</html>
