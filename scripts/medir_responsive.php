<?php

declare(strict_types=1);

/**
 * Mide si alguna pantalla desborda el ancho de la ventana.
 *
 *     php scripts/medir_responsive.php
 *
 * Sale con 0 si ninguna pantalla desborda en ningún ancho, y con 1 si alguna lo
 * hace, nombrando el elemento culpable.
 *
 * POR QUÉ EXISTE
 * --------------
 * Este script nació de equivocarme dos veces seguidas razonando sin medir. La
 * primera, afirmando que el `overflow-x` del contenedor ya evitaba que la página
 * se ensanchara: era falso, y el propietario lo vio antes que yo. La segunda,
 * dando por buenas unas medidas tomadas con `--window-size=360` sin saber que
 * **Chrome no baja de unos 500 px de ventana en Windows**, así que aquellas
 * pruebas «de teléfono» medían en realidad 485 px y no probaban nada.
 *
 * CÓMO MIDE
 * ---------
 * Renderiza cada vista a un archivo HTML y lo carga **dentro de un `<iframe>` de
 * ancho exacto**. Un iframe sí crea un viewport de verdad: las media queries de
 * dentro se evalúan contra su ancho, así que 320 px son 320 px. Después compara
 * `scrollWidth` con `clientWidth` y, si sobra, nombra los elementos que se salen
 * ordenados por cuánto se salen.
 *
 * Descarta a los que viven dentro de una caja con scroll propio: ahí sobresalir
 * es correcto —es lo que hace la tira de la barra de navegación— y contarlos
 * escondía las causas reales entre ruido.
 *
 * LO QUE NO HACE
 * --------------
 * Mide el ancho, no el diseño. Que algo desborde es un fallo objetivo y esto lo
 * caza; que algo quede feo, apretado o ilegible no. Para eso hay que mirar, y
 * para eso está `docs/protocolo-pruebas.html`.
 */

require __DIR__ . '/pruebas/_comun.php';

use App\Models\Apoderado;
use App\Models\Concurso;
use App\Models\Inscripcion;
use App\Models\InstitucionEducativa;
use App\Models\Participante;
use App\Models\Usuario;
use Core\Database;
use Core\View;

/** Anchos representativos, del teléfono pequeño al monitor. */
const ANCHOS = [320, 360, 390, 414, 768, 1024, 1280, 1440];

// ---------------------------------------------------------------------
// 1. Localizar Chrome
// ---------------------------------------------------------------------

function rutaChrome(): ?string
{
    $candidatos = [
        'C:/Program Files/Google/Chrome/Application/chrome.exe',
        'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
        getenv('LOCALAPPDATA') . '/Google/Chrome/Application/chrome.exe',
        '/usr/bin/google-chrome',
        '/usr/bin/chromium',
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    ];

    foreach ($candidatos as $ruta) {
        if ($ruta !== false && is_file($ruta)) {
            return $ruta;
        }
    }

    return null;
}

$chrome = rutaChrome();

if ($chrome === null) {
    echo "No se encontró Chrome. Esta medición lo necesita; el resto de pruebas\n"
       . "(scripts/pruebas/todas.php) funciona sin él.\n";
    exit(0);
}

// ---------------------------------------------------------------------
// 2. Renderizar las pantallas a archivos
// ---------------------------------------------------------------------

$carpeta = sys_get_temp_dir() . '/cociap-responsive';

if (!is_dir($carpeta)) {
    mkdir($carpeta, 0777, true);
}

iniciarSesionComo('administrador');
$con      = idConcurso();
$concurso = Concurso::vigente();

$pantallas = [
    'inscripciones' => ['inscripciones.index', [
        'titulo' => 'Inscripciones', 'concurso' => $concurso,
        'inscripciones' => Inscripcion::listar($con),
        'instituciones' => InstitucionEducativa::listar('', null, 50),
        'filtros' => ['institucion_id' => '', 'tipo_origen' => '', 'nivel' => '', 'grado' => '', 'estado' => '', 'q' => ''],
        'resumen' => Inscripcion::resumen($con),
        'total' => Inscripcion::contarFiltradas($con), 'tope' => Inscripcion::TOPE_LISTADO,
        'columnaAncha' => true,
    ], 'principal'],
    'delegacion' => ['inscripciones.delegacion', [
        'titulo' => 'Delegación', 'concurso' => $concurso,
        'instituciones' => InstitucionEducativa::listar('', null, 50), 'institucion' => null,
        'categorias' => Concurso::categorias($con), 'tarifas' => Concurso::tarifas($con),
        'filas' => [], 'errores' => [],
    ], 'principal'],
    'instituciones' => ['instituciones.index', [
        'titulo' => 'Instituciones', 'instituciones' => InstitucionEducativa::listar('', null, 50),
        'busqueda' => '', 'tipo' => '', 'total' => 5,
        'anfitriona' => App\Models\Organizacion::institucionAnfitriona((int) $concurso['organizacion_id']),
    ], 'principal'],
    'usuarios' => ['usuarios.index', [
        'titulo' => 'Usuarios', 'usuarios' => Usuario::todos(), 'yo' => idAdministrador(),
    ], 'principal'],
    'apoderados' => ['apoderados.index', [
        'titulo' => 'Apoderados', 'apoderados' => Apoderado::listar(''), 'busqueda' => '', 'total' => 3,
    ], 'principal'],
];

