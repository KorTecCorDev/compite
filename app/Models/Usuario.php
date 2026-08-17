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

    public static function correoExiste(string $correo): bool
    {
        return Database::uno(
            'SELECT 1 AS existe FROM usuarios WHERE correo = :correo LIMIT 1',
            ['correo' => mb_strtolower(trim($correo))]
        ) !== null;
    }
}
