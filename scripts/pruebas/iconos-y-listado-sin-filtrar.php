<?php

declare(strict_types=1);

/**
 * D-48 — la columna de acciones es de íconos, y el listado no se filtra solo.
 *
 * Las dos mitades se prueban juntas porque son la misma decisión vista de dos
 * lados: lo que se ve en la columna y lo que se ve en la tabla. Y las dos se
 * rompen igual de callado —un ícono sin rótulo sigue pintando, un redirect con
 * filtro sigue devolviendo un 302— así que ninguna se nota sin una prueba.
 */

require __DIR__ . '/_comun.php';

use App\Models\Concurso;
use App\Models\Inscripcion;
use App\Models\InstitucionEducativa;
use Core\Config;
use Core\View;

iniciarSesionComo('administrador');

$fallos = 0;

$comprobar = static function (bool $ok, string $nombre) use (&$fallos): void {
    if (!$ok) {
        $fallos++;
    }

    echo ($ok ? 'OK    ' : 'FALLA ') . $nombre . "\n";
};

$concurso = Concurso::vigente();
$con      = (int) $concurso['id'];

$sinFiltros = ['institucion_id' => '', 'tipo_origen' => '', 'nivel' => '', 'grado' => '', 'estado' => '', 'q' => ''];

$html = View::renderizar('inscripciones.index', [
    'titulo'        => 'Inscripciones',
    'concurso'      => $concurso,
    'inscripciones' => Inscripcion::listar($con),
    'instituciones' => InstitucionEducativa::listar('', null, 50),
    'filtros'       => $sinFiltros,
    'resumen'       => Inscripcion::resumen($con),
    'total'         => Inscripcion::contarFiltradas($con),
    'tope'          => Inscripcion::TOPE_LISTADO,
], 'principal');

// ---------------------------------------------------------------------------
// Los íconos
// ---------------------------------------------------------------------------

echo "\n-- íconos --\n";

/*
 * El sprite se imprime UNA vez. Si acabara dentro del bucle de filas, la página
 * seguiría viéndose igual —los <symbol> repetidos no pintan nada— mientras el
 * HTML crece unos 1.5 KB por fila y los `id` se duplican trescientas veces.
 */
$comprobar(substr_count($html, '<symbol id="i-lapiz"') === 1, 'el sprite se imprime una sola vez');

$usados = [];
preg_match_all('/<use href="#(i-[a-z-]+)">/', $html, $m);
$usados = array_unique($m[1]);
sort($usados);

$comprobar(
    $usados === ['i-descargar', 'i-lapiz', 'i-ojo', 'i-persona-mas', 'i-prohibido', 'i-recargar'],
    'se usan los seis íconos (' . implode(', ', $usados) . ')'
);

// Cada <use> tiene que apuntar a un <symbol> que exista: un id mal escrito no da
// error en ningún sitio, simplemente deja un hueco en blanco en la fila.
$rotos = [];
foreach ($usados as $id) {
    if (!str_contains($html, '<symbol id="' . $id . '"')) {
        $rotos[] = $id;
    }
}
$comprobar($rotos === [], 'ningún <use> apunta a un símbolo inexistente');

/*
 * El rótulo NO desaparece del HTML: es el nombre accesible del control y lo que
 * vuelve a verse en la ficha de teléfono. Quitarlo dejaría seis enlaces que un
 * lector de pantalla anuncia como «enlace» a secas.
 */
foreach (['Corregir categoría', 'Anular', 'PDF', 'Regenerar', 'Reinscribir', 'Ver carné'] as $rotulo) {
    $comprobar(
        str_contains($html, '<span class="accion__texto">' . $rotulo . '</span>'),
        "«{$rotulo}» sigue en el HTML como rótulo recortado"
    );
}

// Y cada acción lleva su `title`: es el globo del ratón, la única pista en
// escritorio antes de pulsar. Una de las seis es irreversible.
$acciones = substr_count($html, 'class="accion__texto"');
$titulos  = preg_match_all('/class="accion[ "][^>]*title="/', $html)
          + preg_match_all('/title="[^"]*"[^>]*class="accion[ "]/', $html);

