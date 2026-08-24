<?php

declare(strict_types=1);

use Core\Fecha;
use Core\View;

/** @var array<string, mixed> $concurso */
/** @var bool $esPropia */
/** @var array<int, array<string, mixed>> $filas */
/** @var array<int, array<string, mixed>> $operaciones */

$soles = static fn (mixed $monto): string => 'S/ ' . number_format((float) $monto, 2);

$medios = [
    'yape'          => 'Yape',
    'transferencia' => 'Transferencia',
    'efectivo'      => 'Efectivo',
];

/*
 * Los totales se suman aquí y no en SQL a propósito: son la suma de lo que
 * la pantalla ESTÁ enseñando. Un total que viene de su propia consulta puede
 * discrepar de las filas de arriba —por un filtro que se aplicó a una y no a
 * la otra— y entonces el reporte se contradice a sí mismo delante de quien lo
 * firma.
 */
$total = ['n_yape' => 0, 'n_transferencia' => 0, 'n_efectivo' => 0, 'n_total' => 0,
          'monto_yape' => 0.0, 'monto_transferencia' => 0.0, 'monto_efectivo' => 0.0,
          'monto_total' => 0.0];

foreach ($filas as $fila) {
    foreach ($total as $clave => $_) {
        $total[$clave] += str_starts_with($clave, 'n_')
            ? (int) $fila[$clave]
            : (float) $fila[$clave];
    }
}

$hayCobrosSinFirma = false;

foreach ($filas as $fila) {
    if ($fila['confirmado_por'] === null) {
        $hayCobrosSinFirma = true;
    }
}
?>
<div class="encabezado">
    <div>
        <h1 class="titulo">Arqueo de caja</h1>
        <p class="subtitulo">
            <?= $esPropia ? 'Tu cierre de caja' : 'Todas las cajas del concurso' ?>
        </p>
    </div>
    <div class="acciones no-imprimir">
        <button type="button" class="boton boton--tenue" data-imprimir>Imprimir</button>
    </div>
</div>

<?= View::parcial("reporte-nav", ["actual" => "/reportes/caja"]) ?>

<?= View::parcial("reporte-identidad", [
    'rotulo'   => $esPropia ? 'Arqueo de caja — cierre propio' : 'Arqueo de caja — todas las cajas',
    'concurso' => (string) ($concurso['nombre'] ?? ''),
    'corte'    => 'Dinero recibido y todavía en poder de la organización. '
                . 'Cada estudiante que pagó cuenta una sola vez, aunque su inscripción '
                . 'se haya anulado o reinscrito después.',
]) ?>

<?php if ($filas === []): ?>

    <div class="aviso aviso--aviso">
        <?= $esPropia
            ? 'Todavía no has confirmado ningún pago, así que no hay nada que arquear.'
            : 'Todavía no se ha confirmado ningún pago en este concurso.' ?>
    </div>

