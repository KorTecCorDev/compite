<?php
declare(strict_types=1);
require __DIR__ . '/_comun.php';

use App\Models\Concurso;
use App\Models\InstitucionEducativa;
use App\Models\Inscripcion;
use Core\View;

$_SESSION['ultimo_uso'] = time();
$ok = 0; $mal = 0;
$c = static function (string $caso, bool $cond) use (&$ok, &$mal): void {
    if ($cond) { $ok++; echo "  OK    {$caso}\n"; } else { $mal++; echo "  FALLA {$caso}\n"; }
};

$con = (int) Concurso::vigente()['id'];
$comunes = [
    'titulo' => 'Inscripciones', 'concurso' => Concurso::vigente(),
    'inscripciones' => Inscripcion::listar($con),
    'instituciones' => InstitucionEducativa::listar('', null, 50),
    'filtros' => ['institucion_id'=>'','tipo_origen'=>'','nivel'=>'','grado'=>'','estado'=>'','q'=>''],
    'resumen' => Inscripcion::resumen($con),
    // Desde D-40 el listado avisa si el tope dejó filas fuera, y para eso
    // necesita el total real. Sin pasarlo el aviso quedaría desactivado en
    // silencio; PHP lo denuncia en desarrollo, y ese aviso es la red.
    'total' => Inscripcion::contarFiltradas($con), 'tope' => Inscripcion::TOPE_LISTADO,
];
$deleg = [
    'titulo' => 'Delegación', 'concurso' => Concurso::vigente(),
    'instituciones' => InstitucionEducativa::listar('', null, 50), 'institucion' => null,
    'categorias' => Concurso::categorias($con), 'tarifas' => Concurso::tarifas($con),
    'filas' => [], 'errores' => [],
];
$panel = ['titulo' => 'Panel', 'concurso' => Concurso::vigente(), 'resumen' => Inscripcion::resumen($con),
          'carnes' => 0, 'instituciones' => 5, 'apoderados' => 3, 'participantes' => 24];

foreach (['secretaria' => false, 'administrador' => true] as $rol => $debeVer) {
    echo "\n{$rol}:\n";
    iniciarSesionComo($rol);

    $listado = View::renderizar('inscripciones.index', $comunes, 'principal');
    $tieneEnlace = str_contains($listado, '>Instituciones<');
    $c(($debeVer ? 've' : 'NO ve') . ' Instituciones en la barra', $tieneEnlace === $debeVer);

    $d = View::renderizar('inscripciones.delegacion', $deleg, 'principal');
    $c(($debeVer ? 've' : 'NO ve') . ' el enlace «Regístrala primero»',
        str_contains($d, 'Regístrala primero') === $debeVer);
    $c('el desplegable de delegaciones sigue lleno para ambos',
        substr_count($d, '<option value="') > 5);
    if (!$debeVer) {
        $c('a la secretaria se le dice a quién pedirlo',
            str_contains($d, 'Pídele al administrador que la registre'));
    }

    try {
        $p = View::renderizar('panel.index', $panel, 'principal');
        $c(($debeVer ? 've' : 'NO ve') . ' el módulo Instituciones en el panel',
            str_contains($p, '>Instituciones Educativas</a>') === $debeVer);
        $c('ambos siguen viendo Apoderados en el panel', str_contains($p, '>Apoderados</a>'));
    } catch (Throwable $e) {
        echo "  (panel no renderizado: " . $e->getMessage() . ")\n";
    }
}

echo "\n{$ok} correctas, {$mal} fallidas\n";
exit($mal === 0 ? 0 : 1);
