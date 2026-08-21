<?php

declare(strict_types=1);

/**
 * D-52 — Cada quien opera sus propios registros.
 *
 * La regla es de ESCRITURA, no de lectura. Esta prueba vigila las dos mitades,
 * y la segunda importa tanto como la primera: si el aislamiento se colara en la
 * lectura, la mesa de la puerta dejaría de encontrar a los estudiantes que
 * registró la otra secretaria, y eso se descubre con la fila delante.
 *
 * Por qué esta prueba existe aparte de `frontera-de-roles`: aquella simula a la
 * secretaria con el **id del administrador** —le basta el rol para lo que
 * comprueba—, así que ahí `puedeOperar()` responde que sí a todo y no vería
 * ninguna regresión. Aquí las sesiones llevan ids reales y distintos.
 */

require __DIR__ . '/_comun.php';

use App\Models\Concurso;
use App\Models\Inscripcion;
use App\Models\InstitucionEducativa;
use App\Models\Participante;
use Core\Auth;
use Core\Config;
use Core\Database;
use Core\View;

$ok = 0;
$mal = 0;
$c = static function (string $caso, bool $cond) use (&$ok, &$mal): void {
    if ($cond) { $ok++; echo "  OK    {$caso}\n"; } else { $mal++; echo "  FALLA {$caso}\n"; }
};

$con   = idConcurso();
$admin = idAdministrador();

/*
 * Dos ids que NO son el del administrador. El segundo puede no existir —hoy hay
 * dos secretarias, pero la prueba no puede depender de cuántas haya—, así que
 * si falta se usa un id imposible: para `puedeOperar()` un id que no coincide es
 * un id que no coincide, exista o no la persona.
 */
$secretarias = Database::todos(
    "SELECT id FROM usuarios WHERE rol = 'secretaria' AND activo = 1 ORDER BY id"
);

if ($secretarias === []) {
    exit("No hay ninguna secretaria activa: esta prueba no puede simular la frontera.\n");
}

$yo   = (int) $secretarias[0]['id'];
$otra = isset($secretarias[1]) ? (int) $secretarias[1]['id'] : PHP_INT_MAX;

// ---------------------------------------------------------------------
// La regla, mirada de frente
// ---------------------------------------------------------------------
echo "\nAuth::puedeOperar()\n";

iniciarSesionComo('secretaria', $yo);
$c('la secretaria opera lo suyo', Auth::puedeOperar($yo));
$c('NO opera lo de otra secretaria', !Auth::puedeOperar($otra));
$c('NO opera lo del administrador', !Auth::puedeOperar($admin));
$c('NO opera una fila sin dueño conocido', !Auth::puedeOperar(null));

iniciarSesionComo('administrador', $admin);
$c('el administrador opera lo suyo', Auth::puedeOperar($admin));
$c('y también lo de cualquier secretaria', Auth::puedeOperar($yo));
$c('incluso una fila sin dueño conocido', Auth::puedeOperar(null));

// ---------------------------------------------------------------------
// El listado: qué se ve y qué se puede tocar
// ---------------------------------------------------------------------
$reales = Inscripcion::listar($con);

if ($reales === []) {
    exit("No hay inscripciones en el concurso: no hay listado que comprobar.\n");
}

$c('el listado trae el dueño de cada fila', array_key_exists('usuario_id', $reales[0]));

/*
 * Se reparten las filas en memoria en vez de crearlas en la base: lo que se
 * comprueba es la decisión de la vista, y para eso da igual de dónde salga el
 * `usuario_id`. Así la prueba tampoco depende de que hoy existan inscripciones
 * de una secretaria concreta.
 */
$mias = 0;
$ajenas = 0;

foreach ($reales as $i => $fila) {
    if ($fila['estado'] === 'anulada') {
        continue;
    }

    if ($i % 2 === 0) {
        $reales[$i]['usuario_id'] = $yo;
        $mias++;
    } else {
        $reales[$i]['usuario_id'] = $otra;
        $ajenas++;
    }
}

if ($mias === 0 || $ajenas === 0) {
    exit("Hacen falta al menos dos inscripciones vivas para repartirlas.\n");
}