<?php else: ?>

    <h2 class="subtitulo-seccion">Por cobrador y medio de pago</h2>

    <div class="tabla-contenedor">
        <table class="tabla tabla--numerica">
            <thead>
                <tr>
                    <th>Cobrador</th>
                    <th class="celda--numero">Yape</th>
                    <th class="celda--numero">Transferencia</th>
                    <th class="celda--numero">Efectivo</th>
                    <th class="celda--numero">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($filas as $fila): ?>
                    <tr>
                        <td class="tabla__principal<?= $fila['confirmado_por'] === null ? '' : ' mayus' ?>">
                            <?= View::e($fila['cobrador']) ?>
                        </td>
                        <?php foreach (['yape', 'transferencia', 'efectivo'] as $medio): ?>
                            <td class="celda--numero" data-etiqueta="<?= View::e($medios[$medio]) ?>">
                                <?= View::e($soles($fila['monto_' . $medio])) ?>
                                <span class="celda__nota"><?= (int) $fila['n_' . $medio ] ?></span>
                            </td>
                        <?php endforeach; ?>
                        <td class="celda--numero celda--fuerte" data-etiqueta="Total">
                            <?= View::e($soles($fila['monto_total'])) ?>
                            <span class="celda__nota"><?= (int) $fila['n_total'] ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th>Total recibido</th>
                    <?php foreach (['yape', 'transferencia', 'efectivo'] as $medio): ?>
                        <td class="celda--numero celda--fuerte" data-etiqueta="<?= View::e($medios[$medio]) ?>">
                            <?= View::e($soles($total['monto_' . $medio])) ?>
                            <span class="celda__nota"><?= (int) $total['n_' . $medio] ?></span>
                        </td>
                    <?php endforeach; ?>
                    <td class="celda--numero celda--fuerte" data-etiqueta="Total">
                        <?= View::e($soles($total['monto_total'])) ?>
                        <span class="celda__nota"><?= (int) $total['n_total'] ?></span>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>

    <p class="nota">
        La cifra pequeña es el número de inscripciones cobradas, no el de operaciones:
        una delegación paga por treinta estudiantes con un solo Yape.
    </p>

    <?php if ($hayCobrosSinFirma): ?>
        <div class="aviso aviso--aviso">
            <strong>«(sin firma)»</strong> son cobros cuya inscripción no guarda quién los
            confirmó: los anteriores al 19 de agosto, cuando el sistema todavía no lo
            registraba (D-39). No se reparten entre los demás cobradores —eso sería
            inventar quién recibió un dinero—, pero <strong>sí suman al total</strong>:
            esa plata entró.
        </div>
    <?php endif; ?>

    <h2 class="subtitulo-seccion">Operaciones, para conciliar</h2>

    <p class="nota">
        <strong>Esta agrupación es una reconstrucción, no un registro.</strong> El sistema
        no guarda la operación de cobro, sino cada inscripción por separado; aquí se juntan
        las que confirmó la misma persona, con el mismo medio, el mismo código y en el mismo
        minuto — que es lo que ocurre al pulsar «Confirmar» una vez. Debajo de cada una van
        <strong>los participantes que la componen</strong>, para poder cotejarla contra la
        nómina de la delegación y contra el extracto del banco.
    </p>

    <?php
    // «primaria 4» → «Primaria 4°». El dato viene así de la base para no atar el
    // SQL a una forma de escribirlo.
    $grado = static function (?string $categoria): string {
        if ($categoria === null || $categoria === '') {
            return '—';
        }

        [$nivel, $numero] = array_pad(explode(' ', $categoria, 2), 2, '');

        return ucfirst($nivel) . ' ' . $numero . '°';
    };
    ?>

    <div class="operaciones">
        <?php foreach ($operaciones as $op): ?>
            <article class="operacion">
                <header class="operacion__cabecera">
                    <div>
                        <span class="operacion__momento"><?= View::e(Fecha::mostrar($op['momento'])) ?></span>
                        <span class="operacion__datos">
                            <span class="<?= $op['confirmado_por'] === null ? '' : 'mayus' ?>">
                                <?= View::e($op['cobrador']) ?>
                            </span>
                            ·
                            <?= View::e($medios[(string) $op['medio_pago']] ?? (string) $op['medio_pago']) ?>
                            <?php if ($op['yape_codigo_seguridad'] !== null): ?>
                                · código <code><?= View::e($op['yape_codigo_seguridad']) ?></code>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="operacion__totales">
                        <span class="operacion__monto"><?= View::e($soles($op['monto'])) ?></span>
                        <span class="operacion__cuenta">
                            <?= (int) $op['cantidad'] ?>
                            inscripcion<?= (int) $op['cantidad'] === 1 ? '' : 'es' ?>
                        </span>
                    </div>
                </header>

                <?php if (count($op['procedencias']) > 1): ?>
                    <p class="operacion__aviso">
                        Toca <?= count($op['procedencias']) ?> procedencias distintas: es muy
                        probable que aquí se hayan juntado <strong>varios cobros reales</strong>
                        confirmados de una sola vez, y no una única operación bancaria.
                    </p>
                <?php endif; ?>

                <ol class="operacion__lista">
                    <?php foreach ($op['participantes'] as $n => $p): ?>
                        <li class="operacion__item">
                            <span class="operacion__n"><?= $n + 1 ?></span>
                            <span class="operacion__nombre mayus">
                                <?= View::e($p['ap_paterno'] . ' ' . $p['ap_materno'] . ', ' . $p['nombres']) ?>
                            </span>
                            <span class="operacion__codigo"><?= View::e($p['codigo_correlativo']) ?></span>
                            <span class="operacion__grado"><?= View::e($grado($p['categoria'])) ?></span>
                            <span class="operacion__procedencia mayus"><?= View::e($p['procedencia']) ?></span>
                            <span class="operacion__importe"><?= View::e($soles($p['monto'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </article>
        <?php endforeach; ?>
    </div>

    <p class="nota">
        Transferencia y efectivo no llevan ninguna referencia externa, así que solo se
        concilian por importe y fecha. El código de Yape es de tres dígitos y
        <strong>se repite</strong> en todas las inscripciones de un mismo cobro: identifica
        la operación en la aplicación del banco, no a un estudiante.
    </p>

    <?php /*
       El pie de entrega. Es lo que convierte la pantalla en un documento: quien
       entrega el dinero y quien lo recibe firman el mismo papel, con el total
       que tienen delante. Solo se ve al imprimir.
    */ ?>
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
