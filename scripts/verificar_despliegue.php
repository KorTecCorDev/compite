<?php

declare(strict_types=1);

/**
 * Comprobación previa (y posterior) al despliegue.
 *
 * Responde a una pregunta: **¿este servidor puede sostener el concurso?**
 * No modifica nada; solo mira y reporta.
 *
 * Existe por un fallo real: durante las pruebas, restaurar un respaldo dejó la
 * base desfasada de las migraciones y el cobro se cayó entero con un error 1364
 * de MySQL, sin ningún aviso previo. No hay tabla de migraciones aplicadas, así
 * que la única forma de saber si el esquema está completo es comprobarlo.
 *
 * Uso, por SSH, desde la raíz del proyecto:
 *
 *     php scripts/verificar_despliegue.php
 *
 * Sale con código 0 si todo está listo, 1 si hay algo que impide abrir el
 * registro. Los avisos (⚠) no bloquean.
 */

if (PHP_SAPI !== 'cli') {
    exit("Este script solo se ejecuta desde la consola.\n");
}

require __DIR__ . '/../core/autoload.php';

use Core\Config;
use Core\Database;

$fallos = 0;
$avisos = 0;

function seccion(string $titulo): void
{
    echo "\n" . $titulo . "\n" . str_repeat('-', mb_strlen($titulo)) . "\n";
}

function bien(string $texto): void
{
    echo "  [ok]    {$texto}\n";
}

function mal(string $texto): void
{
    global $fallos;
    $fallos++;
    echo "  [FALLO] {$texto}\n";
}

function aviso(string $texto): void
{
    global $avisos;
    $avisos++;
    echo "  [aviso] {$texto}\n";
}

echo "Verificación de despliegue — COCIAP 2026\n";
echo "Fecha: " . date('Y-m-d H:i:s') . "\n";


// ---------------------------------------------------------------------
seccion('1. PHP');
// ---------------------------------------------------------------------
// La versión de la CONSOLA puede no ser la del sitio web en Hostinger. Esta
// comprobación mira la de consola; la del sitio se ve en cPanel.
if (PHP_VERSION_ID >= 80200) {
    bien('PHP ' . PHP_VERSION . ' (composer.json exige >= 8.2)');
} else {
    mal('PHP ' . PHP_VERSION . ' es anterior a 8.2. En cPanel → PHP Configuration.');
}

foreach (['pdo_mysql', 'mbstring', 'gd', 'zip'] as $ext) {
    if (extension_loaded($ext)) {
        bien("extensión {$ext}");
    } else {
        mal("falta la extensión {$ext}");
    }
}

echo "\n  Recuerda: esta es la versión de PHP de la CONSOLA. La del sitio web se\n"
   . "  configura aparte en cPanel y puede ser distinta.\n";


// ---------------------------------------------------------------------
seccion('2. Configuración');
// ---------------------------------------------------------------------
$entorno = (string) Config::obtener('app.entorno', 'local');
$depurar = (bool) Config::obtener('app.depurar', true);
$urlBase = (string) Config::obtener('app.url_base', '');

if (!is_file(dirname(__DIR__) . '/config/config.local.php')) {
    mal('no existe config/config.local.php — se están usando los valores de desarrollo');
} else {
    bien('config/config.local.php presente');
}

if ($entorno === 'produccion') {
    bien("entorno = produccion");
} else {
    aviso("entorno = {$entorno} (en el servidor debería ser 'produccion')");
}

if ($depurar) {
    mal('depurar = true — cualquier error mostraría rutas del servidor y trozos de consulta al visitante');
} else {
    bien('depurar = false');
}

/*
 * `url_base` ya solo afecta al QR del carné (D-43). Enlaces, assets y
 * redirecciones son relativos a la raíz y funcionan bajo cualquier dominio sin
 * tocar configuración.
 *
 * Vacío es una opción legítima —y la correcta con un dominio provisional—: cada
 * carné toma el dominio por el que se generó. Se avisa y no se bloquea, pero se
 * avisa, porque la consecuencia hay que tenerla presente al imprimir.
 */
if ($urlBase === '') {
    aviso('url_base vacío: cada carné llevará en su QR el dominio por el que se generó. '
        . 'Es lo correcto con un dominio provisional — pero imprime los carnés lo más '
        . 'tarde posible, porque un QR en papel no se puede corregir');
} elseif (str_contains($urlBase, 'localhost') || str_contains($urlBase, '127.0.0.1')) {
    mal("url_base = {$urlBase} — apunta a la máquina local; TODOS los QR impresos serían inservibles");
} elseif (preg_match('/TU-DOMINIO|tudominio|CAMBIAR|ejemplo\.|dominio\.pe$|localhost/i', $urlBase)) {
    /*
     * Un marcador de posición sin reemplazar pasaba esta comprobación: no está
     * vacío y no dice localhost, así que se daba por bueno. Y es justo el valor
     * que más caro sale equivocado — es el que codifica el QR del carné, y no se
     * arregla reimprimiendo: hay que repartirlos otra vez.
     */
    mal("url_base = {$urlBase} — eso sigue siendo la plantilla, no tu dominio real");
} else {
    bien("url_base = {$urlBase}");
    if (!str_starts_with($urlBase, 'https://')) {
        aviso('url_base no usa https — la cookie de sesión no viajará como Secure');
    }
}


