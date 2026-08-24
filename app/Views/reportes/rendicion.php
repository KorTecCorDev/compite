<?php

declare(strict_types=1);

use Core\Fecha;
use Core\View;

/** @var array<string, mixed> $r  todo lo que arma App\Servicios\Rendicion */

$soles = static fn (mixed $m): string => 'S/ ' . number_format((float) $m, 2);

$rotulos = [
    'yape' => 'Yape', 'transferencia' => 'Transferencia', 'efectivo' => 'Efectivo',
    'publica' => 'I.E. pública', 'privada' => 'I.E. privada',
    'libre' => 'Estudiante libre', 'organizadora' => 'I.E. organizadora (COCIAP)',
];

/*
 * El cuadre, comprobado en ejecución sobre los cuatro desgloses.
 *
 * No es adorno: los cuatro salen del mismo conjunto de filas, así que si alguna
 * vez no cuadraran significaría que una agrupación está perdiendo o repitiendo
 * filas. Un documento que se firma tiene que poder decirlo en la cara.
 */
$suma = static function (array $filas): float {
    $t = 0.0;
    foreach ($filas as $f) { $t += (float) $f['soles']; }
    return $t;
};

$descuadres = [];

foreach (['por día' => $r['por_dia'], 'por medio' => $r['por_medio'],
          'por modalidad' => $r['por_modalidad'], 'por cobrador' => $r['por_cobrador']] as $eje => $filas) {
    if (abs($suma($filas) - (float) $r['bruto']) >= 0.005) {
        $descuadres[] = $eje;
    }
}

$competidores = (int) $r['recuento']['confirmadas'] - count($r['duplicados']);
?>
<div class="encabezado">
    <div>
        <h1 class="titulo">Rendición de cuentas</h1>
        <p class="subtitulo"><?= View::e((string) ($r['concurso']['nombre'] ?? '')) ?></p>
    </div>
    <div class="acciones no-imprimir">
        <button type="button" class="boton boton--tenue" data-imprimir>Imprimir</button>
    </div>
</div>

<?= View::parcial('reporte-nav', ['actual' => '/reportes/rendicion']) ?>

<?= View::parcial('reporte-identidad', [
    'rotulo'   => 'Rendición de cuentas del concurso',
    'concurso' => (string) ($r['concurso']['nombre'] ?? ''),
    'corte'    => 'Concurso cerrado. Cada pago se cuenta una sola vez y las horas están '
                . 'expresadas en hora de Ancash. Las observaciones del Anexo I no se '
                . 'corrigieron en la base: se declaran.',
]) ?>

<?php if ($descuadres !== []): ?>
    <div class="aviso aviso--error">
        <strong>El documento no cuadra</strong> en: <?= View::e(implode(', ', $descuadres)) ?>.
        No lo firmes: avisa antes.
    </div>
<?php endif; ?>

<section class="metricas">
    <div class="metrica metrica--destacada">
        <span class="metrica__valor"><?= View::e($soles($r['neto'])) ?></span>
        <span class="metrica__nombre">Ingreso legítimo</span>
    </div>
    <div class="metrica">
        <span class="metrica__valor"><?= View::e($soles($r['bruto'])) ?></span>
        <span class="metrica__nombre">Recaudado bruto</span>
    </div>
    <div class="metrica">
        <span class="metrica__valor"><?= (int) $competidores ?></span>
        <span class="metrica__nombre">Competidores efectivos</span>
    </div>
    <div class="metrica">
        <span class="metrica__valor"><?= (int) $r['padron'] ?></span>
        <span class="metrica__nombre">Personas en el padrón</span>
    </div>
</section>

<h2 class="subtitulo-seccion">1 · Cadena de conciliación</h2>

<p class="nota">
    De la cantidad de filas registradas al dinero que le corresponde al concurso, sin saltos.
    Cada resta remite al anexo donde está su detalle.
</p>

