<?php
declare(strict_types=1);
require __DIR__ . '/_comun.php';
use App\Models\Concurso; use App\Models\Inscripcion; use Core\Database;

$ok = 0; $mal = 0;
$c = static function (string $caso, $esp, $obt) use (&$ok, &$mal): void {
    if ($esp === $obt) { $ok++; echo "  OK    {$caso}\n"; }
    else { $mal++; echo "  FALLA {$caso}: esperaba " . var_export($esp, true) . ", obtuvo " . var_export($obt, true) . "\n"; }
};

$con = (int) Concurso::vigente()['id'];

// contarFiltradas y listar tienen que coincidir bajo el tope, y con CADA filtro.
$casos = [
    'sin filtros'      => [],
    'por modalidad'    => ['tipo_origen' => 'libre'],
    'por estado'       => ['estado' => 'confirmada'],
    'por nivel'        => ['nivel' => 'primaria'],
    'por grado'        => ['grado' => 3],
    'por búsqueda'     => ['q' => 'a'],
    'combinado'        => ['nivel' => 'primaria', 'estado' => 'confirmada', 'tipo_origen' => 'privada'],
];
foreach ($casos as $nombre => $f) {
    $c("cuenta y listado coinciden — {$nombre}",
        count(Inscripcion::listar($con, $f)), Inscripcion::contarFiltradas($con, $f));
}

$ie = Database::uno('SELECT institucion_id FROM participantes WHERE institucion_id IS NOT NULL LIMIT 1');
$c('cuenta y listado coinciden — por delegación',
    count(Inscripcion::listar($con, ['institucion_id' => (int) $ie['institucion_id']])),
    Inscripcion::contarFiltradas($con, ['institucion_id' => (int) $ie['institucion_id']]));

$c('el tope subió de 500', true, Inscripcion::TOPE_LISTADO > 500);

echo "\n{$ok} correctas, {$mal} fallidas\n";
exit($mal === 0 ? 0 : 1);
