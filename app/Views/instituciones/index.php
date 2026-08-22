<?php

declare(strict_types=1);

use Core\Auth;
use Core\Sesion;
use Core\View;

/** @var array<int, array<string, mixed>> $instituciones */
/** @var string $busqueda */
/** @var string $tipo */
/** @var int $total */
/** @var int|null $anfitriona */
?>
<div class="encabezado">
    <div>
        <h1 class="titulo">Instituciones Educativas</h1>
        <p class="subtitulo">
            Catálogo compartido · <?= (int) $total ?> registrada<?= $total === 1 ? '' : 's' ?>
        </p>
    </div>
    <a class="boton boton--principal" href="<?= View::e(View::url('/instituciones/nueva')) ?>">
        Nueva institución
    </a>
</div>

<form method="get" action="<?= View::e(View::url('/instituciones')) ?>" class="filtros">
    <input type="search" name="q" placeholder="Buscar por nombre o distrito…"
           value="<?= View::e($busqueda) ?>">

    <select name="tipo">
        <option value="">Toda gestión</option>
        <option value="publica" <?= $tipo === 'publica' ? 'selected' : '' ?>>Pública</option>
        <option value="privada" <?= $tipo === 'privada' ? 'selected' : '' ?>>Privada</option>
    </select>

    <button type="submit" class="boton boton--tenue">Buscar</button>

    <?php if ($busqueda !== '' || $tipo !== ''): ?>
        <a class="enlace-tenue" href="<?= View::e(View::url('/instituciones')) ?>">Limpiar</a>
    <?php endif; ?>
</form>

<?php if ($instituciones === []): ?>

    <div class="vacio">
        <?php if ($busqueda !== '' || $tipo !== ''): ?>
            No hay instituciones que coincidan con la búsqueda.
        <?php else: ?>
            Todavía no hay instituciones registradas.
            <a href="<?= View::e(View::url('/instituciones/nueva')) ?>">Registra la primera</a>.
        <?php endif; ?>
    </div>

<?php else: ?>

    <div class="tabla-contenedor">
        <table class="tabla">
            <thead>
                <tr>
                    <th>Institución Educativa</th>
                    <th>Gestión</th>
                    <th>Ubicación</th>
                    <th class="tabla__acciones">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($instituciones as $ie): ?>
                <tr>
                    <td class="tabla__principal mayus"><strong><?= View::e($ie['nombre']) ?></strong></td>
                    <td data-etiqueta="Gestión">
                        <?php
                        /* El anfitrión lleva SU papel, no su gestión (D-37). Es de
                           gestión pública y eso sigue guardado, pero mostrarlo aquí
                           como «pública» hacía leer que cobra la tarifa de las
                           públicas, y cobra la suya. */
                        ?>
                        <?php if ($anfitriona !== null && (int) $ie['id'] === $anfitriona): ?>
                            <span class="etiqueta etiqueta--organizadora"
                                  title="Organiza el concurso. Sus estudiantes se inscriben en la modalidad COCIAP.">
                                ANFITRIÓN
                            </span>
                        <?php else: ?>
                            <span class="etiqueta etiqueta--<?= View::e($ie['tipo']) ?>">
                                <?= $ie['tipo'] === 'publica' ? 'pública' : 'privada' ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="tenue" data-etiqueta="Ubicación">
                        <?= View::e($ie['distrito']) ?>,
                        <?= View::e($ie['provincia']) ?>,
                        <?= View::e($ie['departamento']) ?>
                    </td>
                    <td class="tabla__acciones" data-etiqueta="Acciones">
                        <a class="enlace-tenue"
                           href="<?= View::e(View::url('/instituciones/' . $ie['id'] . '/editar')) ?>">Editar</a>

                        <?php if (Auth::esAdministrador()): ?>
                            <form method="post"
                                  action="<?= View::e(View::url('/instituciones/' . $ie['id'] . '/eliminar')) ?>"
                                  onsubmit="return confirm('¿Eliminar «<?= View::e($ie['nombre']) ?>» del catálogo?');">
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
