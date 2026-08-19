<?php

declare(strict_types=1);

use App\Models\Concurso;
use Core\Sesion;
use Core\View;

/** @var array<string, mixed> $concurso */
/** @var array<int, array<string, mixed>> $inscripciones */
/** @var array<int, array<string, mixed>> $instituciones */
/** @var array<string, mixed> $filtros */
/** @var array<string, mixed> $resumen */
/** @var int $total */
/** @var int $tope */

$sel = static fn (string $clave, string $valor): string
    => (string) ($filtros[$clave] ?? '') === $valor ? 'selected' : '';

$hayPendientes = false;
foreach ($inscripciones as $ins) {
    if ($ins['estado'] === 'pendiente') { $hayPendientes = true; break; }
}
?>
<div class="encabezado">
    <div>
        <h1 class="titulo">Inscripciones</h1>
        <p class="subtitulo"><?= View::e($concurso['nombre']) ?></p>
    </div>
    <div class="acciones">
        <a class="boton boton--principal" href="<?= View::e(View::url('/inscripciones/delegacion')) ?>">
            Nueva delegación
        </a>
        <a class="boton boton--tenue" href="<?= View::e(View::url('/inscripciones/libre')) ?>">
            Estudiante libre
        </a>
    </div>
</div>

<section class="metricas">
    <div class="metrica">
        <span class="metrica__valor"><?= (int) $resumen['pendientes'] ?></span>
        <span class="metrica__nombre">Pendientes de pago</span>
    </div>
    <div class="metrica">
        <span class="metrica__valor"><?= (int) $resumen['confirmadas'] ?></span>
        <span class="metrica__nombre">Confirmadas</span>
    </div>
    <div class="metrica">
        <span class="metrica__valor"><?= (int) $resumen['anuladas'] ?></span>
        <span class="metrica__nombre">Anuladas</span>
    </div>
    <div class="metrica metrica--destacada">
        <span class="metrica__valor">S/ <?= number_format((float) $resumen['recaudado'], 2) ?></span>
        <span class="metrica__nombre">
            Recaudado · por cobrar S/ <?= number_format((float) $resumen['por_cobrar'], 2) ?>
        </span>
    </div>
</section>