$comprobar($acciones > 0 && $titulos === $acciones, "las {$acciones} acciones llevan title (con title: {$titulos})");

// ---------------------------------------------------------------------------
// El listado no se filtra solo
// ---------------------------------------------------------------------------

echo "\n-- listado sin filtrar --\n";

$total = (int) Inscripcion::contarFiltradas($con);
$todas = Inscripcion::listar($con);

$comprobar(
    count($todas) === min($total, Inscripcion::TOPE_LISTADO) && $total > 0,
    "sin filtros el listado trae las {$total} inscripciones, de todos los estados"
);

$estados = [];
foreach ($todas as $fila) {
    $estados[(string) $fila['estado']] = true;
}

$comprobar(count($estados) > 1, 'y conviven varios estados en la misma pantalla (' . implode(', ', array_keys($estados)) . ')');

// Las anclas son lo que sustituye al `?q=CÓDIGO`: sin ellas, volver a una tabla
// ordenada por apellido no dice qué acaba de pasar.
$anclas = preg_match_all('/<tr id="ins-\d+">/', $html);
$comprobar($anclas === count($todas), "cada fila lleva su ancla #ins-N ({$anclas} de " . count($todas) . ')');

/*
 * El guardia de verdad: NINGÚN redirect del sistema puede volver al listado con
 * un filtro puesto. Se mira el código y no el navegador porque es un `header()`
 * y no deja rastro en ninguna vista.
 *
 * La única excepción aprobada es `?institucion_id=`, que no recorta la vista
 * sino que habilita el botón de la hoja de carnés de la delegación.
 */
$impuestos = [];

foreach (glob(Config::ruta('app/Controllers') . '/*.php') ?: [] as $archivo) {
    $codigo = (string) file_get_contents($archivo);

    preg_match_all("/redirigir\('\/inscripciones\?([a-z_]+)=/", $codigo, $encontrados);

    foreach ($encontrados[1] as $clave) {
        if ($clave !== 'institucion_id') {
            $impuestos[] = basename($archivo) . " → ?{$clave}=";
        }
    }
}

$comprobar($impuestos === [], 'ningún controlador redirige con un filtro impuesto' . ($impuestos === [] ? '' : ': ' . implode(', ', $impuestos)));

// ---------------------------------------------------------------------------
// La lista blanca de la URL de vuelta
// ---------------------------------------------------------------------------

echo "\n-- Inscripcion::urlListado() --\n";

$comprobar(Inscripcion::urlListado([]) === '/inscripciones', 'sin filtros devuelve el listado entero');
$comprobar(Inscripcion::urlListado(['estado' => '', 'q' => '  ']) === '/inscripciones', 'los valores vacíos no ensucian la URL');
/*
 * El orden de salida es el de `Inscripcion::FILTROS`, no el de entrada. No es
 * capricho: así los mismos filtros dan siempre la misma URL, y la caché del
 * navegador y el historial no acumulan tres direcciones distintas para la misma
 * pantalla.
 */
$comprobar(
    Inscripcion::urlListado(['estado' => 'pendiente', 'grado' => '3']) === '/inscripciones?grado=3&estado=pendiente',
    'conserva los filtros conocidos, en el orden canónico'
);

/*
 * Lo que llega del formulario de cobro es entrada del cliente. Si `urlListado()`
 * dejara pasar cualquier clave, un `volver` fabricado podría convertir el
 * redirect en una redirección abierta.
 */
$sucio = Inscripcion::urlListado([
    'estado'  => 'pendiente',
    'inyecta' => 'https://example.invalid',
    0         => '//example.invalid',
]);

$comprobar($sucio === '/inscripciones?estado=pendiente', "descarta las claves desconocidas ({$sucio})");
$comprobar(str_starts_with(Inscripcion::urlListado(['q' => '//example.invalid']), '/inscripciones?q='), 'un valor con // no se sale del sitio');

exit($fallos === 0 ? 0 : 1);
