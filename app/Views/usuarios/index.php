<?php

declare(strict_types=1);

use Core\Sesion;
use Core\View;

/** @var array<int, array<string, mixed>> $usuarios */
/** @var int $yo */
?>
<div class="encabezado">
    <div>
        <h1 class="titulo">Usuarios</h1>
        <p class="subtitulo">
            Un acceso por persona · <?= count($usuarios) ?> registrado<?= count($usuarios) === 1 ? '' : 's' ?>
        </p>
    </div>
    <a class="boton boton--principal" href="<?= View::e(View::url('/usuarios/nuevo')) ?>">
        Nuevo usuario
    </a>
</div>

<div class="aviso aviso--aviso">
    Cada inscripción guarda <strong>quién la registró</strong>, y cada cobro y cada anulación
    guardan quién los hizo. Por eso los usuarios se <strong>desactivan</strong> y no se borran:
    si se borraran, esas firmas se quedarían sin dueño.
</div>

<div class="tabla-contenedor">
    <table class="tabla">
        <thead>
            <tr>
                <th>Nombre</th>
                <th>Correo</th>
                <th>Rol</th>
                <th>Acceso</th>
                <th class="tabla__acciones">Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($usuarios as $u): ?>
            <?php $activo = (bool) $u['activo']; $esYo = (int) $u['id'] === $yo; ?>
            <tr>
                <td>
                    <strong><?= View::e($u['nombres']) ?></strong>
                    <?php if ($esYo): ?>
                        <span class="etiqueta etiqueta--neutra">tú</span>
                    <?php endif; ?>
                </td>
                <td class="tenue"><?= View::e($u['correo']) ?></td>
                <td>
                    <span class="etiqueta <?= $u['rol'] === 'administrador' ? 'etiqueta--organizadora' : 'etiqueta--neutra' ?>">
                        <?= $u['rol'] === 'administrador' ? 'administrador' : 'secretaria' ?>
                    </span>
                </td>
                <td>
                    <span class="etiqueta etiqueta--estado-<?= $activo ? 'confirmada' : 'anulada' ?>">
                        <?= $activo ? 'activo' : 'sin acceso' ?>
                    </span>
                </td>
                <td class="tabla__acciones">
                    <a class="enlace-tenue"
                       href="<?= View::e(View::url('/usuarios/' . $u['id'] . '/editar')) ?>">
                        Editar / contraseña
                    </a>

                    <?php if (!$esYo): ?>
                        <form method="post"
                              action="<?= View::e(View::url('/usuarios/' . $u['id'] . '/estado')) ?>">
                            <input type="hidden" name="_csrf" value="<?= View::e(Sesion::tokenCsrf()) ?>">
                            <input type="hidden" name="activo" value="<?= $activo ? '0' : '1' ?>">
                            <button type="submit" class="<?= $activo ? 'enlace-peligro' : 'enlace-tenue enlace-boton' ?>">
                                <?= $activo ? 'Quitar acceso' : 'Devolver acceso' ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