// ---------------------------------------------------------------------
seccion('3. Base de datos');
// ---------------------------------------------------------------------
try {
    $pdo = Database::conexion();
    bien('conexión establecida');
    bien('servidor: ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
} catch (Throwable $e) {
    mal('no se pudo conectar: ' . $e->getMessage());
    echo "\nSin base de datos no tiene sentido seguir comprobando.\n";
    exit(1);
}

/*
 * La colación española. `Database::ordenEspanol()` la usa para que la Ñ caiga
 * después de la N en las nóminas, y si el servidor no la trae **no falla**:
 * registra un aviso en el log y ordena con la colación por defecto.
 *
 * Ese silencio es justo el problema. Nadie mira el log el día del concurso, y
 * un listado con las Ñ mezcladas entre las N parece correcto hasta que alguien
 * busca a un «Ñañez» y no lo encuentra donde debería. Por eso se comprueba aquí,
 * antes de abrir el registro, y no cuando ya hay trescientos apellidos dentro.
 */
$colacion = Database::uno(
    'SELECT 1 AS ok FROM information_schema.COLLATIONS WHERE COLLATION_NAME = :n LIMIT 1',
    ['n' => Database::ORDEN_ES]
);

$colacion !== null
    ? bien('colación ' . Database::ORDEN_ES . ' disponible (la Ñ ordena después de la N)')
    : aviso('este servidor no trae ' . Database::ORDEN_ES . '. No rompe nada, pero los listados '
          . 'ordenarán la Ñ mezclada entre las N y solo lo dirá el log');

/*
 * Esquema esperado. Es la lista de columnas que el código de HOY necesita, no
 * la tabla entera: si falta alguna, es que la base viene de una versión
 * anterior y algo va a romperse en producción, probablemente al cobrar.
 */
$esperado = [
    'organizaciones'          => ['id', 'nombre', 'institucion_id'],
    'concursos'               => ['id', 'organizacion_id', 'codigo', 'fecha_evento'],
    'categorias'              => ['id', 'concurso_id', 'nivel', 'grado'],
    'tarifas'                 => ['id', 'concurso_id', 'tipo_origen', 'monto'],
    'apoderados'              => ['id', 'dni', 'ap_paterno', 'celular', 'correo'],
    'instituciones_educativas' => ['id', 'nombre', 'tipo', 'docente_delegado_id'],
    'usuarios'                => ['id', 'correo', 'password_hash', 'rol', 'activo'],
    'participantes'           => ['id', 'codigo_correlativo', 'concurso_id', 'dni', 'institucion_id'],
    'inscripciones'           => [
        'id', 'participante_id', 'categoria_id', 'usuario_id', 'estado',
        'tipo_origen', 'monto', 'medio_pago', 'yape_codigo_seguridad',
        'fecha_pago', 'confirmado_por', 'motivo_anulacion', 'anulado_por',
        'requiere_devolucion',
    ],
    'carnes'                  => ['id', 'inscripcion_id', 'codigo_qr'],
    // D-50: sin esta tabla, corregir un dato del estudiante falla al
    // registrar la firma, y falla DENTRO de la transacción: no se
    // corrompe nada, pero la secretaria no puede corregir.
    'correcciones'            => [
        'id', 'participante_id', 'inscripcion_id', 'lote', 'campo',
        'anterior', 'nuevo', 'motivo', 'usuario_id',
    ],
];

foreach ($esperado as $tabla => $columnas) {
    $filas = Database::todos(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
        ['t' => $tabla]
    );

    if ($filas === []) {
        mal("falta la tabla `{$tabla}` — ¿se importó database/schema.sql?");
        continue;
    }

    $presentes = array_column($filas, 'COLUMN_NAME');
    $faltan    = array_diff($columnas, $presentes);

    if ($faltan === []) {
        bien("tabla `{$tabla}` completa");
    } else {
        mal("`{$tabla}` sin " . implode(', ', $faltan) . ' — esquema desfasado');
    }
}

// El ENUM de modalidad tiene que admitir 'organizadora' (D-37) o el colegio
// anfitrión no se puede cobrar.
foreach (['tarifas', 'inscripciones'] as $tabla) {
    $tipo = Database::uno(
        "SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = 'tipo_origen'",
        ['t' => $tabla]
    );

    if ($tipo !== null && str_contains((string) $tipo['t'], 'organizadora')) {
        bien("`{$tabla}.tipo_origen` admite 'organizadora'");
    } else {
        mal("`{$tabla}.tipo_origen` no admite 'organizadora' — el anfitrión no podría inscribir");
    }
}


// ---------------------------------------------------------------------
seccion('4. Datos mínimos para abrir el registro');
// ---------------------------------------------------------------------
$concurso = Database::uno('SELECT * FROM concursos ORDER BY fecha_evento DESC LIMIT 1');

if ($concurso === null) {
    mal('no hay ningún concurso — falta importar database/seed.sql');
} else {
    bien("concurso: {$concurso['nombre']} ({$concurso['codigo']}), {$concurso['fecha_evento']}");

    $categorias = (int) Database::uno(
        'SELECT COUNT(*) AS n FROM categorias WHERE concurso_id = :c',
        ['c' => $concurso['id']]
    )['n'];
    $categorias === 11
        ? bien("{$categorias} categorías")
        : mal("{$categorias} categorías (se esperan 11: primaria 1-6, secundaria 1-5)");

    $tarifas = Database::todos(
        'SELECT tipo_origen, monto FROM tarifas WHERE concurso_id = :c',
        ['c' => $concurso['id']]
    );
    $porTipo = array_column($tarifas, 'monto', 'tipo_origen');

    foreach (['publica', 'privada', 'libre', 'organizadora'] as $tipo) {
        isset($porTipo[$tipo])
            ? bien("tarifa {$tipo}: S/ " . number_format((float) $porTipo[$tipo], 2))
            : mal("falta la tarifa '{$tipo}' — inscribir en esa modalidad lanzaría una excepción");
    }

    // La I.E. anfitriona (D-37). Sin enlazar, sus estudiantes se cobrarían como
    // pública y competirían en la bolsa equivocada, sin ningún aviso.
    $org = Database::uno(
        'SELECT o.id, o.nombre, o.institucion_id, ie.nombre AS colegio
           FROM organizaciones o
      LEFT JOIN instituciones_educativas ie ON ie.id = o.institucion_id
          WHERE o.id = :id',
        ['id' => $concurso['organizacion_id']]
    );

    if ($org['institucion_id'] === null) {
        aviso('la organización no tiene I.E. anfitriona marcada. Si el colegio organizador '
            . 'va a inscribir estudiantes propios, márcalo en /instituciones antes del primero');
    } else {
        bien("I.E. anfitriona: {$org['colegio']} (modalidad COCIAP)");
    }
}

$admins = (int) Database::uno(
    "SELECT COUNT(*) AS n FROM usuarios WHERE rol = 'administrador' AND activo = 1"
)['n'];
$admins > 0
    ? bien("{$admins} administrador(es) activo(s)")
    : mal('no hay ningún administrador activo — nadie podría gestionar el sistema');

$secretarias = (int) Database::uno(
    "SELECT COUNT(*) AS n FROM usuarios WHERE rol = 'secretaria' AND activo = 1"
)['n'];
$secretarias > 0
    ? bien("{$secretarias} secretaria(s) activa(s)")
    : aviso('no hay secretarias activas todavía');


// ---------------------------------------------------------------------
seccion('5. Almacenamiento y assets');
// ---------------------------------------------------------------------
$logs = dirname(__DIR__) . '/storage/logs';
is_dir($logs) && is_writable($logs)
    ? bien('storage/logs escribible')
    : mal('storage/logs no existe o no es escribible — los errores no quedarían registrados');

$css = dirname(__DIR__) . '/public/build/css/app.css';

if (!is_file($css)) {
    mal('falta public/build/css/app.css — ejecuta `npm run build` y vuelve a subir');
} else {
    // El build de producción sale minificado (una sola línea). Si trae muchas,
    // se subió la salida de desarrollo.
    $lineas = substr_count((string) file_get_contents($css), "\n");
    $lineas <= 2
        ? bien('assets compilados en modo producción (minificados)')
        : aviso("public/build/css/app.css tiene {$lineas} líneas: parece la salida de "
              . 'desarrollo. Ejecuta `npm run build` y vuelve a subir');
}

if (is_file(dirname(__DIR__) . '/vendor/autoload.php')) {
    bien('dependencias de Composer instaladas');
} else {
    mal('falta vendor/ — ejecuta `composer install --no-dev`');
}


// ---------------------------------------------------------------------
seccion('Resultado');
// ---------------------------------------------------------------------
if ($fallos === 0 && $avisos === 0) {
    echo "  Todo listo. Se puede abrir el registro.\n";
} elseif ($fallos === 0) {
    echo "  Sin fallos, {$avisos} aviso(s). Revisa los avisos y decide si importan.\n";
} else {
    echo "  {$fallos} fallo(s) y {$avisos} aviso(s). NO abras el registro hasta resolver los fallos.\n";
}

echo "\n";
exit($fallos === 0 ? 0 : 1);
