<?php

declare(strict_types=1);

/**
 * Arranque común de las pruebas.
 *
 * Cada prueba de esta carpeta empieza con:
 *
 *     require __DIR__ . '/_comun.php';
 *
 * Todas se ejecutan **contra la base real de trabajo**, que es lo que las hace
 * valer —comprueban el esquema, las colaciones y el modo estricto de verdad, no
 * una maqueta— y por eso cada una abre su propia transacción y la revierte al
 * final. Aquí se añade la red por debajo: si una prueba se cae a mitad, o hace
 * `exit()` antes de su `rollBack()`, la transacción se deshace igual.
 *
 * Nada de esto está atado al entorno: ni rutas absolutas ni identificadores
 * fijos. El administrador y el concurso se buscan, no se escriben, así que las
 * pruebas siguen valiendo en Hostinger, en otra máquina o después de restaurar
 * un respaldo distinto.
 *
 * Para ejecutarlas todas:  php scripts/pruebas/todas.php
 */

if (PHP_SAPI !== 'cli') {
    exit("Las pruebas solo se ejecutan desde la consola.\n");
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Models\Concurso;
use Core\Database;

/**
 * Un administrador activo cualquiera. Sin él no hay sesión que simular.
 */
function idAdministrador(): int
{
    $fila = Database::uno(
        "SELECT id FROM usuarios WHERE rol = 'administrador' AND activo = 1 ORDER BY id LIMIT 1"
    );

    if ($fila === null) {
        exit("No hay ningún administrador activo: las pruebas no pueden simular una sesión.\n");
    }

    return (int) $fila['id'];
}

/**
 * El concurso sobre el que se trabaja.
 */
function idConcurso(): int
{
    $concurso = Concurso::vigente();

    if ($concurso === null) {
        exit("No hay ningún concurso: ejecuta database/seed.sql antes de las pruebas.\n");
    }

    return (int) $concurso['id'];
}

/**
 * Una inscripción PENDIENTE recién creada, con participante propio.
 *
 * Existe porque dos pruebas —`firmas-y-usuarios` y `reinscribir`— buscaban una
 * con `SELECT ... WHERE estado='pendiente' LIMIT 1`, es decir, secuestraban una
 * fila real de trabajo. Eso las ataba al estado de los datos, que es
 * exactamente lo que el resto de la carpeta evita: **el 21-ago se cobró todo el
 * lote, no quedó ni una pendiente y las dos suites se pusieron rojas solas, sin
 * que nadie tocara una línea de código**. Estaba anotado como deuda en
 * `PENDIENTE.md` y ocurrió tal cual.
 *
 * Creando el caso, la prueba comprueba lo que dice comprobar y no depende de
 * que la secretaría haya dejado algo sin cobrar. Todo se revierte con la
 * transacción de la prueba que lo llama.
 *
 * La modalidad y el monto NO se escriben a mano: se derivan con
 * `Concurso::modalidad()` y `Concurso::tarifa()`, así que siguen siendo
 * coherentes aunque a la I.E. elegida le toque ser la anfitriona.
 */
function inscripcionPendienteDePrueba(int $usuarioId): int
{
    $concurso = Concurso::vigente();
    $con      = idConcurso();

    $ie = Database::uno('SELECT id, tipo FROM instituciones_educativas ORDER BY id LIMIT 1');

    if ($ie === null) {
        exit("No hay ninguna I.E. en el catálogo: no se puede crear la inscripción de prueba.\n");
    }

    $categorias = Concurso::categorias($con);

    if ($categorias === []) {
        exit("El concurso no tiene categorías: no se puede crear la inscripción de prueba.\n");
    }

    $modalidad = Concurso::modalidad($concurso, $ie);

    // DNI aleatorio: `uq_participante_documento` no admite repetidos, y un
    // número fijo chocaría con el de una corrida anterior o con una persona real.
    $dni = (string) random_int(90000000, 99999999);

    $participante = \App\Models\Participante::crear([
        'concurso_id'       => $con,
        'tipo_participante' => 'delegacion',
        'dni'               => $dni,
        'ap_paterno'        => 'Desechable',
        'ap_materno'        => 'Prueba',
        'nombres'           => 'Estudiante Pendiente',
        'institucion_id'    => (int) $ie['id'],
        'apoderado_id'      => null,
    ], \App\Models\Participante::prefijoConcurso($con));

    return \App\Models\Inscripcion::crear([
        'participante_id' => $participante,
        'categoria_id'    => (int) $categorias[0]['id'],
        'usuario_id'      => $usuarioId,
        'estado'          => 'pendiente',
        'tipo_origen'     => $modalidad,
        'monto'           => Concurso::tarifa($con, $modalidad),
    ]);
}

/**
 * Simula una sesión iniciada.
 *
 * Las claves son planas (`usuario_id`, `usuario_rol`), como las escribe
 * `Core\Auth`. No es un detalle menor: unas pruebas anteriores las guardaban
 * anidadas en `$_SESSION['usuario']['rol']`, con lo que `Auth::esAdministrador()`
 * devolvía false siempre y **dos comprobaciones pasaban en verde sin comprobar
 * nada** —una de ellas encontraba la palabra «Usuarios» en el `<h1>` y la daba
 * por el enlace del menú—. Por eso la sesión se monta desde aquí y en un solo
 * sitio.
 */
function iniciarSesionComo(string $rol, ?int $id = null): void
{
    // La sesión ya está abierta desde el arranque (más abajo): aquí solo se
    // cambian sus valores. `session_start()` aquí fallaría cuando una prueba
    // cambia de rol a mitad —la de fronteras lo hace dos veces— porque para
    // entonces ya se ha escrito en pantalla y las cabeceras se fueron.
    $_SESSION['usuario_id']      = $id ?? idAdministrador();
    $_SESSION['usuario_nombres'] = 'Prueba';
    $_SESSION['usuario_rol']     = $rol;
    $_SESSION['ultimo_uso']      = time();
}

/*
 * La sesión se abre aquí, al cargar el arranque, y no dentro de
 * `iniciarSesionComo()`: en este punto todavía no se ha escrito nada en
 * pantalla, que es la única condición que `session_start()` exige.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
 * Red de seguridad: la prueba abre y revierte su transacción, pero si muere
 * antes de llegar a su `rollBack()` —una excepción, un `exit()` temprano— esto
 * la deshace igualmente. Sin ello, una prueba caída dejaría filas suyas en la
 * base de trabajo.
 */
register_shutdown_function(static function (): void {
    $pdo = Database::conexion();

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
});
