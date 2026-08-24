<?php

declare(strict_types=1);

use Core\Fecha;
use Core\View;

/** @var array<string, mixed> $concurso */
/** @var array<string, mixed> $saldos */
/** @var array<int, array<string, mixed>> $sinReasignar */

$soles = static fn (mixed $monto): string => 'S/ ' . number_format((float) $monto, 2);

/*
 * Las tres líneas del dinero que YA entró. La suma de las tres es el cobrado
 * bruto, y esa igualdad es el cuadre: si alguna vez no se cumpliera, el reporte
 * estaría dejando dinero fuera de alguna categoría.
 */
$reparto = [
    'en_firme' => [
        'rotulo' => 'En firme',
        'nota'   => 'Inscripciones vivas y pagadas. Es el dinero que se queda.',
    ],
    'por_devolver' => [
        'rotulo' => 'Por devolver',
        'nota'   => 'Anulaciones definitivas de algo ya pagado. Es el fondo de devoluciones.',
    ],
    'sin_reasignar' => [
        'rotulo' => 'Cobrado sin reasignar',
        'nota'   => 'Anuladas para reinscribir que todavía no se reinscribieron. '
                  . 'El dinero está en el cajón, esperando.',
    ],
];

$sumaReparto = 0.0;

foreach (array_keys($reparto) as $clave) {
    $sumaReparto += (float) $saldos[$clave]['monto'];
}

// El cuadre, comprobado en ejecución y no dado por hecho. Si un día deja de
// cerrar, el reporte tiene que decirlo en la cara y no callárselo.
$cuadra = abs($sumaReparto - (float) $saldos['bruto']) < 0.005;
?>
<div class="encabezado">
    <div>
        <h1 class="titulo">Estado de la caja</h1>
        <p class="subtitulo"><?= View::e((string) ($concurso['nombre'] ?? '')) ?></p>
    </div>
    <div class="acciones no-imprimir">
        <button type="button" class="boton boton--tenue" data-imprimir>Imprimir</button>
    </div>
</div>

<?= View::parcial("reporte-nav", ["actual" => "/reportes/saldos"]) ?>

<?= View::parcial("reporte-identidad", [
    'rotulo'   => 'Estado de la caja',
    'concurso' => (string) ($concurso['nombre'] ?? ''),
    'corte'    => 'Todo el concurso. Cada estudiante que pagó cuenta una sola vez, '
                . 'aunque su inscripción se haya anulado o reinscrito después.',
]) ?>

<section class="metricas">
    <div class="metrica metrica--destacada">
        <span class="metrica__valor"><?= View::e($soles($saldos['en_poder'])) ?></span>
        <span class="metrica__nombre">En poder de la organización</span>
    </div>
    <div class="metrica">
        <span class="metrica__valor"><?= View::e($soles($saldos['en_firme']['monto'])) ?></span>
        <span class="metrica__nombre">En firme</span>
    </div>
    <div class="metrica">
        <span class="metrica__valor"><?= View::e($soles($saldos['por_devolver']['monto'])) ?></span>
        <span class="metrica__nombre">Por devolver</span>
    </div>
    <div class="metrica">
        <span class="metrica__valor"><?= View::e($soles($saldos['por_cobrar']['monto'])) ?></span>
        <span class="metrica__nombre">Por cobrar</span>
    </div>
</section>

<h2 class="subtitulo-seccion">El cuadre</h2>

