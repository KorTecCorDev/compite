<?php

declare(strict_types=1);

use Core\Fecha;
use Core\View;

/** @var array<string, mixed> $concurso */
/** @var array<int, array<string, mixed>> $filas */
/** @var array<int, array<string, mixed>> $instituciones */
/** @var array<int, array<string, mixed>> $usuarios */
/** @var array<string, mixed> $filtros */
/** @var int $total */
/** @var int $tope */

$soles = static fn (mixed $monto): string => 'S/ ' . number_format((float) $monto, 2);

$sel = static fn (string $clave, string $valor): string
    => (string) ($filtros[$clave] ?? '') === $valor ? 'selected' : '';

$medios = [
    'yape'          => 'Yape',
    'transferencia' => 'Transferencia',
    'efectivo'      => 'Efectivo',
];

$modalidades = [
    'publica'      => 'Pública',
    'privada'      => 'Privada',
    'libre'        => 'Libre',
    'organizadora' => 'COCIAP',
];

// Cuántas de las filas mostradas llevan un pago que los reportes de dinero YA
// cuentan en otra fila. Se cuenta aquí para poder avisar solo cuando las hay:
// una advertencia permanente deja de leerse.
$copias = 0;

foreach ($filas as $fila) {
    if ($fila['fecha_pago'] !== null && (int) $fila['pago_contado'] === 0) {
        $copias++;
    }
}

$hayFiltro = false;

foreach ($filtros as $valor) {
    if ((string) $valor !== '') {
        $hayFiltro = true;
        break;
    }
}
?>
<div class="encabezado">
    <div>
        <h1 class="titulo">Cobros</h1>
        <p class="subtitulo">
            Todas las inscripciones del concurso, ordenadas por la fecha en que se
            confirmó el pago — lo más reciente arriba.
        </p>
    </div>
    <div class="acciones no-imprimir">
        <button type="button" class="boton boton--tenue" data-imprimir>Imprimir</button>
    </div>
</div>

<?= View::parcial("reporte-nav", ["actual" => "/reportes/cobros"]) ?>

<?= View::parcial('reporte-identidad', [
    'rotulo'   => 'Detalle de cobros',
    'concurso' => (string) ($concurso['nombre'] ?? ''),
    'corte'    => $hayFiltro
        ? 'Filas filtradas, tal como se ven en pantalla. Incluye pendientes y anuladas.'
        : 'Todas las inscripciones del concurso, incluidas las pendientes y las anuladas.',
]) ?>

<form method="get" action="<?= View::e(View::url('/reportes/cobros')) ?>" class="filtros no-imprimir">
    <input type="search" name="q" placeholder="Código, documento o nombre…"
           value="<?= View::e((string) $filtros['q']) ?>">

    <select name="estado">
        <option value="">Todo estado</option>
        <option value="pendiente"  <?= $sel('estado', 'pendiente') ?>>Pendiente</option>
        <option value="confirmada" <?= $sel('estado', 'confirmada') ?>>Confirmada</option>
        <option value="anulada"    <?= $sel('estado', 'anulada') ?>>Anulada</option>
    </select>

    <select name="medio_pago">
        <option value="">Todo medio de pago</option>
        <option value="yape"          <?= $sel('medio_pago', 'yape') ?>>Yape</option>
        <option value="transferencia" <?= $sel('medio_pago', 'transferencia') ?>>Transferencia</option>
        <option value="efectivo"      <?= $sel('medio_pago', 'efectivo') ?>>Efectivo</option>
        <option value="sin_cobrar"    <?= $sel('medio_pago', 'sin_cobrar') ?>>Sin cobrar</option>
    </select>

    <select name="confirmado_por">
        <option value="">Confirmado por cualquiera</option>
        <?php foreach ($usuarios as $u): ?>
            <option value="<?= (int) $u['id'] ?>"
                <?= (string) $filtros['confirmado_por'] === (string) $u['id'] ? 'selected' : '' ?>>
                <?= View::e($u['nombres']) ?><?= empty($u['activo']) ? ' (inactivo)' : '' ?>
            </option>
        <?php endforeach; ?>
        <option value="sin_firma" <?= $sel('confirmado_por', 'sin_firma') ?>>(sin firma)</option>
    </select>

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
        <option value="">Toda modalidad</option>
        <?php foreach ($modalidades as $clave => $rotulo): ?>
            <option value="<?= View::e($clave) ?>" <?= $sel('tipo_origen', $clave) ?>>
                <?= View::e($rotulo) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label class="filtros__fecha">
        Cobrado desde
        <input type="date" name="desde" value="<?= View::e((string) $filtros['desde']) ?>">
    </label>

    <label class="filtros__fecha">
        hasta
        <input type="date" name="hasta" value="<?= View::e((string) $filtros['hasta']) ?>">
    </label>

    <button type="submit" class="boton boton--tenue">Filtrar</button>
    <a class="enlace-tenue" href="<?= View::e(View::url('/reportes/cobros')) ?>">Limpiar</a>
</form>

<p class="nota no-imprimir">
    Las fechas filtran por <strong>cuándo se confirmó el pago</strong>, así que al usarlas
    desaparece todo lo que no se ha cobrado: una pendiente no tiene fecha que comparar.
</p>

