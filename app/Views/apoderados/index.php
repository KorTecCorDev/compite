<?php

declare(strict_types=1);

use Core\Auth;
use Core\Sesion;
use Core\View;

/** @var array<int, array<string, mixed>> $apoderados */
/** @var string $busqueda */
/** @var int $total */
?>
<div class="encabezado">
    <div>
        <h1 class="titulo">Apoderados</h1>
        <p class="subtitulo">
            Apoderados de estudiantes libres y encargados de delegación ·
            <?= (int) $total ?> registrado<?= $total === 1 ? '' : 's' ?>
        </p>
    </div>
    <a class="boton boton--principal" href="<?= View::e(View::url('/apoderados/nuevo')) ?>">
        Nuevo apoderado
    </a>
</div>

<form method="get" action="<?= View::e(View::url('/apoderados')) ?>" class="filtros">
    <input type="search" name="q" placeholder="Buscar por DNI, apellidos o nombres…"
           value="<?= View::e($busqueda) ?>">
    <button type="submit" class="boton boton--tenue">Buscar</button>
    <?php if ($busqueda !== ''): ?>
        <a class="enlace-tenue" href="<?= View::e(View::url('/apoderados')) ?>">Limpiar</a>
    <?php endif; ?>
</form>

<?php if ($apoderados === []): ?>

    <div class="vacio">
        <?php if ($busqueda !== ''): ?>
            Ningún apoderado coincide con la búsqueda.
        <?php else: ?>
            Todavía no hay apoderados registrados.
            <a href="<?= View::e(View::url('/apoderados/nuevo')) ?>">Registra el primero</a>.
        <?php endif; ?>
    </div>

<?php else: ?>

    <div class="tabla-contenedor">
        <table class="tabla">
            <thead>
                <tr>
                    <th>DNI</th>
                    <th>Apellidos y nombres</th>
                    <th>Modalidad</th>
                    <th>Celular</th>
                    <th>Estudiantes</th>
                    <th class="tabla__acciones">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($apoderados as $a): ?>
                <tr>
                    <td data-etiqueta="DNI"><code><?= View::e($a['dni']) ?></code></td>
                    <td class="tabla__principal">
                        <strong><?= View::e($a['ap_paterno'] . ' ' . $a['ap_materno']) ?></strong>,
                        <?= View::e($a['nombres']) ?>
                    </td>
                    <?php
                    /*
                     * Modalidad: no es excluyente. El docente que encabeza la
                     * delegación de su colegio puede además haber inscrito a su
                     * propio hijo como estudiante libre, y entonces lleva las
                     * dos etiquetas. Un apoderado recién dado de alta todavía no
                     * lleva ninguna, y verlo así es útil: es alguien que se
                     * registró y todavía no se usó.
                     */
                    $delegaciones = (int) $a['delegaciones'];
                    $libres       = (int) $a['estudiantes_libres'];
                    ?>
                    <td data-etiqueta="Modalidad">
                        <?php if ($delegaciones > 0): ?>
                            <span class="etiqueta">Encargado de delegación<?= $delegaciones > 1 ? ' (' . $delegaciones . ')' : '' ?></span>
                        <?php endif; ?>

                        <?php if ($libres > 0): ?>
                            <span class="etiqueta etiqueta--neutra">Apoderado libre</span>
                        <?php endif; ?>

                        <?php if ($delegaciones === 0 && $libres === 0): ?>
                            <span class="tenue">Sin vincular</span>
                        <?php endif; ?>
                    </td>
                    <td class="tenue" data-etiqueta="Celular"><?= View::e($a['celular']) ?></td>
                    <td data-etiqueta="Estudiantes">
                        <?php $n = (int) $a['estudiantes']; ?>
                        <span class="etiqueta<?= $n > 1 ? '' : ' etiqueta--neutra' ?>">
                            <?= $n ?> vinculado<?= $n === 1 ? '' : 's' ?>
                        </span>
                    </td>
                    <td class="tabla__acciones" data-etiqueta="Acciones">
                        <a class="enlace-tenue"
                           href="<?= View::e(View::url('/apoderados/' . $a['id'] . '/editar')) ?>">Editar</a>

                        <?php if (Auth::esAdministrador() && $n === 0): ?>
                            <form method="post"
                                  action="<?= View::e(View::url('/apoderados/' . $a['id'] . '/eliminar')) ?>"
                                  onsubmit="return confirm('¿Eliminar a este apoderado?');">
                                <input type="hidden" name="_csrf" value="<?= View::e(Sesion::tokenCsrf()) ?>">
                                <button type="submit" class="enlace-peligro">Eliminar</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php endif; ?>
