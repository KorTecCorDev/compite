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

/**
 * Incrusta la hoja de estilos en el HTML.
 *
 * Hace falta desde D-43: los enlaces del sistema son relativos a la raíz, y una
 * página abierta como `file://` resolvería `/build/css/app.css` contra la raíz
 * del disco. Sin esto, las pantallas se miden **sin CSS** y el banco denuncia
 * desbordes que no existen — pasó, y las «culpables» eran las tablas en crudo.
 */
function conEstilosDentro(string $html, string $css): string
{
    $hoja = '<style>' . $css . '</style>';

    return (string) preg_replace('#<link[^>]+app\.css[^>]*>#', $hoja, $html, 1);
}

$css = (string) file_get_contents(dirname(__DIR__) . '/public/build/css/app.css');

if ($css === '') {
    echo "No se encontró public/build/css/app.css. Ejecuta `npm run build`.\n";
    exit(1);
}

foreach ($pantallas as $nombre => [$vista, $datos, $layout]) {
    file_put_contents(
        "{$carpeta}/{$nombre}.html",
        conEstilosDentro(View::renderizar($vista, $datos, $layout), $css)
    );
}

$codigo = Database::uno('SELECT codigo_correlativo FROM participantes LIMIT 1');

if ($codigo !== null) {
    $pantallas['carne'] = null;
    file_put_contents("{$carpeta}/carne.html", conEstilosDentro(View::renderizar('carne.publico', [
        'titulo' => 'Carné',
        'ficha'  => Participante::porCodigo((string) $codigo['codigo_correlativo']),
        'estado' => 'confirmada',
    ], 'publico'), $css));
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
        // Segunda medida: ¿van los botones de la columna de acciones al mismo
        // alto que el resto de su fila? Solo tiene sentido en el diseño de
        // tabla: en la ficha de teléfono cada dato ocupa su propio renglón y no
        // existe un «centro de la fila» con el que comparar.
        let torcido = 0;
        if (cw > 768) {
          d.querySelectorAll('tbody tr').forEach(function (tr) {
            const celda = tr.querySelector('.tabla__acciones');
            if (!celda) return;
            const hijos = Array.from(celda.children).filter(function (e) {
              return e.getBoundingClientRect().height > 0;
            });
            if (hijos.length === 0) return;
            const rf = tr.getBoundingClientRect();
            const arriba = Math.min.apply(null, hijos.map(function (e) { return e.getBoundingClientRect().top; }));
            const abajo  = Math.max.apply(null, hijos.map(function (e) { return e.getBoundingClientRect().bottom; }));
            const desvio = ((arriba + abajo) / 2) - (rf.top + rf.height / 2);
            if (Math.abs(desvio) > Math.abs(torcido)) torcido = desvio;
          });
        }
        lineas.push(pagina + '|' + ancho + '|' + cw + '|' + sw + '|' + malos.join(' ')
                  + '|' + Math.round(torcido * 10) / 10);
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

/*
 * Cuánto se le tolera a los botones de acción antes de decir que están torcidos.
 *
 * No es cero, y no debe serlo: `vertical-align: middle` alinea con el medio de
 * la equis minúscula y no con el centro geométrico del renglón, así que en una
 * fila alta los íconos quedan un par de píxeles por debajo del centro. Eso es
 * correcto y no se ve.
 *
 * Lo que este umbral caza es otra cosa: una celda de acciones que **ha dejado de
 * ser una celda**. Al ponerle `display: flex` a un `<td>` deja de ser
 * `table-cell`, pierde el `vertical-align: middle` del que dependía, y el
 * navegador lo envuelve en una celda anónima donde la caja —con alto de
 * contenido— se queda arriba del todo. Ahí el desvío no son dos píxeles: en el
 * listado de inscripciones fueron 26.8 px sobre una fila de 94, y en apoderados
 * 12.6 sobre una de 70. Ver D-48.
 */
const TOLERANCIA_ALINEACION = 6.0;

$fallos   = 0;
$torcidas = 0;

foreach (explode('~~', html_entity_decode($m[1])) as $linea) {
    [$pagina, $ancho, $cw, $sw, $malos, $torcido] = array_pad(explode('|', $linea, 6), 6, '');

    if ((int) $sw > (int) $cw + 1) {
        $fallos++;
        printf("  DESBORDA  %-16s %5spx  documento %spx (+%s)\n", $pagina, $ancho, $sw, (int) $sw - (int) $cw);
        printf("            culpables: %s\n", $malos !== '' ? $malos : '(ninguno sin recortar — mira dentro de una caja con overflow)');
    }

    if (abs((float) $torcido) > TOLERANCIA_ALINEACION) {
        $torcidas++;
        printf(
            "  TORCIDA   %-16s %5spx  botones de acción a %s px del centro de su fila\n",
            $pagina,
            $ancho,
            (float) $torcido > 0 ? '+' . $torcido . ' (abajo)' : $torcido . ' (arriba)'
        );
        echo "            Mira si algo le puso `display: flex` al <td> de acciones:\n";
        echo "            eso le quita el `vertical-align: middle` y los descuelga.\n";
    }
}

echo str_repeat('-', 70) . "\n";

if ($fallos === 0 && $torcidas === 0) {
    echo "Ninguna pantalla desborda y los botones van al alto de su fila.\n";
    echo "Recuerda: esto mide el ancho y la alineación, no el diseño.\n";
    exit(0);
}

if ($fallos > 0) {
    echo "{$fallos} combinaciones desbordan el ancho de la ventana.\n";
}

if ($torcidas > 0) {
    echo "{$torcidas} combinaciones con los botones de acción fuera del alto de su fila.\n";
}

exit(1);
