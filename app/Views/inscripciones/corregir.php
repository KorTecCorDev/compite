<?php

declare(strict_types=1);

use App\Models\Correccion;
use Core\Sesion;
use Core\Fecha;
use Core\View;

/** @var array<string, mixed> $inscripcion */
/** @var array<int, array<string, mixed>> $categorias */
/** @var array<int, array<string, mixed>> $instituciones */
/** @var array<int, array<string, mixed>> $historial */
/** @var bool $esAdmin */
/** @var array<string, mixed> $valores */
/** @var array<string, string> $errores */

$v   = static fn (string $c): string => View::e((string) ($valores[$c] ?? ''));
$err = static fn (string $c): string => isset($errores[$c]) ? ' campo--error' : '';
$msg = static fn (string $c): string => isset($errores[$c])
    ? '<span class="campo__error">' . View::e($errores[$c]) . '</span>'
    : '';

$tipoActual = (string) ($valores['tipo_participante'] ?? $inscripcion['tipo_participante']);
$esLibre    = $tipoActual === 'libre';
$estaPagada = $inscripcion['estado'] === 'confirmada';
?>
<div class="encabezado">
    <div>
        <h1 class="titulo">Corregir inscripción</h1>
        <p class="subtitulo">
            <strong><?= View::e($inscripcion['ap_paterno'] . ' ' . $inscripcion['ap_materno']) ?>,
            <?= View::e($inscripcion['nombres']) ?></strong>
            · <code><?= View::e($inscripcion['codigo_correlativo']) ?></code>
        </p>
    </div>
    <a class="boton boton--tenue" href="<?= View::e(View::url('/inscripciones')) ?>">Cancelar</a>
</div>

<?php if ($errores !== []): ?>
    <div class="aviso aviso--error">
        <strong>No se corrigió nada. Revisa <?= count($errores) ?> campo<?= count($errores) === 1 ? '' : 's' ?>:</strong>
        <ul class="lista-errores">
            <?php foreach ($errores as $mensaje): ?>
                <li><?= View::e($mensaje) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php
/*
 * Lo que va a pasar, dicho antes de tocar nada. Cambió por completo respecto al
 * formulario viejo: aquello anulaba y reinscribía, esto corrige en su sitio.
 * Quien lleve dos días usando el sistema espera lo primero, así que hay que
 * decirle que ya no es así.
 */
?>
<div class="aviso aviso--aviso">
    <strong>Qué va a pasar exactamente:</strong>
    <ul class="lista-errores">
        <li>Los datos se corrigen <strong>en esta misma inscripción</strong>: conserva su número,
            su estado y su carné. Ya <strong>no</strong> se anula ni se reinscribe.</li>
        <li>El participante conserva su código
            <code><?= View::e($inscripcion['codigo_correlativo']) ?></code>, que es el que se
            teclea en la puerta.</li>
        <li>Queda registrado <strong>qué cambió, quién lo cambió y por qué</strong>.</li>
        <?php if ($estaPagada): ?>
            <li>Como el pago ya está confirmado, la procedencia solo se puede cambiar
                <strong>si la tarifa nueva cuesta lo mismo</strong>. Si no, hay que anular y
                volver a registrar.</li>
        <?php endif; ?>
    </ul>
</div>