$datos = [
    'titulo'        => 'Inscripciones',
    'concurso'      => Concurso::vigente(),
    'inscripciones' => $reales,
    'instituciones' => InstitucionEducativa::listar('', null, 50),
    'filtros'       => ['institucion_id' => '', 'tipo_origen' => '', 'nivel' => '', 'grado' => '', 'estado' => '', 'q' => ''],
    'resumen'       => Inscripcion::resumen($con),
    'total'         => Inscripcion::contarFiltradas($con),
    'tope'          => Inscripcion::TOPE_LISTADO,
];

echo "\nsecretaria (id {$yo}):\n";
iniciarSesionComo('secretaria', $yo);
$html = View::renderizar('inscripciones.index', $datos, 'principal');

$corregir = substr_count($html, '<span class="accion__texto">Corregir</span>');

$c("«Corregir» sale en sus {$mias} filas y en ninguna más (salió {$corregir})",
    $corregir === $mias);

/*
 * Lo que NO se aísla, y es la mitad que sostiene el día del concurso.
 */
$c('sigue viendo TODAS las filas, propias y ajenas',
    substr_count($html, '<tr id="ins-') === count($reales));
$c('sigue pudiendo cobrar: la barra de cobro está',
    str_contains($html, 'id="form-cobro"'));
$c('las casillas de cobro no se filtran por dueño',
    substr_count($html, 'class="casilla-pago"')
        === count(array_filter($reales, static fn (array $f): bool => $f['estado'] === 'pendiente')));
$c('sigue viendo la columna Responsable, que es como sabe de quién es cada fila',
    str_contains($html, '>Responsable<'));
$c('se le explica por qué faltan acciones en unas filas',
    str_contains($html, 'aparecen únicamente en las inscripciones que registraste tú'));

echo "\nadministrador (id {$admin}):\n";
iniciarSesionComo('administrador', $admin);
$htmlAdmin = View::renderizar('inscripciones.index', $datos, 'principal');

$corregirAdmin = substr_count($htmlAdmin, '<span class="accion__texto">Corregir</span>');
$vivas = count(array_filter($reales, static fn (array $f): bool => $f['estado'] !== 'anulada'));

$c("«Corregir» le sale en las {$vivas} filas vivas (salió {$corregirAdmin})",
    $corregirAdmin === $vivas);
$c('a él no se le pinta la nota de la regla: no le falta ninguna acción',
    !str_contains($htmlAdmin, 'aparecen únicamente en las inscripciones que registraste tú'));

// ---------------------------------------------------------------------
// El servidor. Ocultar el botón es cortesía; esto es la protección.
//
// No se invoca al controlador: rechaza con `redirigir()`, que hace `exit` y
// mataría la prueba. Se mira el código, igual que hace `frontera-de-roles` con
// la anulación, y lo que se exige es que la guarda esté ANTES de escribir.
// ---------------------------------------------------------------------
echo "\nservidor:\n";

$correccion = (string) file_get_contents(Config::ruta('app/Controllers/CorreccionController.php'));
$guardia    = strpos($correccion, 'Auth::puedeOperar(');

$c('corregir comprueba el dueño', $guardia !== false);
$c('y lo comprueba en el mismo sitio que carga la inscripción',
    $guardia !== false && $guardia > (int) strpos($correccion, 'private function inscripcionCorregibleOFallar'));
$c('el rechazo dice quién es el responsable',
    str_contains($correccion, "\$inscripcion['registrado_por']"));

$anulacion = (string) file_get_contents(Config::ruta('app/Controllers/AnulacionController.php'));

/*
 * El orden que importa es el de EJECUCIÓN, no el del archivo: la guarda vive en
 * `inscripcionReinscribibleOFallar()`, un método privado que está al final del
 * fichero aunque se llame al principio del flujo. Comparar posiciones de texto
 * daría rojo teniendo el código bien —y, peor, daría verde el día que alguien
 * mueva el método sin tocar la guarda—. Así que se comprueba lo que de verdad
 * ata las dos cosas: que la guarda esté DENTRO del helper, y que `reinscribir()`
 * llame al helper antes de crear nada.
 */
