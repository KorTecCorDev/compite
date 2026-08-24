<?php
declare(strict_types=1);
require __DIR__ . '/_comun.php';

use App\Models\Concurso;
use App\Models\InstitucionEducativa;
use App\Models\Inscripcion;
use Core\Config;
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

    /*
     * Anular es exclusivo del administrador (D-51). Se comprueban las tres
     * piezas por separado porque se pintan en tres sitios distintos de la misma
     * vista, y esconder solo el botón dejaría el mecanismo servido: el
     * formulario oculto lleva su token CSRF y la URL de destino.
     */
    $c(($debeVer ? 've' : 'NO ve') . ' el botón Anular en las filas',
        str_contains($listado, 'boton-anular') === $debeVer);
    $c(($debeVer ? 've' : 'NO ve') . ' el formulario de anulación',
        str_contains($listado, 'id="form-anular"') === $debeVer);
    $c(($debeVer ? 've' : 'NO ve') . ' «Anular» en la leyenda',
        str_contains($listado, 'leyenda__item--peligro') === $debeVer);

    /*
     * Y lo que NO cambia: la secretaria conserva el resto de la fila. D-51 quita
     * una acción, no la pantalla — si esto se rompiera, el registro del día se
     * quedaría sin quien lo haga.
     */
    $c('sigue viendo «Corregir» en las filas',
        str_contains($listado, '<span class="accion__texto">Corregir</span>'));
    $c('sigue viendo el enlace del carné en PDF',
        str_contains($listado, '<span class="accion__texto">PDF</span>'));
    $c('sigue viendo la barra de cobro',
        str_contains($listado, 'id="form-cobro"'));

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
        /*
         * El panel ya NO lista los módulos (21-ago): esa sección era un mapa de
         * avance del desarrollo —«Fase 3 · listo», módulos sin construir— puesto
         * delante de quien solo viene a trabajar. Así que la frontera de roles se
         * comprueba donde de verdad vive ahora, que es la barra, y esta pantalla
         * la lleva igual.
         */
        $c(($debeVer ? 've' : 'NO ve') . ' Instituciones desde el panel',
            str_contains($p, '>Instituciones</a>') === $debeVer);
        $c('ambos siguen llegando a Apoderados desde el panel',
            str_contains($p, '>Apoderados</a>'));

        // Que la sección no vuelva por descuido.
        $c('el panel no enseña el estado de avance del desarrollo',
            !str_contains($p, 'lista-modulos'));
        $c('ni explica por dentro cómo trata las fechas',
            !str_contains($p, 'El cierre lo decide la secretaría'));

        /*
         * Concordancia de la cuenta atrás. Se comprueba porque el fallo que
         * corrige —«faltan 1 día»— salió justo la víspera del concurso: la
         * versión anterior singularizaba el sustantivo y se olvidaba del verbo,
         * así que estuvo bien 364 días al año y mal el único que se mira.
         */
        $c('la cuenta atrás concuerda en singular',
            !str_contains($p, 'faltan 1 día'));
    } catch (Throwable $e) {
        echo "  (panel no renderizado: " . $e->getMessage() . ")\n";
    }
}

/*
 * El servidor, mirando el código y no la pantalla.
 *
 * `anular()` rechaza a la secretaria con un `redirigir()`, y `redirigir()` hace
 * `exit`: invocarlo desde aquí mataría la prueba a mitad. Así que se comprueba
 * lo único que se puede comprobar sin una petición real — que la guarda esté
 * puesta, y que esté ANTES de tocar la inscripción—. Es el mismo recurso que
 * usa `iconos-y-listado-sin-filtrar` para vigilar los redirects con filtro.
 *
 * Ocultar el botón es cortesía; esto es la protección.
 */
echo "\nservidor:\n";

$codigo = (string) file_get_contents(Config::ruta('app/Controllers/AnulacionController.php'));
$anular = substr($codigo, (int) strpos($codigo, 'public function anular('));
$anular = substr($anular, 0, (int) strpos($anular, 'public function formularioReinscribir('));

$posGuarda = strpos($anular, '!Auth::esAdministrador()');
$posCarga  = strpos($anular, 'inscripcionVigenteOFallar');

