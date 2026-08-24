<?php

declare(strict_types=1);

use Core\Auth;
use Core\Sesion;
use Core\View;

/** @var string $contenido */
/** @var string $titulo */
/** @var bool|null $columnaAncha */

/*
 * Las pantallas que muestran una tabla de datos piden la columna ancha con
 * `'columnaAncha' => true`. Va como dato de la vista y no deducido del nombre de
 * la plantilla: quien añada un listado nuevo tiene que decidirlo, no heredarlo
 * por parecido.
 */
$claseContenido = 'contenido' . (!empty($columnaAncha) ? ' contenido--ancho' : '');

$flash = Sesion::tomarFlash();
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($titulo ?? '') ?> · COCIAP 2026</title>
    <link rel="stylesheet" href="<?= View::e(View::asset('build/css/app.css')) ?>">
</head>
<body>

<header class="barra">
    <a class="barra__marca" href="<?= View::e(View::url('/panel')) ?>">COCIAP&nbsp;2026</a>

    <?php
    $rutaActual = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '';
    /*
     * «Caja» lleva al arqueo (D-59), y lo ven los dos roles: la secretaria solo
     * encuentra ahí lo que cobró ella —es su cierre, el papel con el que
     * entrega el dinero— y el administrador, las tres cajas más los enlaces a
     * las otras dos pantallas contables. Un solo enlace en la barra en vez de
     * tres: las otras dos son de dirección y se llega a ellas desde dentro.
     */
    $enlaces = [
        '/panel'          => 'Panel',
        '/inscripciones'  => 'Inscripciones',
        '/apoderados'     => 'Apoderados',
        '/control'        => 'Control de ingreso',
        '/reportes/caja'  => 'Caja',
    ];

    /*
     * Instituciones y Usuarios son administrativas (§3 y §7 del plan): el
     * catálogo de colegios es global y compartido, y darlo de alta mal afecta a
     * la tarifa y a la bolsa de competencia de toda una delegación.
     *
     * Se ocultan además de estar protegidas en el controlador: enseñarle a la
     * secretaria una puerta que le va a dar 403 no ayuda a nadie.
     */
    if (Auth::esAdministrador()) {
        $enlaces['/instituciones'] = 'Instituciones';
        $enlaces['/usuarios']      = 'Usuarios';
    }
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

<main class="<?= View::e($claseContenido) ?>">
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
                    <div class="aviso aviso--cerrable aviso--<?= View::e($tipo) ?>"
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

<script src="<?= View::e(View::asset('build/js/avisos.js')) ?>" defer></script>

<?php
/*
 * Sprite de íconos (D-48). Va aquí, al final del layout y no dentro de cada
 * vista, por dos razones: se imprime una sola vez por página aunque la tabla
 * tenga 300 filas, y queda disponible para cualquier otra pantalla que después
 * quiera los mismos íconos sin volver a pegarlos.
 */
?>
<?= View::parcial('iconos') ?>

</body>
</html>