<div class="tabla-contenedor">
    <table class="tabla tabla--numerica">
        <thead>
            <tr>
                <th>Concepto</th>
                <th class="celda--numero">Cantidad</th>
                <th class="celda--numero">Importe</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="tabla__principal">Inscripciones registradas</td>
                <td class="celda--numero" data-etiqueta="Cantidad"><?= (int) $r['recuento']['inscripciones'] ?></td>
                <td class="celda--numero" data-etiqueta="Importe">—</td>
            </tr>
            <tr>
                <td class="tabla__principal">
                    (−) Anuladas
                    <span class="celda__nota">Detalle en el Anexo II.</span>
                </td>
                <td class="celda--numero" data-etiqueta="Cantidad">−<?= (int) $r['recuento']['anuladas'] ?></td>
                <td class="celda--numero" data-etiqueta="Importe">—</td>
            </tr>
            <?php if ($r['recuento']['pendientes'] > 0): ?>
                <tr>
                    <td class="tabla__principal">(−) Pendientes de pago al cierre</td>
                    <td class="celda--numero" data-etiqueta="Cantidad">−<?= (int) $r['recuento']['pendientes'] ?></td>
                    <td class="celda--numero" data-etiqueta="Importe">—</td>
                </tr>
            <?php endif; ?>
            <tr class="fila--suma">
                <td class="tabla__principal">Inscripciones confirmadas y cobradas</td>
                <td class="celda--numero" data-etiqueta="Cantidad"><?= (int) $r['recuento']['confirmadas'] ?></td>
                <td class="celda--numero celda--fuerte" data-etiqueta="Importe"><?= View::e($soles($r['bruto'])) ?></td>
            </tr>
            <tr>
                <td class="tabla__principal">
                    (−) Cobros duplicados a la misma persona
                    <span class="celda__nota">
                        Dinero recibido que no le corresponde al concurso: está pendiente de
                        devolución. Detalle en el Anexo I.
                    </span>
                </td>
                <td class="celda--numero" data-etiqueta="Cantidad">−<?= count($r['duplicados']) ?></td>
                <td class="celda--numero" data-etiqueta="Importe">−<?= View::e($soles($r['indebido'])) ?></td>
            </tr>
        </tbody>
        <tfoot>
            <tr>
                <th>Competidores efectivos e ingreso legítimo</th>
                <td class="celda--numero celda--fuerte" data-etiqueta="Cantidad"><?= (int) $competidores ?></td>
                <td class="celda--numero celda--fuerte" data-etiqueta="Importe"><?= View::e($soles($r['neto'])) ?></td>
            </tr>
        </tfoot>
    </table>
</div>

<h2 class="subtitulo-seccion">2 · Recaudación</h2>

<p class="nota">
    Los cuatro desgloses salen del mismo conjunto de cobros, así que los cuatro suman
    <strong><?= View::e($soles($r['bruto'])) ?></strong>. Las fechas son <strong>hora de
    Ancash</strong>: tal como el servidor las guarda están cinco horas adelantadas, y sin
    corregirlas los cobros del viernes por la noche se contarían como del sábado.
</p>

<?php
$tablas = [
    'Por día'       => ['filas' => $r['por_dia'],       'clave' => 'dia',   'titulo' => 'Día'],
    'Por medio de pago' => ['filas' => $r['por_medio'],  'clave' => 'clave', 'titulo' => 'Medio'],
    'Por modalidad' => ['filas' => $r['por_modalidad'],  'clave' => 'clave', 'titulo' => 'Modalidad'],
    'Por cobrador'  => ['filas' => $r['por_cobrador'],   'clave' => 'clave', 'titulo' => 'Cobrador'],
];
?>

