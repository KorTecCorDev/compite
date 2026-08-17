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
            Solo para estudiantes libres ·
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
                    <th>Celular</th>
                    <th>Estudiantes</th>
                    <th class="tabla__acciones">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($apoderados as $a): ?>
                <tr>
                    <td><code><?= View::e($a['dni']) ?></code></td>
                    <td>
                        <strong><?= View::e($a['ap_paterno'] . ' ' . $a['ap_materno']) ?></strong>,
                        <?= View::e($a['nombres']) ?>
                    </td>
                    <td class="tenue"><?= View::e($a['celular']) ?></td>
                    <td>
                        <?php $n = (int) $a['estudiantes']; ?>
                        <span class="etiqueta<?= $n > 1 ? '' : ' etiqueta--neutra' ?>">
                            <?= $n ?> vinculado<?= $n === 1 ? '' : 's' ?>
                        </span>
                    </td>
                    <td class="tabla__acciones">
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