$helper = substr($anulacion, (int) strpos($anulacion, 'private function inscripcionReinscribibleOFallar'));
$helper = substr($helper, 0, (int) strpos($helper, 'private function inscripcionVigenteOFallar'));

$cuerpo = substr($anulacion, (int) strpos($anulacion, 'public function reinscribir('));
$cuerpo = substr($cuerpo, 0, (int) strpos($cuerpo, 'private function inscripcionReinscribibleOFallar'));

$llamada = strpos($cuerpo, 'inscripcionReinscribibleOFallar(');
$creaR   = strpos($cuerpo, 'Inscripcion::crear(');

$c('reinscribir comprueba el dueño', str_contains($helper, 'Auth::puedeOperar('));
$c('y lo comprueba antes de crear la inscripción nueva',
    $llamada !== false && $creaR !== false && $llamada < $creaR);
$c('el formulario pasa por la misma guarda que el envío',
    substr_count($anulacion, 'inscripcionReinscribibleOFallar(') >= 3);

/*
 * La exención del cobro, vigilada por escrito. Si alguien «completa» D-52
 * añadiendo la guarda aquí, una delegación registrada entre dos secretarias
 * dejaría de poder cobrarse con el Yape único con el que paga.
 */
$pago = (string) file_get_contents(Config::ruta('app/Controllers/PagoController.php'));
$c('el cobro sigue EXENTO de la regla (decisión del propietario)',
    !str_contains($pago, 'puedeOperar'));

$carne = (string) file_get_contents(Config::ruta('app/Controllers/CarneController.php'));
$c('los carnés siguen exentos: la hoja de una delegación los necesita a todos',
    !str_contains($carne, 'puedeOperar'));

$control = (string) file_get_contents(Config::ruta('app/Controllers/ControlController.php'));
$c('el control de ingreso sigue viendo el concurso entero',
    !str_contains($control, 'puedeOperar'));

// ---------------------------------------------------------------------
// El dato tal como sale de la base, no fabricado en memoria.
//
// Las comprobaciones de arriba reparten los `usuario_id` a mano, así que
// siempre son enteros de PHP. Aquí se lee una inscripción REAL escrita con el
// id de una secretaria: si PDO devolviera la columna como cadena y alguien
// quitara un cast, `===` diría que no en todas las filas y la secretaria se
// quedaría sin poder corregir NI LO SUYO, justo el día del registro masivo.
// ---------------------------------------------------------------------
echo "\nel dato real:\n";

$pdo = Database::conexion();
$pdo->beginTransaction();

try {
    $sufijo = str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);

    $participanteId = Participante::crear([
        'concurso_id'       => $con,
        'tipo_participante' => 'libre',
        'dni'               => '99' . $sufijo . '7',
        'ap_paterno'        => 'Prueba',
        'ap_materno'        => 'Propiedad',
        'nombres'           => 'Estudiante Desechable',
        'institucion_id'    => null,
        'apoderado_id'      => null,
    ], Participante::prefijoConcurso($con));

    $inscripcionId = Inscripcion::crear([
        'participante_id' => $participanteId,
        'categoria_id'    => (int) Concurso::categorias($con)[0]['id'],
        'usuario_id'      => $yo,
        'tipo_origen'     => 'libre',
        'monto'           => Concurso::tarifa($con, 'libre'),
    ]);

    $fila = Inscripcion::porId($inscripcionId);

    $c('porId() trae el dueño', $fila !== null && isset($fila['usuario_id']));

    iniciarSesionComo('secretaria', $yo);
    $c('la dueña SÍ puede operar su inscripción recién creada',
        Auth::puedeOperar((int) $fila['usuario_id']));

    iniciarSesionComo('secretaria', $otra);
    $c('la otra secretaria NO puede', !Auth::puedeOperar((int) $fila['usuario_id']));

    iniciarSesionComo('administrador', $admin);
    $c('el administrador sí', Auth::puedeOperar((int) $fila['usuario_id']));
} finally {
    $pdo->rollBack();
    echo "  (transacción revertida: la base queda como estaba)\n";
}

echo "\n{$ok} correctas, {$mal} fallidas\n";
exit($mal === 0 ? 0 : 1);
