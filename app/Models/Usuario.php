<?php

declare(strict_types=1);

namespace App\Models;

use Core\Database;

/**
 * Usuarios del sistema. Login individual por persona (sección 3 del plan).
 */
final class Usuario
{
    /**
     * @return array<string, mixed>|null
     */
    public static function porCorreo(string $correo): ?array
    {
        return Database::uno(
            'SELECT id, nombres, correo, password_hash, rol, activo
               FROM usuarios
              WHERE correo = :correo
              LIMIT 1',
            ['correo' => mb_strtolower(trim($correo))]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function porId(int $id): ?array
    {
        return Database::uno(
            'SELECT id, nombres, correo, rol, activo, created_at
               FROM usuarios
              WHERE id = :id
              LIMIT 1',
            ['id' => $id]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function todos(): array
    {
        return Database::todos(
            'SELECT id, nombres, correo, rol, activo, created_at
               FROM usuarios
           ORDER BY activo DESC, nombres' . Database::ordenEspanol() . ' ASC'
        );
    }

    public static function crear(string $nombres, string $correo, string $password, string $rol): int
    {
        return Database::insertar(
            'INSERT INTO usuarios (nombres, correo, password_hash, rol)
                  VALUES (:nombres, :correo, :hash, :rol)',
            [
                'nombres' => trim($nombres),
                'correo'  => mb_strtolower(trim($correo)),
                'hash'    => password_hash($password, PASSWORD_DEFAULT),
                'rol'     => $rol,
            ]
        );
    }

    public static function actualizarPassword(int $id, string $password): void
    {
        Database::ejecutar(
            'UPDATE usuarios SET password_hash = :hash WHERE id = :id',
            [
                'hash' => password_hash($password, PASSWORD_DEFAULT),
                'id'   => $id,
            ]
        );
    }

    /**
     * El plan habla de "desactivar" usuarios, no de borrarlos: las
     * inscripciones guardan quién las registró y esa referencia debe seguir
     * siendo válida.
     */
    public static function cambiarEstado(int $id, bool $activo): void
    {
        Database::ejecutar(
            'UPDATE usuarios SET activo = :activo WHERE id = :id',
            ['activo' => $activo ? 1 : 0, 'id' => $id]
        );
    }

    public static function correoExiste(string $correo, ?int $exceptoId = null): bool
    {
        // `$exceptoId` permite editar un usuario sin que su propio correo se
        // detecte como repetido contra sí mismo.
        $sql = 'SELECT 1 AS existe FROM usuarios WHERE correo = :correo';
        $parametros = ['correo' => mb_strtolower(trim($correo))];

        if ($exceptoId !== null) {
            $sql .= ' AND id <> :id';
            $parametros['id'] = $exceptoId;
        }

        return Database::uno($sql . ' LIMIT 1', $parametros) !== null;
    }

    /**
     * Cambia nombre, correo y rol. La contraseña va por su propio método:
     * son dos formularios distintos y no deben poder tocarse por accidente.
     */
    public static function actualizar(int $id, string $nombres, string $correo, string $rol): void
    {
        Database::ejecutar(
            'UPDATE usuarios SET nombres = :nombres, correo = :correo, rol = :rol WHERE id = :id',
            [
                'nombres' => trim($nombres),
                'correo'  => mb_strtolower(trim($correo)),
                'rol'     => $rol,
                'id'      => $id,
            ]
        );
    }

    /**
     * Administradores activos. Lo consulta el controlador antes de degradar o
     * desactivar a uno: quedarse sin ningún administrador activo dejaría el
     * sistema sin quien gestione concurso, tarifas, instituciones y usuarios,
     * y no habría forma de arreglarlo desde la propia aplicación.
     */
    public static function administradoresActivos(): int
    {
        $fila = Database::uno(
            "SELECT COUNT(*) AS total FROM usuarios WHERE rol = 'administrador' AND activo = 1"
        );

        return (int) ($fila['total'] ?? 0);
    }
}
