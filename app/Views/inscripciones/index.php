<?php

declare(strict_types=1);

use Core\Sesion;
use Core\View;

/** @var array<string, mixed> $concurso */
/** @var array<int, array<string, mixed>> $inscripciones */
/** @var array<int, array<string, mixed>> $instituciones */
/** @var array<string, mixed> $filtros */
/** @var array<string, mixed> $resumen */

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
        <option value="publica" <?= $sel('tipo_origen', 'publica') ?>>I.E. pública</option>
        <option value="privada" <?= $sel('tipo_origen', 'privada') ?>>I.E. privada</option>
        <option value="libre"   <?= $sel('tipo_origen', 'libre') ?>>Estudiante libre</option>
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
                    <th class="tabla__acciones">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($inscripciones as $ins): ?>
                <?php $esPendiente = $ins['estado'] === 'pendiente'; ?>
                <tr>
                    <td>
                        <?php if ($esPendiente): ?>
                            <input type="checkbox" name="ids[]" value="<?= (int) $ins['id'] ?>"
                                   class="casilla-pago" data-monto="<?= (float) $ins['monto'] ?>">
                        <?php endif; ?>
                    </td>
                    <td>
                        <code><?= View::e($ins['codigo_correlativo']) ?></code>
                        <?php if ($ins['estado'] === 'confirmada'): ?>
                            <br>
                            <a class="enlace-tenue" target="_blank"
                               href="<?= View::e(View::url('/carne/' . $ins['codigo_correlativo'])) ?>">
                                ver carné
                            </a>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong><?= View::e($ins['ap_paterno'] . ' ' . $ins['ap_materno']) ?></strong>,
                        <?= View::e($ins['nombres']) ?>
                        <br><span class="tenue"><?= View::e($ins['dni']) ?></span>
                    </td>
                    <td class="tenue">
                        <?php if ($ins['tipo_participante'] === 'libre'): ?>
                            <span class="etiqueta etiqueta--neutra">libre</span>
                        <?php else: ?>
                            <?= View::e($ins['institucion'] ?? '—') ?>
                            <span class="etiqueta etiqueta--<?= View::e((string) $ins['institucion_tipo']) ?>">
                                <?= $ins['institucion_tipo'] === 'publica' ? 'pública' : 'privada' ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td><?= View::e(ucfirst((string) $ins['nivel'])) ?> <?= (int) $ins['grado'] ?>°</td>
                    <td>
                        S/ <?= number_format((float) $ins['monto'], 2) ?>
                        <?php if (!empty($ins['medio_pago'])): ?>
                            <br><span class="tenue"><?= View::e((string) $ins['medio_pago']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="etiqueta etiqueta--estado-<?= View::e((string) $ins['estado']) ?>">
                            <?= View::e((string) $ins['estado']) ?>
                        </span>
                        <?php if (!empty($ins['requiere_devolucion'])): ?>
                            <br><span class="etiqueta etiqueta--alerta">por devolver</span>
                        <?php endif; ?>
                    </td>
                    <td class="tabla__acciones">
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

                        <?php if ($ins['estado'] === 'confirmada'): ?>
                            <a class="enlace-tenue"
                               href="<?= View::e(View::url('/inscripciones/' . $ins['id'] . '/carne.pdf')) ?>">
                                PDF
                            </a>

                            <!--
                                Regenerar sirve si el PDF se perdió del disco o si
                                se corrigió algún dato de la ficha después de emitirlo.
                            -->
                            <form method="post"
                                  action="<?= View::e(View::url('/inscripciones/' . $ins['id'] . '/carne/regenerar')) ?>"
                                  onsubmit="return confirm('¿Volver a generar el PDF de este carné?');">
                                <input type="hidden" name="_csrf" value="<?= View::e(Sesion::tokenCsrf()) ?>">
                                <button type="submit" class="enlace-tenue enlace-boton">Regenerar</button>
                            </form>
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

        <label class="barra-cobro__campo" id="campo-yape" hidden>
            <span class="campo__etiqueta">
                Código de seguridad <span class="tenue">(opcional)</span>
            </span>
            <input type="text" name="yape_codigo" id="yape-codigo" maxlength="3"
                   inputmode="numeric" placeholder="3 dígitos">
        </label>

        <button type="submit" class="boton boton--principal">
            Confirmar pago y emitir carnés
        </button>
    </div>
    <?php endif; ?>
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
