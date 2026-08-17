<?php

declare(strict_types=1);

use Core\Sesion;
use Core\View;

/** @var array<string, mixed> $concurso */
/** @var array<int, array<string, mixed>> $categorias */
/** @var float $tarifa */
/** @var array<string, mixed> $valores */
/** @var array<string, string> $errores */

$v   = static fn (string $c): string => View::e($valores[$c] ?? '');
$err = static fn (string $c): string => isset($errores[$c]) ? ' campo--error' : '';
$msg = static fn (string $c): string => isset($errores[$c])
    ? '<span class="campo__error">' . View::e($errores[$c]) . '</span>'
    : '';
?>
<div class="encabezado">
    <div>
        <h1 class="titulo">Inscripción de estudiante libre</h1>
        <p class="subtitulo">
            Para estudiantes que se inscriben por cuenta propia, sin delegación de su colegio.
        </p>
    </div>
    <a class="boton boton--tenue" href="<?= View::e(View::url('/inscripciones')) ?>">Ver inscripciones</a>
</div>

<?php if ($errores !== []): ?>
    <div class="aviso aviso--error">
        <strong>No se guardó nada. Revisa <?= count($errores) ?> campo<?= count($errores) === 1 ? '' : 's' ?>:</strong>
        <ul class="lista-errores">
            <?php foreach ($errores as $mensaje): ?>
                <li><?= View::e($mensaje) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="caja-tarifa">
    <span class="caja-tarifa__texto">
        Tarifa de estudiante libre: <strong>S/ <?= number_format($tarifa, 2) ?></strong>
    </span>
    <span class="caja-tarifa__nota">Fija para esta modalidad. No es editable.</span>
</div>

<form method="post" action="<?= View::e(View::url('/inscripciones/libre')) ?>" class="formulario-largo">
    <input type="hidden" name="_csrf" value="<?= View::e(Sesion::tokenCsrf()) ?>">

    <fieldset class="grupo">
        <legend class="grupo__titulo">Apoderado</legend>

        <p class="grupo__ayuda">
            Escribe el documento: si el apoderado ya está registrado —por ejemplo, porque
            inscribió antes a un hermano— sus datos se completan solos y se reutiliza el
            mismo registro.
        </p>

        <div class="rejilla">
            <label class="campo<?= $err('ap_dni') ?>">
                <span class="campo__etiqueta">DNI o C.E. *</span>
                <input type="text" name="ap_dni" id="ap-dni" maxlength="12" required
                       value="<?= $v('ap_dni') ?>">
                <span class="campo__ayuda" id="ap-estado"></span>
                <?= $msg('ap_dni') ?>
            </label>

            <label class="campo<?= $err('ap_celular') ?>">
                <span class="campo__etiqueta">Celular *</span>
                <input type="tel" name="ap_celular" id="ap-celular" maxlength="20" required
                       inputmode="numeric" placeholder="9########" value="<?= $v('ap_celular') ?>">
                <?= $msg('ap_celular') ?>
            </label>

            <label class="campo<?= $err('ap_ap_paterno') ?>">
                <span class="campo__etiqueta">Apellido paterno *</span>
                <input type="text" name="ap_ap_paterno" id="ap-paterno" maxlength="100" required
                       value="<?= $v('ap_ap_paterno') ?>">
                <?= $msg('ap_ap_paterno') ?>
            </label>

            <label class="campo<?= $err('ap_ap_materno') ?>">
                <span class="campo__etiqueta">Apellido materno *</span>
                <input type="text" name="ap_ap_materno" id="ap-materno" maxlength="100" required
                       value="<?= $v('ap_ap_materno') ?>">
                <?= $msg('ap_ap_materno') ?>
            </label>

            <label class="campo campo--ancho<?= $err('ap_nombres') ?>">
                <span class="campo__etiqueta">Nombres *</span>
                <input type="text" name="ap_nombres" id="ap-nombres" maxlength="150" required
                       value="<?= $v('ap_nombres') ?>">
                <?= $msg('ap_nombres') ?>
            </label>
        </div>
    </fieldset>

    <fieldset class="grupo">
        <legend class="grupo__titulo">Estudiante</legend>

        <div class="rejilla">
            <label class="campo<?= $err('dni') ?>">
                <span class="campo__etiqueta">DNI o C.E. *</span>
                <input type="text" name="dni" maxlength="12" required value="<?= $v('dni') ?>">
                <?= $msg('dni') ?>
            </label>

            <label class="campo<?= $err('categoria_id') ?>">
                <span class="campo__etiqueta">Categoría *</span>
                <select name="categoria_id" required>
                    <option value="">Seleccionar…</option>
                    <?php foreach ($categorias as $cat): ?>
                        <option value="<?= (int) $cat['id'] ?>"
                            <?= ($valores['categoria_id'] ?? null) == $cat['id'] ? 'selected' : '' ?>>
                            <?= View::e($cat['etiqueta']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?= $msg('categoria_id') ?>
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
        <button type="submit" class="boton boton--principal">Registrar inscripción</button>
        <a class="boton boton--tenue" href="<?= View::e(View::url('/inscripciones')) ?>">Cancelar</a>
    </div>
</form>

<script>
(function () {
    const campoDni = document.getElementById('ap-dni');
    const estado = document.getElementById('ap-estado');
    const campos = {
        ap_paterno: document.getElementById('ap-paterno'),
        ap_materno: document.getElementById('ap-materno'),
        nombres: document.getElementById('ap-nombres'),
        celular: document.getElementById('ap-celular')
    };

    let temporizador = null;

    campoDni.addEventListener('input', function () {
        clearTimeout(temporizador);
        const dni = campoDni.value.trim();

        if (dni.length < 8) { estado.textContent = ''; return; }

        temporizador = setTimeout(async function () {
            try {
                const url = <?= json_encode(View::url('/api/apoderados/buscar')) ?> +
                            '?dni=' + encodeURIComponent(dni);
                const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
                if (!r.ok) { estado.textContent = ''; return; }

                const datos = await r.json();

                if (!datos.encontrado) {
                    estado.textContent = 'Apoderado nuevo: completa sus datos.';
                    return;
                }

                /* Se autocompleta para reutilizar el registro existente. */
                Object.keys(campos).forEach(function (clave) {
                    if (campos[clave] && datos.apoderado[clave]) {
                        campos[clave].value = datos.apoderado[clave];
                    }
                });

                estado.textContent = 'Ya registrado: se reutilizará el mismo apoderado.';
            } catch (e) { estado.textContent = ''; }
        }, 400);
    });
})();
</script>
