<?php
declare(strict_types=1);
require __DIR__ . '/_comun.php';

use App\Models\Carne;
use App\Models\Concurso;
use App\Models\Inscripcion;
use Core\Database;

$ok = 0; $mal = 0;
$c = static function (string $caso, $esp, $obt) use (&$ok, &$mal): void {
    if ($esp === $obt) { $ok++; echo "  OK    {$caso}\n"; }
    else { $mal++; echo "  FALLA {$caso}: esperaba " . var_export($esp, true) . ", obtuvo " . var_export($obt, true) . "\n"; }
};

$pdo = Database::conexion();
$pdo->beginTransaction();
try {
    $con = (int) Concurso::vigente()['id'];

    // --- Caso 1: pendiente anulada definitivamente ----------------------
    // Se CREA la pendiente en vez de buscar una real: al cobrarse el lote del
    // 21-ago no quedó ninguna y esta prueba se puso roja sola (ver `_comun.php`).
    $pendienteId = inscripcionPendienteDePrueba(idAdministrador());
    $ins = Inscripcion::porId($pendienteId);
    Inscripcion::anular($pendienteId, 'Prueba', true, (int) $ins['usuario_id']);

    $c('queda fuera: sin inscripción viva', null,
        Inscripcion::activaDe((int) $ins['participante_id']));

    $fila = null;
    foreach (Inscripcion::listar($con) as $f) { if ((int) $f['id'] === $pendienteId) { $fila = $f; } }
    $c('el listado lo marca como reinscribible', 0, (int) $fila['participante_activo']);

    $nueva = Inscripcion::crear([
        'participante_id' => (int) $ins['participante_id'],
        'categoria_id' => (int) $ins['categoria_id'], 'usuario_id' => (int) $ins['usuario_id'],
        'estado' => 'pendiente', 'tipo_origen' => $ins['tipo_origen'], 'monto' => (float) $ins['monto'],
    ]);
    $c('reinscrito: vuelve a tener inscripción viva', $nueva,
        (int) Inscripcion::activaDe((int) $ins['participante_id'])['id']);

    foreach (Inscripcion::listar($con) as $f) { if ((int) $f['id'] === $pendienteId) { $fila = $f; } }
    $c('ya no se ofrece reinscribir sobre la anulada', 1, (int) $fila['participante_activo']);

    // --- Caso 2: confirmada anulada definitivamente ---------------------
    $q = Database::uno("SELECT id FROM inscripciones WHERE estado = 'confirmada' AND fecha_pago IS NOT NULL LIMIT 1");
    $pag = Inscripcion::porId((int) $q['id']);
    Inscripcion::anular((int) $q['id'], 'Prueba pagada', true, (int) $pag['usuario_id']);
    $anulada = Inscripcion::porId((int) $q['id']);

    $c('el pago entra al fondo de devoluciones', 1, (int) $anulada['requiere_devolucion']);
    $c('la anulación conserva fecha_pago', $pag['fecha_pago'], $anulada['fecha_pago']);

    $nueva2 = Inscripcion::crear([
        'participante_id' => (int) $anulada['participante_id'],
        'categoria_id' => (int) $anulada['categoria_id'], 'usuario_id' => (int) $anulada['usuario_id'],
        'estado' => 'confirmada', 'tipo_origen' => $anulada['tipo_origen'],
        'monto' => (float) $anulada['monto'], 'medio_pago' => $anulada['medio_pago'],
        'yape_codigo_seguridad' => $anulada['yape_codigo_seguridad'], 'fecha_pago' => $anulada['fecha_pago'],
    ]);
    Carne::registrar($nueva2, (string) $anulada['codigo_correlativo']);
    Inscripcion::limpiarDevolucion((int) $q['id']);

    $recreada = Inscripcion::porId($nueva2);
    $c('la nueva conserva el medio de pago', $pag['medio_pago'], $recreada['medio_pago']);
    $c('la nueva conserva el código de Yape', $pag['yape_codigo_seguridad'], $recreada['yape_codigo_seguridad']);
    $c('la nueva conserva la fecha de pago', $pag['fecha_pago'], $recreada['fecha_pago']);
    $c('la nueva tiene carné emitido', true, Carne::porInscripcion($nueva2) !== null);
    $c('sale del fondo de devoluciones', 0,
        (int) Inscripcion::porId((int) $q['id'])['requiere_devolucion']);
    $c('no queda en el reporte de devoluciones', false, in_array((int) $q['id'],
        array_map('intval', array_column(Inscripcion::fondoDevoluciones($con), 'id')), true));

    // --- Caso 3: la nota no borra el motivo original --------------------
    Inscripcion::anotarEnAnulacion((int) $q['id'], 'Reinscrito: se anuló por error');
    $motivo = (string) Inscripcion::porId((int) $q['id'])['motivo_anulacion'];
    $c('conserva el motivo original', true, str_contains($motivo, 'Prueba pagada'));
    $c('añade la nota de reinscripción', true, str_contains($motivo, 'se anuló por error'));
} finally { $pdo->rollBack(); }

echo "\n{$ok} correctas, {$mal} fallidas\n";
exit($mal === 0 ? 0 : 1);
