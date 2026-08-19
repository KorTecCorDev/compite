<?php
declare(strict_types=1);
require __DIR__ . '/_comun.php';

use App\Models\Apoderado;
use App\Models\Concurso;
use App\Models\Inscripcion;
use App\Models\InstitucionEducativa;
use App\Models\Usuario;
use Core\View;

iniciarSesionComo('administrador'); $_SESSION['ultimo_uso'] = time();

$ok = 0; $mal = 0;
$c = static function (string $caso, bool $cond, string $detalle = '') use (&$ok, &$mal): void {
    if ($cond) { $ok++; echo "  OK    {$caso}\n"; }
    else { $mal++; echo "  FALLA {$caso}" . ($detalle !== '' ? ": {$detalle}" : '') . "\n"; }
};

$con = (int) Concurso::vigente()['id'];

$pantallas = [
    'inscripciones' => ['inscripciones.index', [
        'titulo' => 'Inscripciones', 'concurso' => Concurso::vigente(),
        'inscripciones' => Inscripcion::listar($con),
        'instituciones' => InstitucionEducativa::listar('', null, 50),
        'filtros' => ['institucion_id'=>'','tipo_origen'=>'','nivel'=>'','grado'=>'','estado'=>'','q'=>''],
        'resumen' => Inscripcion::resumen($con),
        'total' => Inscripcion::contarFiltradas($con), 'tope' => Inscripcion::TOPE_LISTADO,
    ]],
    'instituciones' => ['instituciones.index', [
        'titulo' => 'I.E.', 'instituciones' => InstitucionEducativa::listar('', null, 50),
        'busqueda' => '', 'tipo' => '', 'total' => 5, 'anfitriona' => (int) (App\Models\Organizacion::institucionAnfitriona((int) App\Models\Concurso::vigente()['organizacion_id']) ?? 0),
    ]],
    'apoderados' => ['apoderados.index', [
        'titulo' => 'Apoderados', 'apoderados' => Apoderado::listar(''), 'busqueda' => '', 'total' => 3,
    ]],
    'usuarios' => ['usuarios.index', [
        'titulo' => 'Usuarios', 'usuarios' => Usuario::todos(), 'yo' => idAdministrador(),
    ]],
    'nómina de delegación' => ['inscripciones.delegacion', [
        'titulo' => 'Delegación', 'concurso' => Concurso::vigente(),
        'instituciones' => InstitucionEducativa::listar('', null, 50), 'institucion' => null,
        'categorias' => Concurso::categorias($con), 'tarifas' => Concurso::tarifas($con),
        'filas' => [], 'errores' => [],
    ]],
];

// Celdas que NO llevan rótulo a propósito.
$sinRotulo = ['inscripciones' => 0];

foreach ($pantallas as $nombre => [$vista, $datos]) {
    $html = View::renderizar($vista, $datos, 'principal');

    // Cabeceras y celdas de la PRIMERA fila del cuerpo.
    preg_match('/<thead>(.*?)<\/thead>/s', $html, $th);
    preg_match('/<tbody[^>]*>(.*?)<\/tr>/s', $html, $tb);

    $cabeceras = preg_match_all('/<th[^>]*>/', $th[1] ?? '', $m1);
    $celdas    = preg_match_all('/<td[^>]*>/', $tb[1] ?? '', $m2);
    // Cada celda o lleva rótulo, o es la identidad de la ficha (su título).
    $rotuladas = 0; $principales = 0;
    foreach ($m2[0] ?? [] as $td) {
        if (str_contains($td, 'data-etiqueta='))      { $rotuladas++; }
        elseif (str_contains($td, 'tabla__principal')) { $principales++; }
    }
    $rotuladas += $principales;

    $c("{$nombre}: hay tantas celdas como cabeceras", $cabeceras === $celdas,
        "{$cabeceras} <th> vs {$celdas} <td>");
    $c("{$nombre}: toda celda lleva su rótulo para la ficha móvil",
        $rotuladas === $celdas, "{$rotuladas} de {$celdas}");

    // Ningún rótulo vacío: `attr(data-etiqueta)` con valor vacío deja una ficha
    // con una línea sin nombre, que es peor que no tener rótulo.
    $c("{$nombre}: ningún rótulo vacío", !preg_match('/data-etiqueta=""/', $html));
    $c("{$nombre}: como mucho una celda de identidad por fila", $principales <= 1,
        "{$principales} celdas con tabla__principal");
}

// El CSS compilado tiene que traer de verdad los puntos de corte.
$css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/build/css/app.css');
foreach ([
    'ficha móvil (48rem)'        => 'max-width:48rem',
    'teléfono (36rem)'           => 'max-width:36rem',
    'teléfono pequeño (23.5rem)' => 'max-width:23.5rem',
    'punteros gruesos'           => 'pointer:coarse',
    'rótulo desde el atributo'   => 'attr(data-etiqueta)',
] as $que => $aguja) {
    $c("el CSS compilado trae {$que}", str_contains($css, $aguja));
}

echo "\n{$ok} correctas, {$mal} fallidas\n";
exit($mal === 0 ? 0 : 1);