<form method="get" action="<?= View::e(View::url('/inscripciones')) ?>" class="filtros">
    <input type="search" name="q" placeholder="Código, documento o nombre…"
           value="<?= View::e((string) $filtros['q']) ?>">

    <select name="institucion_id">
        <option value="">Todas las delegaciones</option>
        <?php foreach ($instituciones as $ie): ?>
            <option value="<?= (int) $ie['id'] ?>"
                <?= (string) $filtros['institucion_id'] === (string) $ie['id'] ? 'selected' : '' ?>>
                <?= View::e($ie['nombre']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select name="tipo_origen">
        <option value="">Todo origen</option>
        <option value="publica"      <?= $sel('tipo_origen', 'publica') ?>>I.E. pública</option>
        <option value="privada"      <?= $sel('tipo_origen', 'privada') ?>>I.E. privada</option>
        <option value="libre"        <?= $sel('tipo_origen', 'libre') ?>>Estudiante libre</option>
        <option value="organizadora" <?= $sel('tipo_origen', 'organizadora') ?>>COCIAP</option>
    </select>

    <select name="nivel">
        <option value="">Todo nivel</option>
        <option value="primaria"   <?= $sel('nivel', 'primaria') ?>>Primaria</option>
        <option value="secundaria" <?= $sel('nivel', 'secundaria') ?>>Secundaria</option>
    </select>

    <select name="grado">
        <option value="">Todo grado</option>
        <?php for ($g = 1; $g <= 6; $g++): ?>
            <option value="<?= $g ?>" <?= $sel('grado', (string) $g) ?>><?= $g ?>°</option>
        <?php endfor; ?>
    </select>

    <select name="estado">
        <option value="">Todo estado</option>
        <option value="pendiente"  <?= $sel('estado', 'pendiente') ?>>Pendiente</option>
        <option value="confirmada" <?= $sel('estado', 'confirmada') ?>>Confirmada</option>
        <option value="anulada"    <?= $sel('estado', 'anulada') ?>>Anulada</option>
    </select>

    <button type="submit" class="boton boton--tenue">Filtrar</button>
    <a class="enlace-tenue" href="<?= View::e(View::url('/inscripciones')) ?>">Limpiar</a>
</form>

<?php if (!empty($filtros['institucion_id'])): ?>
    <?php
    /*
     * Solo aparece con una delegación elegida, porque solo entonces la hoja
     * tiene un destinatario claro. Imprimir «todos los carnés del concurso»
     * sería otra cosa: cientos de páginas y un PDF que en hosting compartido
     * se queda sin tiempo de ejecución a medio generar.
     */
    $confirmadas = 0;
    foreach ($inscripciones as $ins) {
        if ($ins['estado'] === 'confirmada') { $confirmadas++; }
    }
    ?>
    <?php if ($confirmadas > 0): ?>
        <p class="acciones-delegacion">
            <a class="boton boton--principal"
               href="<?= View::e(View::url('/delegaciones/' . (int) $filtros['institucion_id'] . '/carnes.pdf')) ?>">
                Imprimir carnés de esta delegación
            </a>
            <span class="acciones-delegacion__nota">
                <?= (int) $confirmadas ?> confirmada(s) · hoja A4, 10 carnés por página, con guías de corte
            </span>
        </p>
    <?php endif; ?>
<?php endif; ?>

<?php if (count($inscripciones) < $total): ?>
    <?php /* El corte por tope deja de ser silencioso (D-40): la misma consulta
             alimenta la hoja de carnés por delegación, y una hoja incompleta no
             se nota hasta que faltan carnés en la puerta. */ ?>
    <div class="aviso aviso--aviso">
        <strong>Se están mostrando <?= count($inscripciones) ?> de <?= (int) $total ?> inscripciones.</strong>
        El listado se corta en <?= (int) $tope ?> filas. Afina los filtros —por delegación,
        modalidad o grado— para verlas todas; <strong>la hoja de carnés de una delegación
        también se corta ahí</strong>.
    </div>
<?php endif; ?>

<?php if ($inscripciones === []): ?>

    <div class="vacio">
        No hay inscripciones que coincidan.
        <a href="<?= View::e(View::url('/inscripciones/delegacion')) ?>">Registrar una delegación</a>
        o <a href="<?= View::e(View::url('/inscripciones/libre')) ?>">un estudiante libre</a>.
    </div>

<?php else: ?>

<form method="post" action="<?= View::e(View::url('/pagos/confirmar')) ?>" id="form-cobro">
    <input type="hidden" name="_csrf" value="<?= View::e(Sesion::tokenCsrf()) ?>">

    <div class="tabla-contenedor">
        <table class="tabla">
            <thead>
                <tr>
                    <th style="width:2.2rem">
                        <?php if ($hayPendientes): ?>
                            <input type="checkbox" id="marcar-todas" title="Seleccionar todas las pendientes">
                        <?php endif; ?>
                    </th>
                    <th>Código</th>
                    <th>Apellidos y nombres</th>
                    <th>Origen</th>
                    <th>Categoría</th>
                    <th>Monto</th>
                    <th>Estado</th>
                    <th>Responsable</th>
                    <th class="tabla__acciones">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($inscripciones as $ins): ?>
                <?php $esPendiente = $ins['estado'] === 'pendiente'; ?>
                <tr>
                    <td data-etiqueta="Cobrar">
                        <?php if ($esPendiente): ?>
                            <input type="checkbox" name="ids[]" value="<?= (int) $ins['id'] ?>"
                                   class="casilla-pago" data-monto="<?= (float) $ins['monto'] ?>">
                        <?php endif; ?>
                    </td>
                    <td data-etiqueta="Código">
                        <code><?= View::e($ins['codigo_correlativo']) ?></code>
                        <?php if ($ins['estado'] === 'confirmada'): ?>
                            <br>
                            <a class="enlace-tenue" target="_blank"
                               href="<?= View::e(View::url('/carne/' . $ins['codigo_correlativo'])) ?>">
                                ver carné
                            </a>
                        <?php endif; ?>
                    </td>
                    <td class="tabla__principal">
                        <strong><?= View::e($ins['ap_paterno'] . ' ' . $ins['ap_materno']) ?></strong>,
                        <?= View::e($ins['nombres']) ?>
                        <br><span class="tenue"><?= View::e($ins['dni']) ?></span>
                    </td>
                    <td class="tenue" data-etiqueta="Origen">
                        <?php
                        /* La píldora dice la MODALIDAD con la que se cobró, no el
                           tipo del colegio (D-37): el anfitrión es público y aun así
                           compite y paga como COCIAP. */
                        $modalidad = (string) $ins['tipo_origen'];
                        ?>
                        <?php if ($ins['tipo_participante'] === 'libre'): ?>
                            <span class="etiqueta etiqueta--neutra">libre</span>
                        <?php else: ?>
                            <?= View::e($ins['institucion'] ?? '—') ?>
                            <span class="etiqueta etiqueta--<?= View::e($modalidad) ?>">
                                <?= View::e(Concurso::etiquetaModalidad($modalidad)) ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td data-etiqueta="Categoría"><?= View::e(ucfirst((string) $ins['nivel'])) ?> <?= (int) $ins['grado'] ?>°</td>
                    <td data-etiqueta="Monto">
                        S/ <?= number_format((float) $ins['monto'], 2) ?>
                        <?php if (!empty($ins['medio_pago'])): ?>
                            <br><span class="tenue"><?= View::e((string) $ins['medio_pago']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-etiqueta="Estado">
                        <span class="etiqueta etiqueta--estado-<?= View::e((string) $ins['estado']) ?>">
                            <?= View::e((string) $ins['estado']) ?>
                        </span>
                        <?php if (!empty($ins['requiere_devolucion'])): ?>
                            <br><span class="etiqueta etiqueta--alerta">por devolver</span>
                        <?php endif; ?>
                    </td>
                    <?php
                    /* Quién registró la inscripción (D-39). Con varias secretarias
                       trabajando a la vez, un registro incorrecto tiene que poder
                       atribuirse sin salir del listado. Quién cobró y quién anuló
                       también quedan guardados, pero no se muestran aquí por
                       decisión del propietario: la columna es una sola. */
                    ?>
                    <td class="tenue" data-etiqueta="Responsable"><?= View::e((string) $ins['registrado_por']) ?></td>
                    <td class="tabla__acciones" data-etiqueta="Acciones">
                        <?php if ($ins['estado'] !== 'anulada'): ?>
                            <a class="enlace-tenue"
                               href="<?= View::e(View::url('/inscripciones/' . $ins['id'] . '/corregir')) ?>">
                                Corregir categoría
                            </a>
                            <button type="button" class="enlace-peligro boton-anular"
                                    data-id="<?= (int) $ins['id'] ?>"
                                    data-nombre="<?= View::e($ins['ap_paterno'] . ' ' . $ins['nombres']) ?>"
                                    data-pagada="<?= $ins['estado'] === 'confirmada' ? '1' : '0' ?>"
                                    data-monto="<?= number_format((float) $ins['monto'], 2) ?>">
                                Anular
                            </button>
                        <?php endif; ?>

                        <?php
                        /* Reinscribir solo cuando el participante se quedó SIN
                           ninguna inscripción viva, que es cuando de verdad está
                           fuera del concurso. Cada corrección de categoría deja
                           una anulada detrás, y ofrecer el enlace también en esas
                           —que son la mayoría— lo volvería ruido y llevaría a
                           duplicar la inscripción de alguien que ya está dentro. */
                        ?>
                        <?php if ($ins['estado'] === 'anulada' && empty($ins['participante_activo'])): ?>
                            <a class="enlace-tenue"
                               href="<?= View::e(View::url('/inscripciones/' . $ins['id'] . '/reinscribir')) ?>">
                                Reinscribir
                            </a>
                        <?php endif; ?>

                        <?php if ($ins['estado'] === 'confirmada'): ?>
                            <a class="enlace-tenue"
                               href="<?= View::e(View::url('/inscripciones/' . $ins['id'] . '/carne.pdf')) ?>">
                                PDF
                            </a>

                            <!--
                                Regenerar sirve si el PDF se perdió del disco o si
                                se corrigió algún dato de la ficha después de emitirlo.

                                El botón está dentro de la tabla pero pertenece al
                                formulario `form-regenerar`, que vive fuera del de
                                cobro. Aquí había un formulario anidado, que no es
                                HTML válido: el navegador ignora su etiqueta de
                                apertura y su etiqueta de cierre cierra el
                                formulario de cobro en la primera fila confirmada. Todo lo que venía después
                                —el resto de casillas y el botón de confirmar—
                                quedaba fuera de cualquier formulario, y por eso no
                                se podía cobrar nada desde el listado completo.
                            -->
                            <button type="submit" class="enlace-tenue enlace-boton"
                                    form="form-regenerar"
                                    formaction="<?= View::e(View::url('/inscripciones/' . $ins['id'] . '/carne/regenerar')) ?>">
                                Regenerar
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($hayPendientes): ?>
    <div class="barra-cobro" id="barra-cobro" hidden>
        <div class="barra-cobro__resumen">
            <strong id="cobro-cantidad">0</strong> seleccionada(s) ·
            total <strong id="cobro-total">S/ 0.00</strong>
        </div>

        <label class="barra-cobro__campo">
            <span class="campo__etiqueta">Medio de pago *</span>
            <select name="medio_pago" id="medio-pago" required>
                <option value="">Seleccionar…</option>
                <option value="yape">Yape</option>
                <option value="transferencia">Transferencia (BCP)</option>
                <option value="efectivo">Efectivo</option>
            </select>
        </label>

        <!--
            El `required` no está en el HTML: lo pone y lo quita el JS junto con
            la visibilidad. Un campo obligatorio dentro de un bloque oculto
            bloquea el envío sin decir por qué —el navegador intenta enfocar algo
            que no se ve— y dejaría la caja parada al cobrar en efectivo.
        -->
        <label class="barra-cobro__campo" id="campo-yape" hidden>
            <span class="campo__etiqueta">Código de seguridad *</span>
            <input type="text" name="yape_codigo" id="yape-codigo" maxlength="3"
                   inputmode="numeric" pattern="[0-9]{3}" placeholder="3 dígitos">
        </label>

        <button type="submit" class="boton boton--principal">
            Confirmar pago y emitir carnés
        </button>
    </div>
    <?php endif; ?>
</form>

<!--
    Regeneración del carné: un solo formulario para todas las filas. La acción
    concreta la pone cada botón con `formaction`, así no hace falta un formulario
    por fila dentro del de cobro.
-->
<form method="post" id="form-regenerar" class="oculto"
      onsubmit="return confirm('¿Volver a generar el PDF de este carné?');">
    <input type="hidden" name="_csrf" value="<?= View::e(Sesion::tokenCsrf()) ?>">
</form>

<!-- Anulación definitiva: formulario aparte, para que no viaje con el cobro. -->
<form method="post" id="form-anular" class="oculto"
      data-url-base="<?= View::e(View::url('/inscripciones/')) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e(Sesion::tokenCsrf()) ?>">
    <input type="hidden" name="motivo" id="motivo-anulacion">
</form>

<p class="nota">
    Mostrando <?= count($inscripciones) ?> inscripción(es).
    «Corregir categoría» anula y reinscribe conservando el pago y el código.
    «Anular» es definitiva y, si ya estaba pagada, suma el monto al fondo de devoluciones.
</p>

<script src="<?= View::e(View::url('build/js/inscripciones.js')) ?>" defer></script>

<?php endif; ?>