<?php if ($total > $tope): ?>
    <div class="aviso aviso--aviso">
        Hay <?= (int) $total ?> inscripciones que cumplen el filtro y se muestran las
        primeras <?= count($filas) ?>. Acota la búsqueda para verlas todas.
    </div>
<?php endif; ?>

<?php if ($filas === []): ?>

    <div class="vacio">
        Ninguna inscripción cumple ese filtro.
        <a href="<?= View::e(View::url('/reportes/cobros')) ?>">Ver todas</a>.
    </div>

<?php else: ?>

    <p class="nota">
        <strong><?= (int) $total ?></strong>
        inscripcion<?= $total === 1 ? '' : 'es' ?><?= $hayFiltro ? ' con este filtro' : ' en el concurso' ?>.
        <strong>Aquí no se suma dinero a propósito:</strong> una reinscripción deja el mismo
        pago escrito en dos filas, así que sumar esta lista cobraría dos veces al mismo
        estudiante. Los totales están en
        <a href="<?= View::e(View::url('/reportes/saldos')) ?>">Estado de la caja</a>.
    </p>

    <?php if ($copias > 0): ?>
        <div class="aviso aviso--aviso no-imprimir">
            <?= $copias === 1 ? 'Una fila lleva' : $copias . ' filas llevan' ?>
            un pago que <strong>ya está contado en otra</strong>: son las que dejó atrás una
            reinscripción, marcadas como «ya contado». El pago se copia a la inscripción
            nueva para que sepa cómo se cobró, y las dos conservan su fecha.
        </div>
    <?php endif; ?>

    <div class="tabla-contenedor">
        <table class="tabla tabla--numerica">
            <thead>
                <tr>
                    <th>Confirmado el</th>
                    <th>Participante</th>
                    <th>Procedencia</th>
                    <th>Grado</th>
                    <th>Estado</th>
                    <th>Medio</th>
                    <th>Código Yape</th>
                    <th>Confirmó</th>
                    <th class="celda--numero">Monto</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($filas as $fila): ?>
                    <?php
                    $pagada = $fila['fecha_pago'] !== null;
                    $copia  = $pagada && (int) $fila['pago_contado'] === 0;
                    ?>
                    <tr id="cob-<?= (int) $fila['id'] ?>">
                        <td class="tabla__principal" data-etiqueta="Confirmado el">
                            <?php if ($pagada): ?>
                                <?= View::e(Fecha::mostrar($fila['fecha_pago'])) ?>
                                <?php if ($copia): ?>
                                    <span class="etiqueta etiqueta--alerta">ya contado</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="celda__nota">sin cobrar</span>
                            <?php endif; ?>
                        </td>

                        <td class="mayus" data-etiqueta="Participante">
                            <?= View::e($fila['ap_paterno'] . ' ' . $fila['ap_materno'] . ', ' . $fila['nombres']) ?>
                            <span class="celda__nota">
                                <?= View::e($fila['codigo_correlativo']) ?> · <?= View::e($fila['dni']) ?>
                            </span>
                        </td>

                        <td data-etiqueta="Procedencia">
                            <span class="mayus"><?= View::e($fila['tipo_participante'] === 'libre'
                                ? 'Libre'
                                : (string) ($fila['institucion'] ?? '—')) ?></span>
                            <span class="celda__nota">
                                <?= View::e($modalidades[(string) $fila['tipo_origen']] ?? (string) $fila['tipo_origen']) ?>
                            </span>
                        </td>

                        <td data-etiqueta="Grado">
                            <?= View::e(ucfirst((string) $fila['nivel']) . ' ' . (int) $fila['grado'] . '°') ?>
                        </td>

                        <td data-etiqueta="Estado">
                            <span class="etiqueta etiqueta--estado-<?= View::e((string) $fila['estado']) ?>">
                                <?= View::e($fila['estado']) ?>
                            </span>
                            <?php if (!empty($fila['requiere_devolucion'])): ?>
                                <span class="etiqueta etiqueta--alerta">por devolver</span>
                            <?php endif; ?>
                        </td>

                        <td data-etiqueta="Medio">
                            <?= $pagada
                                ? View::e($medios[(string) $fila['medio_pago']] ?? (string) $fila['medio_pago'])
                                : '—' ?>
                        </td>

                        <td data-etiqueta="Código Yape">
                            <?php if ($fila['yape_codigo_seguridad'] !== null): ?>
                                <code><?= View::e($fila['yape_codigo_seguridad']) ?></code>
                            <?php elseif ($fila['medio_pago'] !== null): ?>
                                <span class="celda__nota">no aplica</span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>

                        <td data-etiqueta="Confirmó">
                            <?php if ($fila['cobrador'] !== null): ?>
                                <span class="mayus"><?= View::e($fila['cobrador']) ?></span>
                            <?php elseif ($pagada): ?>
                                <span class="celda__nota">(sin firma)</span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>

                        <td class="celda--numero celda--fuerte" data-etiqueta="Monto">
                            <?= View::e($soles($fila['monto'])) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p class="nota">
        «Sin firma» son cobros anteriores al 19 de agosto, cuando el sistema todavía no
        guardaba quién confirmaba el pago (D-39). «No aplica» en el código de Yape es lo
        normal en transferencia y efectivo: ahí no existe ese dato.
    </p>

<?php endif; ?>

<script src="<?= View::e(View::asset('build/js/reportes.js')) ?>" defer></script>
