<?php

declare(strict_types=1);

use Core\Sesion;
use Core\View;

/** @var array<string, mixed>|null $apoderado */
/** @var array<string, mixed> $valores */
/** @var array<string, string> $errores */

$esNuevo = $apoderado === null;
$accion  = $esNuevo
    ? View::url('/apoderados')
    : View::url('/apoderados/' . $apoderado['id']);

$v   = static fn (string $c): string => View::e($valores[$c] ?? '');
$err = static fn (string $c): string => isset($errores[$c]) ? ' campo--error' : '';
$msg = static fn (string $c): string => isset($errores[$c])
    ? '<span class="campo__error">' . View::e($errores[$c]) . '</span>'
    : '';
?>
<div class="encabezado">
    <div>
        <h1 class="titulo"><?= $esNuevo ? 'Nuevo apoderado' : 'Editar apoderado' ?></h1>
        <p class="subtitulo">
            Un mismo apoderado puede quedar vinculado a varios estudiantes libres
            —el caso típico son hermanos—, así que conviene reutilizarlo en vez de duplicarlo.
        </p>
    </div>
    <a class="boton boton--tenue" href="<?= View::e(View::url('/apoderados')) ?>">Volver</a>
</div>

<?php if ($errores !== []): ?>
    <div class="aviso aviso--error">
        <strong>Revisa <?= count($errores) ?> campo<?= count($errores) === 1 ? '' : 's' ?>:</strong>
        <ul class="lista-errores">
            <?php foreach ($errores as $mensaje): ?>
                <li><?= View::e($mensaje) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="<?= View::e($accion) ?>" class="formulario-largo">
    <input type="hidden" name="_csrf" value="<?= View::e(Sesion::tokenCsrf()) ?>">

    <fieldset class="grupo">
        <legend class="grupo__titulo">Datos del apoderado</legend>

        <div class="rejilla">
            <label class="campo<?= $err('dni') ?>">
                <span class="campo__etiqueta">DNI o C.E. *</span>
                <input type="text" name="dni" maxlength="12" required
                       value="<?= $v('dni') ?>">
                <span class="campo__ayuda">
                    8 dígitos, o carné de extranjería. Identifica a la persona: si ya está
                    registrado, reutilízalo en vez de crearlo de nuevo.
                </span>
                <?= $msg('dni') ?>
            </label>

            <label class="campo<?= $err('celular') ?>">
                <span class="campo__etiqueta">Celular *</span>
                <input type="tel" name="celular" maxlength="20" inputmode="numeric" required
                       placeholder="9########" value="<?= $v('celular') ?>">
                <?= $msg('celular') ?>
            </label>

            <label class="campo<?= $err('ap_paterno') ?>">
                <span class="campo__etiqueta">Apellido paterno *</span>
                <input type="text" name="ap_paterno" maxlength="100" required value="<?= $v('ap_paterno') ?>">
                <?= $msg('ap_paterno') ?>
            </label>

            <label class="campo<?= $err('ap_materno') ?>">
                <span class="campo__etiqueta">Apellido materno *</span>
                <input type="text" name="ap_materno" maxlength="100" required value="<?= $v('ap_materno') ?>">
                <?= $msg('ap_materno') ?>
            </label>

            <label class="campo campo--ancho<?= $err('nombres') ?>">
                <span class="campo__etiqueta">Nombres *</span>
                <input type="text" name="nombres" maxlength="150" required value="<?= $v('nombres') ?>">
                <?= $msg('nombres') ?>
            </label>
        </div>
    </fieldset>

    <div class="acciones">
        <button type="submit" class="boton boton--principal">
            <?= $esNuevo ? 'Registrar apoderado' : 'Guardar cambios' ?>
        </button>
        <a class="boton boton--tenue" href="<?= View::e(View::url('/apoderados')) ?>">Cancelar</a>
    </div>
</form>
