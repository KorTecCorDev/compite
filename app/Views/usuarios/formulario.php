<?php

declare(strict_types=1);

use Core\Sesion;
use Core\View;

/** @var array<string, mixed>|null $usuario */
/** @var array<string, mixed> $valores */
/** @var array<string, string> $errores */

$esNuevo = $usuario === null;
$accion  = $esNuevo ? '/usuarios' : '/usuarios/' . $usuario['id'];

$err = static fn (string $campo): string => isset($errores[$campo]) ? ' campo--error' : '';
$msg = static fn (string $campo): string => isset($errores[$campo])
    ? '<span class="campo__error">' . View::e($errores[$campo]) . '</span>'
    : '';
$val = static fn (string $campo): string => View::e((string) ($valores[$campo] ?? ''));
?>
<div class="encabezado">
    <div>
        <h1 class="titulo"><?= $esNuevo ? 'Nuevo usuario' : 'Editar usuario' ?></h1>
        <?php if (!$esNuevo): ?>
            <p class="subtitulo"><?= View::e((string) $usuario['correo']) ?></p>
        <?php endif; ?>
    </div>
    <a class="boton boton--tenue" href="<?= View::e(View::url('/usuarios')) ?>">Volver</a>
</div>

<?php if ($errores !== []): ?>
    <div class="aviso aviso--error">
        <ul class="lista-errores">
            <?php foreach ($errores as $mensaje): ?>
                <li><?= View::e($mensaje) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="<?= View::e(View::url($accion)) ?>" class="formulario-largo">
    <input type="hidden" name="_csrf" value="<?= View::e(Sesion::tokenCsrf()) ?>">

    <fieldset class="grupo">
        <legend class="grupo__titulo">Datos de acceso</legend>

        <div class="rejilla">
            <label class="campo<?= $err('nombres') ?>">
                <span class="campo__etiqueta">Nombres y apellidos *</span>
                <input type="text" name="nombres" maxlength="150" required value="<?= $val('nombres') ?>">
                <span class="campo__ayuda">Es el nombre que aparecerá como responsable de cada inscripción.</span>
                <?= $msg('nombres') ?>
            </label>

            <label class="campo<?= $err('correo') ?>">
                <span class="campo__etiqueta">Correo *</span>
                <input type="email" name="correo" maxlength="150" required value="<?= $val('correo') ?>">
                <span class="campo__ayuda">Con esto inicia sesión. No se envía nada a esa dirección.</span>
                <?= $msg('correo') ?>
            </label>

            <label class="campo<?= $err('rol') ?>">
                <span class="campo__etiqueta">Rol *</span>
                <select name="rol" required>
                    <option value="secretaria" <?= ($valores['rol'] ?? '') === 'secretaria' ? 'selected' : '' ?>>
                        Secretaria
                    </option>
                    <option value="administrador" <?= ($valores['rol'] ?? '') === 'administrador' ? 'selected' : '' ?>>
                        Administrador
                    </option>
                </select>
                <span class="campo__ayuda">
                    La secretaria inscribe, cobra, anula y reinscribe. El administrador hace
                    además lo de esta pantalla y la gestión de concurso, tarifas e instituciones.
                </span>
                <?= $msg('rol') ?>
            </label>
        </div>
    </fieldset>

    <?php if ($esNuevo): ?>
        <fieldset class="grupo">
            <legend class="grupo__titulo">Contraseña</legend>
            <p class="grupo__ayuda">
                Mínimo 8 caracteres. <strong>Entrégasela en persona</strong>: no queda guardada en
                ningún sitio legible, así que nadie —tú incluido— puede recuperarla después; solo
                asignar una nueva desde aquí.
            </p>

            <div class="rejilla">
                <label class="campo<?= $err('password') ?>">
                    <span class="campo__etiqueta">Contraseña *</span>
                    <input type="password" name="password" minlength="8" required autocomplete="new-password">
                    <?= $msg('password') ?>
                </label>

                <label class="campo">
                    <span class="campo__etiqueta">Repetir contraseña *</span>
                    <input type="password" name="password2" minlength="8" required autocomplete="new-password">
                </label>
            </div>
        </fieldset>
    <?php endif; ?>

    <div class="acciones">
        <button type="submit" class="boton boton--principal">
            <?= $esNuevo ? 'Crear usuario' : 'Guardar cambios' ?>
        </button>
        <a class="boton boton--tenue" href="<?= View::e(View::url('/usuarios')) ?>">Cancelar</a>
    </div>
</form>

<?php if (!$esNuevo): ?>
    <?php
    /*
     * Formulario aparte, y no un campo más del de arriba: guardar un cambio de
     * nombre no puede tocar la contraseña por descuido. Va fuera del <form>
     * anterior porque anidar formularios no es HTML válido —el navegador ignora
     * la etiqueta interna y cierra el externo antes de tiempo, que es el fallo
     * que ya apagó la caja de cobro una vez (D-29).
     */
    ?>
    <form method="post" action="<?= View::e(View::url('/usuarios/' . $usuario['id'] . '/password')) ?>"
          class="formulario-largo">
        <input type="hidden" name="_csrf" value="<?= View::e(Sesion::tokenCsrf()) ?>">

        <fieldset class="grupo">
            <legend class="grupo__titulo">Cambiar la contraseña</legend>
            <p class="grupo__ayuda">
                No hace falta la anterior: para esto sirve esta pantalla, para cuando se olvidó o
                se filtró. Mínimo 8 caracteres, y entrégasela en persona.
            </p>

            <div class="rejilla">
                <label class="campo<?= $err('password') ?>">
                    <span class="campo__etiqueta">Contraseña nueva *</span>
                    <input type="password" name="password" minlength="8" required autocomplete="new-password">
                    <?= $msg('password') ?>
                </label>

                <label class="campo">
                    <span class="campo__etiqueta">Repetirla *</span>
                    <input type="password" name="password2" minlength="8" required autocomplete="new-password">
                </label>
            </div>

            <div class="acciones acciones--espaciado">
                <button type="submit" class="boton boton--principal">Cambiar contraseña</button>
            </div>
        </fieldset>
    </form>
<?php endif; ?>
