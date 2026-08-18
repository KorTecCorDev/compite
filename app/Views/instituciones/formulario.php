<?php

declare(strict_types=1);

use Core\Sesion;
use Core\View;

/** @var array<string, mixed>|null $institucion */
/** @var array<string, mixed> $valores */
/** @var array<string, string> $errores */

$esNueva = $institucion === null;
$accion  = $esNueva
    ? View::url('/instituciones')
    : View::url('/instituciones/' . $institucion['id']);

/** Devuelve el valor a repintar en el campo tras un error de validación. */
$v = static fn (string $campo): string => View::e($valores[$campo] ?? '');

/** Marca visualmente el campo que falló. */
$err = static fn (string $campo): string => isset($errores[$campo]) ? ' campo--error' : '';

/** Mensaje bajo el campo. */
$msg = static function (string $campo) use ($errores): string {
    return isset($errores[$campo])
        ? '<span class="campo__error">' . View::e($errores[$campo]) . '</span>'
        : '';
};
?>
<div class="encabezado">
    <div>
        <h1 class="titulo"><?= $esNueva ? 'Nueva Institución Educativa' : 'Editar Institución Educativa' ?></h1>
        <p class="subtitulo">
            El docente delegado y el director se guardan aquí, no en cada inscripción.
            Si cambian, se actualizan sobre este mismo registro.
        </p>
    </div>
    <a class="boton boton--tenue" href="<?= View::e(View::url('/instituciones')) ?>">Volver</a>
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
        <legend class="grupo__titulo">Datos de la institución</legend>

        <div class="rejilla">
            <label class="campo campo--ancho<?= $err('nombre') ?>">
                <span class="campo__etiqueta">Nombre de la I.E. *</span>
                <input type="text" name="nombre" maxlength="200" required value="<?= $v('nombre') ?>"
                       data-url-buscar="<?= View::e(View::url('/api/instituciones/buscar')) ?>"
                       data-url-editar="<?= View::e(View::url('/instituciones/')) ?>">
                <?= $msg('nombre') ?>
            </label>

            <?php /* Pegado al campo que lo dispara, no al pie del formulario.
                     Un aviso que aparece a pantalla y media de distancia del
                     dato que lo provoca no lo lee nadie: para cuando la
                     secretaria llega abajo, ya escribió los otros diecisiete
                     campos. Ver D-30. */ ?>
            <?php if ($esNueva): ?>
                <div id="aviso-duplicados" class="aviso aviso--aviso aviso--campo campo--ancho"
                     role="status" hidden></div>
            <?php endif; ?>

            <label class="campo<?= $err('tipo') ?>">
                <span class="campo__etiqueta">Tipo *</span>
                <select name="tipo" required>
                    <option value="">Seleccionar…</option>
                    <option value="publica" <?= ($valores['tipo'] ?? '') === 'publica' ? 'selected' : '' ?>>Pública</option>
                    <option value="privada" <?= ($valores['tipo'] ?? '') === 'privada' ? 'selected' : '' ?>>Privada</option>
                </select>
                <span class="campo__ayuda">Define la tarifa: pública S/10, privada S/15.</span>
                <?= $msg('tipo') ?>
            </label>

            <label class="campo<?= $err('departamento') ?>">
                <span class="campo__etiqueta">Departamento *</span>
                <input type="text" name="departamento" maxlength="100" required
                       value="<?= $valores === [] ? 'Áncash' : $v('departamento') ?>">
                <?= $msg('departamento') ?>
            </label>

            <label class="campo<?= $err('provincia') ?>">
                <span class="campo__etiqueta">Provincia *</span>
                <input type="text" name="provincia" maxlength="100" required
                       value="<?= $valores === [] ? 'Huaraz' : $v('provincia') ?>">
                <?= $msg('provincia') ?>
            </label>

            <label class="campo<?= $err('distrito') ?>">
                <span class="campo__etiqueta">Distrito *</span>
                <input type="text" name="distrito" maxlength="100" required value="<?= $v('distrito') ?>">
                <?= $msg('distrito') ?>
            </label>

            <label class="campo campo--ancho<?= $err('direccion') ?>">
                <span class="campo__etiqueta">Dirección *</span>
                <input type="text" name="direccion" maxlength="250" required value="<?= $v('direccion') ?>">
                <?= $msg('direccion') ?>
            </label>
        </div>
    </fieldset>

    <fieldset class="grupo">
        <legend class="grupo__titulo">Docente delegado</legend>

        <p class="grupo__ayuda">
            Es el <strong>encargado de la delegación</strong>: queda registrado como apoderado
            de todos los estudiantes que inscriba este colegio. Empieza por su documento — si ya
            está en el sistema, sus datos se completan solos y se reutiliza la misma persona en
            vez de duplicarla.
        </p>

        <div class="rejilla">
            <!-- El documento va primero porque es la llave: es lo que reconoce a
                 la persona. Obligatorio desde D-28 — era el único campo opcional
                 del bloque, y dejó de poder serlo cuando el docente delegado
                 pasó a ser el apoderado de su delegación. -->
            <label class="campo<?= $err('dd_dni') ?>">
                <span class="campo__etiqueta">DNI o C.E. *</span>
                <input type="text" name="dd_dni" id="dd-dni" maxlength="12" required
                       data-url-buscar="<?= View::e(View::url('/api/apoderados/buscar')) ?>"
                       value="<?= $v('docente_delegado_dni') ?: $v('dd_dni') ?>">
                <span class="campo__ayuda" id="dd-estado">8 dígitos, o carné de extranjería.</span>
                <?= $msg('dd_dni') ?>
            </label>

            <label class="campo<?= $err('dd_celular') ?>">
                <span class="campo__etiqueta">Celular *</span>
                <input type="tel" name="dd_celular" id="dd-celular" maxlength="20" required inputmode="numeric"
                       placeholder="9########" value="<?= $v('docente_delegado_celular') ?: $v('dd_celular') ?>">
                <?= $msg('dd_celular') ?>
            </label>

            <label class="campo<?= $err('dd_ap_paterno') ?>">
                <span class="campo__etiqueta">Apellido paterno *</span>
                <input type="text" name="dd_ap_paterno" id="dd-paterno" maxlength="100" required value="<?= $v('docente_delegado_ap_paterno') ?: $v('dd_ap_paterno') ?>">
                <?= $msg('dd_ap_paterno') ?>
            </label>

            <label class="campo<?= $err('dd_ap_materno') ?>">
                <span class="campo__etiqueta">Apellido materno *</span>
                <input type="text" name="dd_ap_materno" id="dd-materno" maxlength="100" required value="<?= $v('docente_delegado_ap_materno') ?: $v('dd_ap_materno') ?>">
                <?= $msg('dd_ap_materno') ?>
            </label>

            <label class="campo<?= $err('dd_nombres') ?>">
                <span class="campo__etiqueta">Nombres *</span>
                <input type="text" name="dd_nombres" id="dd-nombres" maxlength="150" required value="<?= $v('docente_delegado_nombres') ?: $v('dd_nombres') ?>">
                <?= $msg('dd_nombres') ?>
            </label>

            <label class="campo<?= $err('dd_correo') ?>">
                <span class="campo__etiqueta">Correo electrónico <span class="tenue">(opcional)</span></span>
                <input type="email" name="dd_correo" id="dd-correo" maxlength="150" value="<?= $v('docente_delegado_correo') ?: $v('dd_correo') ?>">
                <?= $msg('dd_correo') ?>
            </label>

            <!-- Mismo freno que en la inscripción libre: al reconocer a la
                 persona, sus datos quedan bloqueados. Aquí importa más todavía,
                 porque un tipeo no afecta a un estudiante sino a la delegación
                 entera que este docente encabeza. -->
            <p class="campo campo--ancho reutilizado" id="dd-reutilizado" hidden>
                <span class="reutilizado__texto"></span>
                <button type="button" class="boton boton--tenue" id="dd-editar">
                    Editar sus datos
                </button>
            </p>
        </div>
    </fieldset>

    <fieldset class="grupo">
        <legend class="grupo__titulo">Director de la I.E. <span class="grupo__nota">— opcional</span></legend>

        <p class="grupo__ayuda">
            Puedes registrar la institución sin estos datos y completarlos después.
            Si llenas alguno, se valida su formato.
        </p>

        <div class="rejilla">
            <label class="campo<?= $err('di_ap_paterno') ?>">
                <span class="campo__etiqueta">Apellido paterno</span>
                <input type="text" name="di_ap_paterno" maxlength="100" value="<?= $v('director_ap_paterno') ?: $v('di_ap_paterno') ?>">
                <?= $msg('di_ap_paterno') ?>
            </label>

            <label class="campo<?= $err('di_ap_materno') ?>">
                <span class="campo__etiqueta">Apellido materno</span>
                <input type="text" name="di_ap_materno" maxlength="100" value="<?= $v('director_ap_materno') ?: $v('di_ap_materno') ?>">
                <?= $msg('di_ap_materno') ?>
            </label>

            <label class="campo<?= $err('di_nombres') ?>">
                <span class="campo__etiqueta">Nombres</span>
                <input type="text" name="di_nombres" maxlength="150" value="<?= $v('director_nombres') ?: $v('di_nombres') ?>">
                <?= $msg('di_nombres') ?>
            </label>

            <label class="campo<?= $err('di_celular') ?>">
                <span class="campo__etiqueta">Celular</span>
                <input type="tel" name="di_celular" maxlength="20" inputmode="numeric"
                       placeholder="9########" value="<?= $v('director_celular') ?: $v('di_celular') ?>">
                <?= $msg('di_celular') ?>
            </label>

            <label class="campo<?= $err('di_correo') ?>">
                <span class="campo__etiqueta">Correo electrónico</span>
                <input type="email" name="di_correo" maxlength="150" value="<?= $v('director_correo') ?: $v('di_correo') ?>">
                <?= $msg('di_correo') ?>
            </label>

            <label class="campo<?= $err('di_dni') ?>">
                <span class="campo__etiqueta">DNI o C.E.</span>
                <input type="text" name="di_dni" maxlength="12"
                       value="<?= $v('director_dni') ?: $v('di_dni') ?>">
                <?= $msg('di_dni') ?>
            </label>
        </div>
    </fieldset>

    <div class="acciones">
        <button type="submit" class="boton boton--principal">
            <?= $esNueva ? 'Registrar institución' : 'Guardar cambios' ?>
        </button>
        <a class="boton boton--tenue" href="<?= View::e(View::url('/instituciones')) ?>">Cancelar</a>
    </div>
</form>

<?php /* Los scripts, en cambio, se cargan SIEMPRE. Antes vivían dentro del
         `if` de arriba, y eso dejaba el reconocimiento del docente delegado
         sin funcionar justo al editar una institución —que es donde se le
         cambia el encargado—. Cada bloque de instituciones.js comprueba que
         sus elementos existan antes de engancharse, así que cargarlo en las dos
         pantallas no activa lo que no toca. */ ?>
<script src="<?= View::e(View::url('build/js/apoderado-reutilizable.js')) ?>" defer></script>
<script src="<?= View::e(View::url('build/js/instituciones.js')) ?>" defer></script>