foreach ($pantallas as $nombre => [$vista, $datos, $layout]) {
    file_put_contents("{$carpeta}/{$nombre}.html", View::renderizar($vista, $datos, $layout));
}

$codigo = Database::uno('SELECT codigo_correlativo FROM participantes LIMIT 1');

if ($codigo !== null) {
    $pantallas['carne'] = null;
    file_put_contents("{$carpeta}/carne.html", View::renderizar('carne.publico', [
        'titulo' => 'Carné',
        'ficha'  => Participante::porCodigo((string) $codigo['codigo_correlativo']),
        'estado' => 'confirmada',
    ], 'publico'));
}

// ---------------------------------------------------------------------
// 3. El marco que mide
// ---------------------------------------------------------------------

file_put_contents("{$carpeta}/marco.html", <<<'HTML'
<!doctype html><meta charset="utf-8"><title>midiendo</title>
<style>html,body{margin:0}iframe{border:0;display:block}</style><body><script>
const paginas = new URLSearchParams(location.search).get('p').split(',');
const anchos  = new URLSearchParams(location.search).get('w').split(',').map(Number);
const marco = document.createElement('iframe');
marco.height = 900;
document.body.appendChild(marco);
const lineas = [];

function medir(pagina, ancho) {
  return new Promise(function (listo) {
    marco.width = ancho;
    marco.onload = function () {
      setTimeout(function () {
        const d = marco.contentDocument, v = marco.contentWindow;
        const cw = d.documentElement.clientWidth, sw = d.documentElement.scrollWidth;
        let malos = [];
        if (sw > cw + 1) {
          // Dentro de una caja con scroll propio, sobresalir es correcto.
          const clipado = function (el) {
            for (let p = el.parentElement; p && p !== d.body; p = p.parentElement) {
              const o = v.getComputedStyle(p);
              if (o.overflowX !== 'visible' || o.overflowY !== 'visible') return true;
            }
            return false;
          };
          const cand = [];
          d.querySelectorAll('body *').forEach(function (el) {
            const r = el.getBoundingClientRect();
            if (r.width === 0 || r.right <= cw + 1 || clipado(el)) return;
            cand.push({ el: el, r: r });
          });
          cand.sort(function (a, b) { return b.r.right - a.r.right; });
          malos = cand.slice(0, 4).map(function (c) {
            const cls = (c.el.className && typeof c.el.className === 'string')
              ? '.' + c.el.className.trim().split(/\s+/).slice(0, 2).join('.') : '';
            return c.el.tagName.toLowerCase() + cls + '[' + Math.round(c.r.left) + '..' + Math.round(c.r.right) + ']';
          });
        }
        lineas.push(pagina + '|' + ancho + '|' + cw + '|' + sw + '|' + malos.join(' '));
        listo();
      }, 120);
    };
    marco.src = pagina + '.html?v=' + Math.random();
  });
}

(async function () {
  for (const p of paginas) { for (const a of anchos) { await medir(p, a); } }
  document.title = 'FIN>>' + lineas.join('~~');
})();
</script>
HTML);

// ---------------------------------------------------------------------
// 4. Ejecutar y reportar
// ---------------------------------------------------------------------

$url = 'file:///' . str_replace('\\', '/', $carpeta) . '/marco.html?p='
     . implode(',', array_keys($pantallas)) . '&w=' . implode(',', ANCHOS);

$comando = escapeshellarg($chrome)
    . ' --headless=new --disable-gpu --no-sandbox --allow-file-access-from-files'
    . ' --hide-scrollbars --virtual-time-budget=60000 --window-size=1600,1000'
    . ' --dump-dom ' . escapeshellarg($url) . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

$dom = (string) shell_exec($comando);

if (!preg_match('/<title>FIN&gt;&gt;(.*?)<\/title>/s', $dom, $m)) {
    echo "La medición no devolvió resultados. ¿Chrome pudo abrir los archivos?\n";
    exit(1);
}

echo "Medición responsive — " . count($pantallas) . ' pantallas × ' . count(ANCHOS) . " anchos\n";
echo str_repeat('-', 70) . "\n";

$fallos = 0;

foreach (explode('~~', html_entity_decode($m[1])) as $linea) {
    [$pagina, $ancho, $cw, $sw, $malos] = array_pad(explode('|', $linea, 5), 5, '');

    if ((int) $sw > (int) $cw + 1) {
        $fallos++;
        printf("  DESBORDA  %-16s %5spx  documento %spx (+%s)\n", $pagina, $ancho, $sw, (int) $sw - (int) $cw);
        printf("            culpables: %s\n", $malos !== '' ? $malos : '(ninguno sin recortar — mira dentro de una caja con overflow)');
    }
}

echo str_repeat('-', 70) . "\n";

if ($fallos === 0) {
    echo "Ninguna pantalla desborda. Recuerda: esto mide el ancho, no el diseño.\n";
    exit(0);
}

echo "{$fallos} combinaciones desbordan el ancho de la ventana.\n";
exit(1);
