<?php

declare(strict_types=1);

namespace Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Conexión PDO única y centralizada.
 *
 * Regla del plan (sección 2), sin excepciones: todo acceso a datos usa
 * sentencias preparadas. Nunca se concatena una variable dentro del SQL.
 */
final class Database
{
    /**
     * Colación para ordenar texto en español.
     *
     * `utf8mb4_spanish_ci` es la del español **moderno**: la Ñ es una letra
     * propia que va después de la N, y CH y LL son dígrafos que se alfabetizan
     * como C+H y L+L, tal como fijó la RAE en 1994.
     *
     * No se usa `utf8mb4_spanish2_ci` (español tradicional) porque separa CH y
     * LL como letras independientes, y eso pondría «Chávez» después de
     * «Cortez» — un orden que hoy ya nadie espera en una nómina peruana.
     */
    public const ORDEN_ES = 'utf8mb4_spanish_ci';

    private static ?PDO $conexion = null;

    /** Memoriza si el servidor tiene la colación española compilada. */
    private static ?bool $hayOrdenEs = null;

    private function __construct()
    {
    }

    /**
     * Cláusula COLLATE lista para pegar dentro de un ORDER BY.
     *
     * Se aplica **solo al ordenar**, nunca a las columnas. La diferencia
     * importa: las columnas siguen en `utf8mb4_unicode_ci`, donde la Ñ y la N
     * se consideran iguales al comparar, así la secretaria encuentra «Ñañez»
     * escribiendo «Nanez». Si cambiáramos la colación de la columna ganaríamos
     * el orden correcto pero perderíamos esa tolerancia al buscar.
     *
     * Si el servidor no tuviera la colación compilada (no debería pasar, pero
     * Hostinger es terreno ajeno), devuelve cadena vacía y el listado sigue
     * funcionando con el orden anterior en lugar de reventar.
     */
    public static function ordenEspanol(): string
    {
        if (self::$hayOrdenEs === null) {
            try {
                self::$hayOrdenEs = self::uno(
                    'SELECT 1 AS ok
                       FROM information_schema.COLLATIONS
                      WHERE COLLATION_NAME = :nombre
                      LIMIT 1',
                    ['nombre' => self::ORDEN_ES]
                ) !== null;
            } catch (\Throwable $e) {
                self::$hayOrdenEs = false;
            }

            if (self::$hayOrdenEs === false) {
                error_log(
                    'Aviso: la colación ' . self::ORDEN_ES . ' no está disponible. '
                    . 'Los listados se ordenarán con la colación por defecto y la Ñ '
                    . 'quedará mezclada entre las N.'
                );
            }
        }

        return self::$hayOrdenEs ? ' COLLATE ' . self::ORDEN_ES : '';
    }

    public static function conexion(): PDO
    {
        if (self::$conexion instanceof PDO) {
            return self::$conexion;
        }

        $cfg = Config::obtener('db');

        if (!is_array($cfg)) {
            throw new RuntimeException('Falta la sección "db" en la configuración.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            (int) $cfg['puerto'],
            $cfg['nombre'],
            $cfg['charset']
        );

        try {
            $pdo = new PDO($dsn, $cfg['usuario'], $cfg['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Preparadas reales del servidor, no emuladas por el driver:
                // es lo que hace que los tipos lleguen correctos y que la
                // separación entre SQL y datos sea efectiva.
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            // No se filtra el mensaje original: puede contener credenciales.
            throw new RuntimeException(
                'No se pudo conectar a la base de datos. Revisa config/config.local.php',
                0,
                $e
            );
        }

        /*
         * Modo estricto explícito.
         *
         * El MariaDB local de XAMPP viene SIN modo estricto: si mandas 20
         * caracteres a un VARCHAR(15), los recorta en silencio y sigue como si
         * nada. Hostinger normalmente sí lo trae activo. Sin esta línea, un
         * dato que se guarda "bien" en desarrollo revienta en producción.
         * Forzándolo aquí, los errores aparecen en la máquina del desarrollador.
         */
        $pdo->exec(
            "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,"
            . "ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'"
        );

        self::$conexion = $pdo;

        return self::$conexion;
    }

    /**
     * Ejecuta una consulta preparada y devuelve todas las filas.
     *
     * @param array<string|int, mixed> $parametros
     * @return array<int, array<string, mixed>>
     */
    public static function todos(string $sql, array $parametros = []): array
    {
        $sentencia = self::conexion()->prepare($sql);
        $sentencia->execute($parametros);

        return $sentencia->fetchAll();
    }

    /**
     * Ejecuta una consulta preparada y devuelve la primera fila, o null.
     *
     * @param array<string|int, mixed> $parametros
     * @return array<string, mixed>|null
     */
    public static function uno(string $sql, array $parametros = []): ?array
    {
        $sentencia = self::conexion()->prepare($sql);
        $sentencia->execute($parametros);

        $fila = $sentencia->fetch();

        return $fila === false ? null : $fila;
    }

    /**
     * Ejecuta INSERT/UPDATE/DELETE y devuelve el número de filas afectadas.
     *
     * @param array<string|int, mixed> $parametros
     */
    public static function ejecutar(string $sql, array $parametros = []): int
    {
        $sentencia = self::conexion()->prepare($sql);
        $sentencia->execute($parametros);

        return $sentencia->rowCount();
    }

    /**
     * Inserta y devuelve el id generado.
     *
     * @param array<string|int, mixed> $parametros
     */
    public static function insertar(string $sql, array $parametros = []): int
    {
        self::ejecutar($sql, $parametros);

        return (int) self::conexion()->lastInsertId();
    }

    /**
     * Envuelve una operación en una transacción.
     *
     * Se usa, por ejemplo, en el alta por lote de una delegación: o entran
     * todos los participantes con sus inscripciones, o no entra ninguno.
     */
    public static function transaccion(callable $operacion): mixed
    {
        $pdo = self::conexion();
        $pdo->beginTransaction();

        try {
            $resultado = $operacion($pdo);
            $pdo->commit();

            return $resultado;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
