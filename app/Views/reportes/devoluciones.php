<?php

declare(strict_types=1);

use Core\Fecha;
use Core\View;

/** @var array<string, mixed> $concurso */
/** @var array<int, array<string, mixed>> $filas */

$soles = static fn (mixed $monto): string => 'S/ ' . number_format((float) $monto, 2);

$medios = [
    'yape'          => 'Yape',
    'transferencia' => 'Transferencia',
    'efectivo'      => 'Efectivo',
];

$total = 0.0;

foreach ($filas as $fila) {
    $total += (float) $fila['monto'];
}
?>
<div class="encabezado">
    <div>
        <h1 class="titulo">Fondo de devoluciones</h1>
        <p class="subtitulo"><?= View::e((string) ($concurso['nombre'] ?? '')) ?></p>
    </div>
    <div class="acciones no-imprimir">
        <button type="button" class="boton boton--tenue" data-imprimir>Imprimir</button>
    </div>
</div>

<?= View::parcial("reporte-nav", ["actual" => "/reportes/devoluciones"]) ?>

<?= View::parcial("reporte-identidad", [
    'rotulo'   => 'Fondo de devoluciones',
    'concurso' => (string) ($concurso['nombre'] ?? ''),
    'corte'    => 'Inscripciones anuladas de forma definitiva que ya habían pagado. '
                . 'Las anuladas para reinscribir no entran: su dinero se reutiliza.',
]) ?>

<?php if ($filas === []): ?>

    <div class="aviso aviso--exito">
        No hay ninguna devolución pendiente.
    </div>

<?php else: ?>

    <section class="metricas">
        <div class="metrica metrica--destacada">
            <span class="metrica__valor"><?= View::e($soles($total)) ?></span>
            <span class="metrica__nombre">Total por devolver</span>
        </div>
        <div class="metrica">
            <span class="metrica__valor"><?= count($filas) ?></span>
            <span class="metrica__nombre">Personas</span>
        </div>
    </section>

    <div class="tabla-contenedor">
        <table class="tabla">
            <thead>
                <tr>
                    <th>Participante</th>
                    <th>Procedencia</th>
                    <th>Grado</th>
                    <th>Medio del cobro</th>
                    <th>Pagado el</th>
                    <th class="celda--numero">Monto</th>
                    <th>Motivo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($filas as $fila): ?>
                    <tr>
                        <td class="tabla__principal mayus" data-etiqueta="Participante">
                            <?= View::e($fila['ap_paterno'] . ' ' . $fila['ap_materno'] . ', ' . $fila['nombres']) ?>
                            <span class="celda__nota"><?= View::e($fila['codigo_correlativo']) ?></span>
                        </td>
                        <td class="mayus" data-etiqueta="Procedencia">
                            <?= View::e((string) ($fila['institucion'] ?? 'Libre')) ?>
                        </td>
                        <td data-etiqueta="Grado">
                            <?= View::e(ucfirst((string) $fila['nivel']) . ' ' . (int) $fila['grado'] . '°') ?>
                        </td>
                        <td data-etiqueta="Medio del cobro">
                            <?= View::e($medios[(string) $fila['medio_pago']] ?? '—') ?>
                        </td>
                        <td data-etiqueta="Pagado el">
                            <?= View::e(Fecha::mostrar($fila['fecha_pago'])) ?>
                        </td>
                        <td class="celda--numero celda--fuerte" data-etiqueta="Monto">
                            <?= View::e($soles($fila['monto'])) ?>
                        </td>
                        <td data-etiqueta="Motivo">
                            <?= View::e((string) ($fila['motivo_anulacion'] ?? '—')) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="5">Total por devolver</th>
                    <td class="celda--numero celda--fuerte" data-etiqueta="Total">
                        <?= View::e($soles($total)) ?>
                    </td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="aviso aviso--aviso no-imprimir">
        <strong>Marcar una devolución como entregada todavía no existe.</strong> Cuando se
        devuelva el dinero, esta fila seguirá aquí: el sistema no tiene dónde anotar quién
        lo entregó, cuándo ni por qué medio. Hasta que exista, la constancia es de papel —
        y conviene que la firme quien recibe.
    </div>

    <div class="reporte-firmas solo-imprimir">
        <div class="reporte-firmas__linea">
            <span>Entregué conforme</span>
        </div>
        <div class="reporte-firmas__linea">
            <span>Recibí conforme</span>
        </div>
    </div>

<?php endif; ?>

<script src="<?= View::e(View::asset('build/js/reportes.js')) ?>" defer></script>