<div class="rendicion-cuadros">
    <?php foreach ($tablas as $nombre => $t): ?>
        <div class="tabla-contenedor">
            <table class="tabla tabla--numerica">
                <thead>
                    <tr>
                        <th><?= View::e($t['titulo']) ?></th>
                        <th class="celda--numero">Cobros</th>
                        <th class="celda--numero">Importe</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($t['filas'] as $fila): ?>
                        <?php
                        $valor = (string) $fila[$t['clave']];
                        $texto = $t['clave'] === 'dia'
                            ? Fecha::dia($valor)
                            : ($rotulos[$valor] ?? $valor);
                        ?>
                        <tr>
                            <td class="tabla__principal"><?= View::e($texto) ?></td>
                            <td class="celda--numero" data-etiqueta="Cobros"><?= (int) $fila['cobros'] ?></td>
                            <td class="celda--numero" data-etiqueta="Importe"><?= View::e($soles($fila['soles'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th><?= View::e($nombre) ?> · total</th>
                        <td class="celda--numero celda--fuerte" data-etiqueta="Cobros">
                            <?= array_sum(array_map('intval', array_column($t['filas'], 'cobros'))) ?>
                        </td>
                        <td class="celda--numero celda--fuerte" data-etiqueta="Importe">
                            <?= View::e($soles($suma($t['filas']))) ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endforeach; ?>
</div>

<h2 class="subtitulo-seccion">Anexo I · Observaciones</h2>

<p class="nota">
    <strong>Nada de esto se corrigió en la base, y es deliberado.</strong> El registro del
    concurso es la prueba de lo que ocurrió: un asiento no se arregla borrándolo, se arregla
    declarándolo. Cada observación lleva su importe y lo que corresponde hacer.
</p>

<h3 class="subtitulo-anexo">I.1 · Cobros duplicados a la misma persona</h3>

<?php if ($r['duplicados'] === []): ?>
    <p class="nota">Ninguno.</p>
<?php else: ?>
    <div class="tabla-contenedor">
        <table class="tabla tabla--numerica">
            <thead>
                <tr>
                    <th>Persona</th>
                    <th>Procedencia y grado</th>
                    <th>Documentos</th>
                    <th>Inscripciones</th>
                    <th class="celda--numero">Cobrado</th>
                    <th class="celda--numero">Por devolver</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($r['duplicados'] as $d): ?>
                    <tr>
                        <td class="tabla__principal mayus"><?= View::e($d['nombre']) ?></td>
                        <td class="mayus" data-etiqueta="Procedencia y grado">
                            <?= View::e($d['institucion'] ?? 'Libre') ?>
                            <span class="celda__nota">
                                <?= View::e(ucfirst((string) $d['nivel']) . ' ' . (int) $d['grado'] . '°') ?>
                            </span>
                        </td>
                        <td data-etiqueta="Documentos"><code><?= View::e($d['documentos']) ?></code></td>
                        <td data-etiqueta="Inscripciones"><?= View::e($d['inscripciones']) ?></td>
                        <td class="celda--numero" data-etiqueta="Cobrado"><?= View::e($soles($d['cobrado'])) ?></td>
                        <td class="celda--numero celda--fuerte" data-etiqueta="Por devolver">
                            <?= View::e($soles($d['importe_indebido'])) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="5">Total por devolver</th>
                    <td class="celda--numero celda--fuerte" data-etiqueta="Total">
                        <?= View::e($soles($r['indebido'])) ?>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>

    <p class="nota">
        Mismo nombre completo, mismo colegio y mismo grado: es la misma persona inscrita dos
        veces, no dos homónimos. Además del dinero, cada caso deja <strong>un competidor de
        más</strong> en su bolsa. <strong>Acción:</strong> devolver el importe a la familia y
        dejar constancia firmada de la entrega.
    </p>
<?php endif; ?>

<h3 class="subtitulo-anexo">I.2 · Personas con el mismo nombre en el padrón</h3>

<?php if ($r['homonimos'] === []): ?>
    <p class="nota">Ninguna.</p>
<?php else: ?>
    <div class="tabla-contenedor">
        <table class="tabla">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Documentos</th>
                    <th>Códigos</th>
                    <th>Procedencias</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($r['homonimos'] as $h): ?>
                    <tr>
                        <td class="tabla__principal mayus"><?= View::e($h['nombre']) ?></td>
                        <td data-etiqueta="Documentos"><code><?= View::e($h['documentos']) ?></code></td>
                        <td data-etiqueta="Códigos"><span class="celda__nota"><?= View::e($h['codigos']) ?></span></td>
                        <td class="mayus" data-etiqueta="Procedencias"><?= View::e($h['procedencias']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p class="nota">
        <strong>No se descuenta nada por esto.</strong> Dos personas pueden llamarse igual, y
        decidir lo contrario mirando una tabla sería inventar. Se declaran para que puedan
        comprobarse: si los documentos difieren en uno o dos dígitos, conviene revisar cuál es
        el correcto antes de emitir cualquier constancia a nombre de esa persona.
    </p>
<?php endif; ?>

<h3 class="subtitulo-anexo">I.3 · Un mismo pago escrito en dos inscripciones</h3>

<?php if ($r['pagos_dobles'] === []): ?>
    <p class="nota">Ninguno.</p>
<?php else: ?>
    <div class="tabla-contenedor">
        <table class="tabla tabla--numerica">
            <thead>
                <tr>
                    <th>Persona</th>
                    <th>Código</th>
                    <th>Inscripciones</th>
                    <th class="celda--numero">Pago real</th>
                    <th class="celda--numero">Error si se contara dos veces</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($r['pagos_dobles'] as $p): ?>
                    <tr>
                        <td class="tabla__principal mayus"><?= View::e($p['nombre']) ?></td>
                        <td data-etiqueta="Código"><span class="celda__nota"><?= View::e($p['codigo_correlativo']) ?></span></td>
                        <td data-etiqueta="Inscripciones"><?= View::e($p['inscripciones']) ?></td>
                        <td class="celda--numero" data-etiqueta="Pago real"><?= View::e($soles($p['monto_real'])) ?></td>
                        <td class="celda--numero" data-etiqueta="Error potencial">
                            <?= View::e($soles($p['riesgo_de_doble_conteo'])) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p class="nota">
        <strong>No es dinero de más: entró una sola vez.</strong> Al reinscribir a alguien que
        ya había pagado, el sistema copia su pago a la inscripción nueva para que sepa cómo se
        cobró, y la anulada conserva el suyo. Las cifras de esta rendición ya cuentan cada pago
        una sola vez; la columna de la derecha dice de cuánto sería el error si alguien sumara
        la columna cruda. <strong>Acción:</strong> ninguna sobre el dinero; queda anotado para
        el diseño del próximo concurso.
    </p>
<?php endif; ?>

<h2 class="subtitulo-seccion">Anexo II · Anulaciones</h2>

<?php if ($r['anulaciones'] === []): ?>
    <p class="nota">No hubo ninguna.</p>
<?php else: ?>
    <div class="tabla-contenedor">
        <table class="tabla tabla--numerica">
            <thead>
                <tr>
                    <th>Persona</th>
                    <th>Procedencia</th>
                    <th>Motivo</th>
                    <th>¿Tenía pago?</th>
                    <th class="celda--numero">Importe</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($r['anulaciones'] as $a): ?>
                    <tr>
                        <td class="tabla__principal mayus">
                            <?= View::e($a['nombre']) ?>
                            <span class="celda__nota"><?= View::e($a['codigo_correlativo']) ?></span>
                        </td>
                        <td class="mayus" data-etiqueta="Procedencia"><?= View::e($a['procedencia']) ?></td>
                        <td data-etiqueta="Motivo"><?= View::e((string) ($a['motivo_anulacion'] ?? '—')) ?></td>
                        <td data-etiqueta="¿Tenía pago?">
                            <?php if ($a['fecha_pago'] === null): ?>
                                No
                            <?php else: ?>
                                Sí, <?= View::e(Fecha::mostrar($a['fecha_pago'], 'd/m/Y H:i')) ?>
                                <?php if (!empty($a['requiere_devolucion'])): ?>
                                    <span class="etiqueta etiqueta--alerta">por devolver</span>
                                <?php else: ?>
                                    <span class="celda__nota">reaplicado a su reinscripción</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="celda--numero" data-etiqueta="Importe"><?= View::e($soles($a['monto'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<h2 class="subtitulo-seccion">Anexo III · Padrón nominal</h2>

<p class="nota">
    Una fila por inscripción —<?= count($r['nominal']) ?> en total—, incluidas las anuladas,
    para que cualquier baja se pueda rastrear. «Ya contado» marca la copia de pago descrita en
    el Anexo I.3, que no suma.
</p>

<div class="tabla-contenedor">
    <table class="tabla tabla--numerica">
        <thead>
            <tr>
                <th>N°</th>
                <th>Apellidos y nombres</th>
                <th>Documento</th>
                <th>Procedencia</th>
                <th>Grado</th>
                <th>Estado</th>
                <th>Medio</th>
                <th>Cobrado el</th>
                <th class="celda--numero">Importe</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($r['nominal'] as $n => $fila): ?>
                <tr>
                    <td class="celda--numero"><?= $n + 1 ?></td>
                    <td class="tabla__principal mayus">
                        <?= View::e($fila['ap_paterno'] . ' ' . $fila['ap_materno'] . ', ' . $fila['nombres']) ?>
                        <span class="celda__nota"><?= View::e($fila['codigo_correlativo']) ?></span>
                    </td>
                    <td data-etiqueta="Documento"><?= View::e($fila['dni']) ?></td>
                    <td class="mayus" data-etiqueta="Procedencia"><?= View::e($fila['procedencia']) ?></td>
                    <td data-etiqueta="Grado">
                        <?= View::e(ucfirst((string) $fila['nivel']) . ' ' . (int) $fila['grado'] . '°') ?>
                    </td>
                    <td data-etiqueta="Estado">
                        <span class="etiqueta etiqueta--estado-<?= View::e((string) $fila['estado']) ?>">
                            <?= View::e($fila['estado']) ?>
                        </span>
                    </td>
                    <td data-etiqueta="Medio">
                        <?= View::e($fila['medio_pago'] === null ? '—' : ($rotulos[(string) $fila['medio_pago']] ?? '')) ?>
                    </td>
                    <td data-etiqueta="Cobrado el">
                        <?= View::e(Fecha::mostrar($fila['fecha_pago'])) ?>
                        <?php if ($fila['fecha_pago'] !== null && (int) $fila['pago_contado'] === 0): ?>
                            <span class="etiqueta etiqueta--alerta">ya contado</span>
                        <?php endif; ?>
                    </td>
                    <td class="celda--numero" data-etiqueta="Importe"><?= View::e($soles($fila['monto'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="reporte-firmas">
    <div class="reporte-firmas__linea"><span>Elaborado por</span></div>
    <div class="reporte-firmas__linea"><span>Recibido por dirección</span></div>
</div>

<script src="<?= View::e(View::asset('build/js/reportes.js')) ?>" defer></script>
