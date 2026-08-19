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
