<?php

declare(strict_types=1);

use Core\Auth;
use Core\View;

/** @var string $actual  ruta de la pantalla que se está viendo */

/*
 * Navegación entre los tres reportes contables (D-59).
 *
 * La secretaria solo ve el arqueo, y con la barra de un solo enlace no queda
 * ninguna puerta que le vaya a dar 403 — el mismo criterio con el que el layout
 * esconde Instituciones y Usuarios.
 *
 * No se imprime: es navegación, no documento.
 */
$enlaces = ['/reportes/caja' => 'Arqueo de caja'];

if (Auth::esAdministrador()) {
    $enlaces['/reportes/rendicion']    = 'Rendición';
    $enlaces['/reportes/cobros']       = 'Cobros';
    $enlaces['/reportes/saldos']       = 'Estado de la caja';
    $enlaces['/reportes/devoluciones'] = 'Fondo de devoluciones';
}
?>
<?php if (count($enlaces) > 1): ?>
    <nav class="reporte-nav no-imprimir">
        <?php foreach ($enlaces as $ruta => $texto): ?>
            <a class="reporte-nav__enlace<?= $ruta === $actual ? ' reporte-nav__enlace--activo' : '' ?>"
               href="<?= View::e(View::url($ruta)) ?>"><?= View::e($texto) ?></a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>