<div class="tabla-contenedor">
    <table class="tabla tabla--numerica">
        <thead>
            <tr>
                <th>Concepto</th>
                <th class="celda--numero">Inscripciones</th>
                <th class="celda--numero">Monto</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($reparto as $clave => $linea): ?>
                <tr>
                    <td class="tabla__principal">
                        <?= View::e($linea['rotulo']) ?>
                        <span class="celda__nota"><?= View::e($linea['nota']) ?></span>
                    </td>
                    <td class="celda--numero" data-etiqueta="Inscripciones">
                        <?= (int) $saldos[$clave]['n'] ?>
                    </td>
                    <td class="celda--numero" data-etiqueta="Monto">
                        <?= View::e($soles($saldos[$clave]['monto'])) ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <tr class="fila--suma">
                <td class="tabla__principal">Cobrado bruto</td>
                <td class="celda--numero" data-etiqueta="Inscripciones">
                    <?= (int) ($saldos['en_firme']['n']
                             + $saldos['por_devolver']['n']
                             + $saldos['sin_reasignar']['n']) ?>
                </td>
                <td class="celda--numero celda--fuerte" data-etiqueta="Monto">
                    <?= View::e($soles($saldos['bruto'])) ?>
                </td>
            </tr>

            <tr>
                <td class="tabla__principal">
                    Devoluciones efectuadas
                    <span class="celda__nota">
                        El sistema no las registra todavía: al reinscribir o al saldar una
                        devolución se borra el marcador sin dejar rastro de quién entregó
                        el dinero ni cuándo. La línea sale en cero para que el cuadre no
                        mienta por omisión, no porque se sepa que no se devolvió nada.
                    </span>
                </td>
                <td class="celda--numero" data-etiqueta="Inscripciones">—</td>
                <td class="celda--numero" data-etiqueta="Monto">
                    <?= View::e($soles($saldos['devuelto'])) ?>
                </td>
            </tr>
        </tbody>
        <tfoot>
            <tr>
                <th>En poder de la organización</th>
                <td class="celda--numero" data-etiqueta="Inscripciones">—</td>
                <td class="celda--numero celda--fuerte" data-etiqueta="Monto">
                    <?= View::e($soles($saldos['en_poder'])) ?>
                </td>
            </tr>
            <tr>
                <th>Por cobrar (pendientes)</th>
                <td class="celda--numero" data-etiqueta="Inscripciones">
                    <?= (int) $saldos['por_cobrar']['n'] ?>
                </td>
                <td class="celda--numero" data-etiqueta="Monto">
                    <?= View::e($soles($saldos['por_cobrar']['monto'])) ?>
                </td>
            </tr>
        </tfoot>
    </table>
</div>

<?php if (!$cuadra): ?>
    <div class="aviso aviso--error">
        <strong>El cuadre no cierra.</strong> La suma de las tres líneas del reparto da
        <?= View::e($soles($sumaReparto)) ?> y el cobrado bruto dice
        <?= View::e($soles($saldos['bruto'])) ?>. No uses este reporte para entregar
        dinero: avisa antes de seguir.
    </div>
<?php endif; ?>

<p class="nota">
    «En poder de la organización» es lo que debería estar hoy entre el Yape, la cuenta
    bancaria y el efectivo de caja, sumados. No es lo que se queda el concurso: de ahí
    salen las devoluciones y el dinero que espera reasignarse.
</p>

<h2 class="subtitulo-seccion">
    Cobrado sin reasignar
    <span class="etiqueta"><?= count($sinReasignar) ?></span>
</h2>

<?php if ($sinReasignar === []): ?>

    <p class="nota">
        No hay ninguno. Toda inscripción anulada que había pagado está o reinscrita, o en
        el fondo de devoluciones.
    </p>

<?php else: ?>

    <p class="nota">
        Estas inscripciones se anularon <strong>para reinscribir</strong> y todavía no se
        reinscribieron, así que su pago no aparece en ninguna otra cuenta. No van al fondo
        de devoluciones a propósito: ese dinero no se devuelve, se reutiliza, y pedir que
        se entregue haría que el concurso lo pagara dos veces.
    </p>

    <div class="tabla-contenedor">
        <table class="tabla">
            <thead>
                <tr>
                    <th>Participante</th>
                    <th>Procedencia</th>
                    <th>Cobró</th>
                    <th>Pagado el</th>
                    <th class="celda--numero">Monto</th>
                    <th>Motivo de la anulación</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sinReasignar as $fila): ?>
                    <tr>
                        <td class="tabla__principal mayus" data-etiqueta="Participante">
                            <?= View::e($fila['ap_paterno'] . ' ' . $fila['ap_materno'] . ', ' . $fila['nombres']) ?>
                            <span class="celda__nota"><?= View::e($fila['codigo_correlativo']) ?></span>
                        </td>
                        <td class="mayus" data-etiqueta="Procedencia">
                            <?= View::e($fila['tipo_participante'] === 'libre'
                                ? 'Libre'
                                : (string) ($fila['institucion'] ?? '—')) ?>
                        </td>
                        <td data-etiqueta="Cobró"
                            class="<?= $fila['cobrador'] === '(sin firma)' ? '' : 'mayus' ?>">
                            <?= View::e($fila['cobrador']) ?>
                        </td>
                        <td data-etiqueta="Pagado el">
                            <?= View::e(Fecha::mostrar($fila['fecha_pago'])) ?>
                        </td>
                        <td class="celda--numero celda--fuerte" data-etiqueta="Monto">
                            <?= View::e($soles($fila['monto'])) ?>
                        </td>
                        <td data-etiqueta="Motivo de la anulación">
                            <?= View::e((string) ($fila['motivo_anulacion'] ?? '—')) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php endif; ?>

<script src="<?= View::e(View::asset('build/js/reportes.js')) ?>" defer></script>