<form method="post" action="<?= View::e(View::url('/inscripciones/' . $inscripcion['id'] . '/corregir')) ?>"
      class="formulario-largo" id="form-corregir">
    <input type="hidden" name="_csrf" value="<?= View::e(Sesion::tokenCsrf()) ?>">

    <fieldset class="grupo">
        <legend class="grupo__titulo">Datos del estudiante</legend>

        <div class="rejilla">
            <label class="campo<?= $err('dni') ?>">
                <span class="campo__etiqueta">DNI o C.E. *</span>
                <input type="text" name="dni" maxlength="12" required value="<?= $v('dni') ?>">
                <?= $msg('dni') ?>
            </label>

            <label class="campo<?= $err('categoria_id') ?>">
                <span class="campo__etiqueta">Categoría *</span>
                <select name="categoria_id" required>
                    <?php foreach ($categorias as $cat): ?>
                        <option value="<?= (int) $cat['id'] ?>"
                            <?= (string) ($valores['categoria_id'] ?? '') === (string) $cat['id'] ? 'selected' : '' ?>>
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

    <?php if ($esAdmin): ?>
        <?php
        /*
         * Bloque exclusivo del administrador. No se dibuja para una secretaria
         * —y el controlador además RECHAZA el POST si llega con estos campos,
         * porque ignorarlos en silencio la dejaría creyendo que el colegio
         * cambió cuando no cambió nada—.
         */
        ?>
        <fieldset class="grupo">
            <legend class="grupo__titulo">Procedencia <span class="tenue">(solo administrador)</span></legend>

            <p class="grupo__ayuda">
                Cambia de dónde viene el estudiante. Esto decide <strong>la tarifa</strong> y
                <strong>la bolsa en la que compite</strong>, así que muévelo solo con la
                nómina del colegio delante.
            </p>

            <div class="rejilla">
                <label class="campo<?= $err('tipo_participante') ?>">
                    <span class="campo__etiqueta">Tipo de participante *</span>
                    <select name="tipo_participante" id="tipo-participante">
                        <option value="delegacion" <?= $esLibre ? '' : 'selected' ?>>Delegación de un colegio</option>
                        <option value="libre" <?= $esLibre ? 'selected' : '' ?>>Estudiante libre</option>
                    </select>
                    <?= $msg('tipo_participante') ?>
                </label>

                <label class="campo<?= $err('institucion_id') ?>" id="campo-institucion" <?= $esLibre ? 'hidden' : '' ?>>
                    <span class="campo__etiqueta">Institución educativa *</span>
                    <select name="institucion_id" id="institucion-id">
                        <option value="">Seleccionar…</option>
                        <?php foreach ($instituciones as $ie): ?>
                            <option value="<?= (int) $ie['id'] ?>"
                                <?= (string) ($valores['institucion_id'] ?? '') === (string) $ie['id'] ? 'selected' : '' ?>>
                                <?= View::e($ie['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= $msg('institucion_id') ?>
                </label>
            </div>

            <?php
            /*
             * Al pasar a libre hace falta un apoderado propio: el estudiante
             * deja de colgar de un colegio y esto es lo único que queda para
             * saber a quién llamar. Es el MISMO buscador de la pantalla de
             * inscripción libre —mismos ids, mismo JS compartido— para que un
             * apoderado ya registrado se reconozca en vez de duplicarse.
             */
            ?>
            <div id="bloque-apoderado" <?= $esLibre ? '' : 'hidden' ?>>
                <p class="grupo__ayuda">
                    Escribe el documento: si el apoderado ya está registrado, sus datos se
                    completan solos y se reutiliza el mismo registro.
                </p>

                <div class="rejilla">
                    <label class="campo<?= $err('ap_dni') ?>">
                        <span class="campo__etiqueta">DNI o C.E. del apoderado *</span>
                        <input type="text" name="ap_dni" id="ap-dni" maxlength="12"
                               data-url-buscar="<?= View::e(View::url('/api/apoderados/buscar')) ?>"
                               value="<?= $v('ap_dni') ?>">
                        <span class="campo__ayuda" id="ap-estado"></span>
                        <?= $msg('ap_dni') ?>
                    </label>

                    <p class="campo campo--ancho reutilizado" id="ap-reutilizado" hidden>
                        <span class="reutilizado__texto"></span>
                        <button type="button" class="boton boton--tenue" id="ap-editar">
                            Editar sus datos
                        </button>
                    </p>

                    <label class="campo<?= $err('ap_celular') ?>">
                        <span class="campo__etiqueta">Celular *</span>
                        <input type="tel" name="ap_celular" id="ap-celular" maxlength="20"
                               inputmode="numeric" placeholder="9########" value="<?= $v('ap_celular') ?>">
                        <?= $msg('ap_celular') ?>
                    </label>

                    <label class="campo<?= $err('ap_ap_paterno') ?>">
                        <span class="campo__etiqueta">Apellido paterno *</span>
                        <input type="text" name="ap_ap_paterno" id="ap-paterno" maxlength="100"
                               value="<?= $v('ap_ap_paterno') ?>">
                        <?= $msg('ap_ap_paterno') ?>
                    </label>

                    <label class="campo<?= $err('ap_ap_materno') ?>">
                        <span class="campo__etiqueta">Apellido materno *</span>
                        <input type="text" name="ap_ap_materno" id="ap-materno" maxlength="100"
                               value="<?= $v('ap_ap_materno') ?>">
                        <?= $msg('ap_ap_materno') ?>
                    </label>

                    <label class="campo<?= $err('ap_nombres') ?>">
                        <span class="campo__etiqueta">Nombres *</span>
                        <input type="text" name="ap_nombres" id="ap-nombres" maxlength="150"
                               value="<?= $v('ap_nombres') ?>">
                        <?= $msg('ap_nombres') ?>
                    </label>

                    <label class="campo<?= $err('ap_correo') ?>">
                        <span class="campo__etiqueta">Correo <span class="tenue">(opcional)</span></span>
                        <input type="email" name="ap_correo" id="ap-correo" maxlength="150"
                               value="<?= $v('ap_correo') ?>">
                        <?= $msg('ap_correo') ?>
                    </label>
                </div>
            </div>
        </fieldset>
    <?php endif; ?>

    <fieldset class="grupo">
        <legend class="grupo__titulo">Motivo</legend>

        <p class="grupo__ayuda">
            Obligatorio. Dentro de un mes, «¿por qué este estudiante cambió de colegio?»
            tiene que poder responderse leyendo esta línea.
        </p>

        <div class="rejilla">
            <label class="campo campo--ancho<?= $err('motivo') ?>">
                <span class="campo__etiqueta">Motivo de la corrección *</span>
                <input type="text" name="motivo" maxlength="250" required
                       placeholder="Ej.: el DNI se digitó con un dígito cambiado; verificado con el documento"
                       value="<?= $v('motivo') ?>">
                <?= $msg('motivo') ?>
            </label>
        </div>
    </fieldset>

    <div class="acciones">
        <button type="submit" class="boton boton--principal">Guardar corrección</button>
        <a class="boton boton--tenue" href="<?= View::e(View::url('/inscripciones')) ?>">Cancelar</a>
    </div>
</form>

<?php
/*
 * Historial en la misma pantalla, sin pantalla de auditoría aparte (decisión
 * del propietario, 20-ago).
 *
 * No es un adorno: con varias secretarias trabajando a la vez, quien va a
 * corregir necesita ver si alguien ya tocó ese mismo dato. Sin esto, la segunda
 * «corrige» encima de una corrección buena y nadie se entera.
 *
 * Son las correcciones del PARTICIPANTE, no solo las de esta inscripción: sus
 * datos le siguen si alguna vez se le reinscribe.
 */
?>
<?php if ($historial !== []): ?>
    <section class="grupo">
        <h2 class="grupo__titulo">Correcciones anteriores de este estudiante</h2>

        <div class="tabla-envoltorio">
            <table class="tabla">
                <thead>
                    <tr>
                        <th>Cuándo</th>
                        <th>Qué</th>
                        <th>Antes</th>
                        <th>Después</th>
                        <th>Motivo</th>
                        <th>Quién</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($historial as $fila): ?>
                        <tr>
                            <td data-etiqueta="Cuándo">
                                <?= View::e(Fecha::mostrar($fila['created_at'])) ?>
                            </td>
                            <td data-etiqueta="Qué"><?= View::e(Correccion::etiqueta((string) $fila['campo'])) ?></td>
                            <td data-etiqueta="Antes"><span class="tenue"><?= View::e((string) $fila['anterior']) ?></span></td>
                            <td data-etiqueta="Después"><strong><?= View::e((string) $fila['nuevo']) ?></strong></td>
                            <td data-etiqueta="Motivo"><?= View::e((string) $fila['motivo']) ?></td>
                            <td data-etiqueta="Quién"><?= View::e((string) $fila['corregido_por']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php if ($esAdmin): ?>
    <script src="<?= View::e(View::asset('build/js/apoderado-reutilizable.js')) ?>" defer></script>
    <script src="<?= View::e(View::asset('build/js/corregir.js')) ?>" defer></script>
<?php endif; ?>
