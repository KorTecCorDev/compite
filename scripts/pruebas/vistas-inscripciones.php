<?php
declare(strict_types=1);
require __DIR__ . '/_comun.php';

use App\Models\Concurso;
use App\Models\InstitucionEducativa;
use App\Models\Inscripcion;
use App\Models\Participante;
use Core\Database;
use Core\View;

$_SESSION['usuario_id'] = 1; $_SESSION['usuario_nombres'] = 'Prueba'; $_SESSION['usuario_rol'] = 'administrador';
$_SESSION['ultimo_uso'] = time();

$fallos = 0;
$probar = static function (string $nombre, callable $fn) use (&$fallos): void {
    try { $html = $fn(); echo "OK    {$nombre} (" . strlen($html) . " bytes)\n"; }
    catch (Throwable $e) { $fallos++; echo "FALLA {$nombre}: " . $e->getMessage() . "\n"; }
};

$concurso = Concurso::vigente();
$con = (int) $concurso['id'];

$probar('inscripciones.index', static fn (): string => View::renderizar('inscripciones.index', [
    'titulo' => 'Inscripciones', 'concurso' => $concurso,
    'inscripciones' => Inscripcion::listar($con),
    'instituciones' => InstitucionEducativa::listar('', null, 50),
    'filtros' => ['institucion_id' => '', 'tipo_origen' => '', 'nivel' => '', 'grado' => '', 'estado' => '', 'q' => ''],
    'resumen' => Inscripcion::resumen($con),
    'total' => Inscripcion::contarFiltradas($con), 'tope' => Inscripcion::TOPE_LISTADO,
], 'principal'));

$probar('inscripciones.delegacion', static fn (): string => View::renderizar('inscripciones.delegacion', [
    'titulo' => 'Delegación', 'concurso' => $concurso,
    'instituciones' => InstitucionEducativa::listar('', null, 50),
    'institucion' => InstitucionEducativa::porId((int) InstitucionEducativa::listar('', null, 1)[0]['id']),
    'categorias' => Concurso::categorias($con), 'tarifas' => Concurso::tarifas($con),
    'filas' => [], 'errores' => [],
], 'principal'));

$codigo = Database::uno('SELECT codigo_correlativo FROM participantes LIMIT 1')['codigo_correlativo'];
$probar('carne.publico', static fn (): string => View::renderizar('carne.publico', [
    'titulo' => 'Carné', 'ficha' => Participante::porCodigo((string) $codigo), 'estado' => 'confirmada',
], 'publico'));

exit($fallos === 0 ? 0 : 1);