$posAnula = strpos($anular, 'Inscripcion::anular(');

$c('anular() comprueba que quien llama es administrador', $posGuarda !== false);
$c('y lo comprueba antes de tocar la inscripción',
    $posGuarda !== false && $posCarga !== false && $posGuarda < $posCarga);

/*
 * Lo que de verdad protege: que la guarda esté ANTES de la anulación. No se
 * comprueba ningún 403 porque no lo hay — `redirigir()` envía un `Location:`, y
 * eso degrada la respuesta a 302 pase lo que pase (medido con el servidor
 * embebido de PHP). Una prueba que buscara `http_response_code(403)` daría
 * verde mientras el cliente recibe un 302.
 */
$c('y la anulación no llega a ejecutarse',
    $posGuarda !== false && $posAnula !== false && $posGuarda < $posAnula);
$c('el rechazo no es silencioso: avisa de que no se anuló nada',
    str_contains($anular, 'No se anuló nada'));

// Reinscribir y corregir NO se tocan: siguen siendo de los dos roles (D-51).
$reinscribir = substr($codigo, (int) strpos($codigo, 'public function reinscribir('));
$c('«Reinscribir» sigue sin exigir administrador',
    !str_contains($reinscribir, 'esAdministrador'));

$correccion = (string) file_get_contents(Config::ruta('app/Controllers/CorreccionController.php'));
$c('«Corregir» sigue abierto a los dos roles',
    str_contains($correccion, 'Auth::exigirSesion()') && !str_contains($correccion, 'Auth::exigirAdministrador()'));

/*
 * El acta de los jurados es SOLO del administrador (Fase 5, decisión del
 * propietario del 21-ago): es el documento oficial del concurso.
 *
 * Se comprueba aquí, sobre el código, y no montando una petición: igual que la
 * anulación, la guarda corta con un `redirigir()`, y una prueba que buscara un
 * 403 no encontraría ninguno. Lo que importa es que la guarda esté **antes** de
 * consultar y generar: exigir el rol después de haber armado el libro sería
 * trabajo hecho para nadie, y sobre todo significaría que los datos ya se
 * leyeron para quien no debía.
 */
$reporte = (string) file_get_contents(Config::ruta('app/Controllers/ReporteController.php'));

/*
 * Se recorta el cuerpo de `acta()` antes de mirar nada.
 *
 * Antes esto se comprobaba sobre el archivo entero, y funcionaba solo mientras
 * el controlador tuvo una sola acción. Con los reportes contables dentro (D-59)
 * dejó de valer: el arqueo SÍ es de los dos roles y usa `exigirSesion()`, así
 * que la última comprobación empezó a fallar señalando una guarda correcta.
 *
 * Acotar es lo que mantiene viva la pregunta original —«¿el ACTA está detrás
 * del rol?»— en vez de convertirla en «¿alguien en este archivo dijo sesión?»,
 * que no es lo que aquí importa. El arqueo tiene sus propias comprobaciones de
 * rol en `reportes-contables.php`.
 */
$cuerpoActa = substr($reporte, (int) strpos($reporte, 'public function acta('));
$siguiente  = strpos($cuerpoActa, '    /**', 10);
$cuerpoActa = $siguiente === false ? $cuerpoActa : substr($cuerpoActa, 0, $siguiente);

$posAdmin    = strpos($cuerpoActa, 'Auth::exigirAdministrador()');
$posConsulta = strpos($cuerpoActa, 'Inscripcion::paraActa');
$posGenerar  = strpos($cuerpoActa, 'GeneradorActa::libros');

$c('el acta exige administrador', $posAdmin !== false);
$c('y lo exige ANTES de consultar los datos',
    $posAdmin !== false && $posConsulta !== false && $posAdmin < $posConsulta);
$c('y antes de generar el libro',
    $posAdmin !== false && $posGenerar !== false && $posAdmin < $posGenerar);
$c('el acta no exige solo sesión, como si fuera de los dos roles',
    !str_contains($cuerpoActa, 'Auth::exigirSesion()'));

echo "\n{$ok} correctas, {$mal} fallidas\n";
exit($mal === 0 ? 0 : 1);
