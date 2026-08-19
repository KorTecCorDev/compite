<?php
declare(strict_types=1);
require __DIR__ . '/_comun.php';

use App\Models\Concurso;
use App\Models\Organizacion;
use Core\Database;

$ok = 0; $mal = 0;
$c = static function (string $caso, $esp, $obt) use (&$ok, &$mal): void {
    if ($esp === $obt) { $ok++; echo "  OK    {$caso}\n"; }
    else { $mal++; echo "  FALLA {$caso}: esperaba " . var_export($esp, true) . ", obtuvo " . var_export($obt, true) . "\n"; }
};

$pdo = Database::conexion();
$pdo->beginTransaction();
try {
    $concurso = Concurso::vigente();
    $org = (int) $concurso['organizacion_id'];
    $ies = Database::todos('SELECT id, nombre, tipo FROM instituciones_educativas ORDER BY id LIMIT 2');
    [$a, $b] = [$ies[0], $ies[1]];

    Organizacion::marcarAnfitriona($org, null);
    $c('se parte sin anfitriona', null, Organizacion::institucionAnfitriona($org));

    Organizacion::marcarAnfitriona($org, (int) $a['id']);
    $c('marca la primera', (int) $a['id'], Organizacion::institucionAnfitriona($org));
    $c('la primera resuelve a organizadora', 'organizadora', Concurso::modalidad(Concurso::vigente(), $a));
    $c('la segunda sigue en su gestión', $b['tipo'], Concurso::modalidad(Concurso::vigente(), $b));

    // Marcar la segunda desplaza a la primera: es la misma columna.
    Organizacion::marcarAnfitriona($org, (int) $b['id']);
    $c('marcar la segunda desplaza a la primera', (int) $b['id'], Organizacion::institucionAnfitriona($org));
    $c('la primera vuelve a su gestión', $a['tipo'], Concurso::modalidad(Concurso::vigente(), $a));
    $c('imposible tener dos a la vez', 1, (int) Database::uno(
        'SELECT COUNT(*) n FROM organizaciones WHERE institucion_id IS NOT NULL')['n']);

    // La tarifa que se cobraría a cada una.
    $con = (int) $concurso['id'];
    $conc = Concurso::vigente();
    $c('el anfitrión cobra la tarifa COCIAP', 10.0, Concurso::tarifa($con, Concurso::modalidad($conc, $b)));
    $c('rótulo del anfitrión en carné y reportes', 'COCIAP',
        Concurso::etiquetaModalidad(Concurso::modalidad($conc, $b)));

    // Desmarcar.
    Organizacion::marcarAnfitriona($org, null);
    $c('se puede desmarcar', null, Organizacion::institucionAnfitriona($org));
    $c('sin anfitriona, vuelve a su gestión', $b['tipo'], Concurso::modalidad(Concurso::vigente(), $b));

    $c('marcar dos veces lo mismo no cambia nada', false,
        (Organizacion::marcarAnfitriona($org, (int) $a['id']) === true)
        && Organizacion::marcarAnfitriona($org, (int) $a['id']));
} finally { $pdo->rollBack(); }

echo "\n{$ok} correctas, {$mal} fallidas\n";
exit($mal === 0 ? 0 : 1);
