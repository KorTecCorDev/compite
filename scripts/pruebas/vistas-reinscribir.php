<?php
declare(strict_types=1);
require __DIR__ . '/_comun.php';

use App\Models\Concurso;
use App\Models\Inscripcion;
use Core\Database;
use Core\View;

$_SESSION['usuario_id'] = 1; $_SESSION['usuario_nombres'] = 'Prueba'; $_SESSION['usuario_rol'] = 'secretaria';
$_SESSION['ultimo_uso'] = time();

$pdo = Database::conexion();
$pdo->beginTransaction();
try {
    $con = (int) Concurso::vigente()['id'];
    $q = Database::uno("SELECT id FROM inscripciones WHERE estado='confirmada' AND fecha_pago IS NOT NULL LIMIT 1");
    Inscripcion::anular((int) $q['id'], 'Anulada por prueba', true, idAdministrador());

    $html = View::renderizar('inscripciones.reinscribir', [
        'titulo' => 'Reinscribir', 'inscripcion' => Inscripcion::porId((int) $q['id']),
        'categorias' => Concurso::categorias($con), 'errores' => [],
    ], 'principal');
    echo (str_contains($html, 'ya había pagado') ? "OK    " : "FALLA ") . "la vista avisa que ya pagó\n";
    echo (str_contains($html, 'fondo de devoluciones') ? "OK    " : "FALLA ") . "avisa que sale del fondo\n";
    echo (str_contains($html, 'Anulada por prueba') ? "OK    " : "FALLA ") . "muestra el motivo de la anulación\n";

    $listado = View::renderizar('inscripciones.index', [
        'titulo' => 'Inscripciones', 'concurso' => Concurso::vigente(),
        'inscripciones' => Inscripcion::listar($con),
        'instituciones' => [], 'filtros' => ['institucion_id'=>'','tipo_origen'=>'','nivel'=>'','grado'=>'','estado'=>'','q'=>''],
        'resumen' => Inscripcion::resumen($con),
        // Desde D-40 el listado avisa si el tope dejó filas fuera, y para eso
        // necesita el total real. Sin pasarlo, el aviso quedaría desactivado en
        // silencio: PHP avisa en desarrollo, y ese aviso es la red.
        'total' => Inscripcion::contarFiltradas($con), 'tope' => Inscripcion::TOPE_LISTADO,
    ], 'principal');
    $esperadas = 0; $vivas = 0;
    foreach (Inscripcion::listar($con) as $f) {
        if ($f['estado'] === 'anulada' && empty($f['participante_activo'])) { $esperadas++; }
        if ($f['estado'] !== 'anulada') { $vivas++; }
    }
    $n = substr_count($listado, '/reinscribir">');
    echo ($n === $esperadas ? "OK    " : "FALLA ")
        . "el enlace sale en las {$esperadas} filas atrapadas y en ninguna mas (encontradas: {$n})
";
    echo ($vivas > 0 ? "OK    " : "FALLA ") . "hay {$vivas} filas vivas y ninguna lo ofrece
";
} finally { $pdo->rollBack(); }
