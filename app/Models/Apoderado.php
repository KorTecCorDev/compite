<?php

declare(strict_types=1);

namespace App\Models;

use Core\Database;

/**
 * Apoderado de un estudiante libre (independiente).
 *
 * Entidad reutilizable: un mismo apoderado puede estar vinculado a varios
 * estudiantes libres — el caso típico son hermanos (regla confirmada,
 * sección 3 del plan). Por eso el DNI es UNIQUE aquí: identifica a la persona.
 */
final class Apoderado
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listar(string $busqueda = '', int $limite = 100): array
    {
        $sql = 'SELECT a.id, a.dni, a.ap_paterno, a.ap_materno, a.nombres, a.celular,
                       COUNT(p.id) AS estudiantes
                  FROM apoderados a
             LEFT JOIN participantes p ON p.apoderado_id = a.id
                 WHERE 1 = 1';
        $parametros = [];

        if (trim($busqueda) !== '') {
            $sql .= ' AND (a.dni LIKE :b1
                        OR a.ap_paterno LIKE :b2
                        OR a.ap_materno LIKE :b3
                        OR a.nombres LIKE :b4)';
            $termino = '%' . trim($busqueda) . '%';
            $parametros = ['b1' => $termino, 'b2' => $termino, 'b3' => $termino, 'b4' => $termino];
        }

        // Orden de nómina peruana: apellido paterno, materno y luego nombres,
        // con la colación española para que la Ñ caiga donde corresponde.
        $es = Database::ordenEspanol();

        $sql .= ' GROUP BY a.id, a.dni, a.ap_paterno, a.ap_materno, a.nombres, a.celular
                  ORDER BY a.ap_paterno' . $es . ' ASC,
                           a.ap_materno' . $es . ' ASC,
                           a.nombres'    . $es . ' ASC
                  LIMIT ' . max(1, min($limite, 500));

        return Database::todos($sql, $parametros);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function porId(int $id): ?array
    {
        return Database::uno('SELECT * FROM apoderados WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    /**
     * Búsqueda por DNI: es el camino natural para reutilizar un apoderado
     * ya registrado antes de crear uno nuevo (Fase 2 del plan).
     *
     * @return array<string, mixed>|null
     */
    public static function porDni(string $dni): ?array
    {
        return Database::uno(
            'SELECT * FROM apoderados WHERE dni = :dni LIMIT 1',
            ['dni' => trim($dni)]
        );
    }

    /**
     * @param array<string, mixed> $datos
     */
    public static function crear(array $datos): int
    {
        return Database::insertar(
            'INSERT INTO apoderados (dni, ap_paterno, ap_materno, nombres, celular)
                  VALUES (:dni, :ap_paterno, :ap_materno, :nombres, :celular)',
            $datos
        );
    }

    /**
     * @param array<string, mixed> $datos
     */
    public static function actualizar(int $id, array $datos): void
    {
        Database::ejecutar(
            'UPDATE apoderados SET
                dni        = :dni,
                ap_paterno = :ap_paterno,
                ap_materno = :ap_materno,
                nombres    = :nombres,
                celular    = :celular
             WHERE id = :id',
            $datos + ['id' => $id]
        );
    }

    /**
     * Estudiantes libres vinculados. Se consulta antes de permitir borrar.
     */
    public static function estudiantesVinculados(int $id): int
    {
        $fila = Database::uno(
            'SELECT COUNT(*) AS total FROM participantes WHERE apoderado_id = :id',
            ['id' => $id]
        );

        return (int) ($fila['total'] ?? 0);
    }

    public static function eliminar(int $id): void
    {
        Database::ejecutar('DELETE FROM apoderados WHERE id = :id', ['id' => $id]);
    }

    public static function dniExiste(string $dni, ?int $exceptoId = null): bool
    {
        $sql = 'SELECT 1 AS existe FROM apoderados WHERE dni = :dni';
        $parametros = ['dni' => trim($dni)];

        if ($exceptoId !== null) {
            $sql .= ' AND id <> :id';
            $parametros['id'] = $exceptoId;
        }

        return Database::uno($sql . ' LIMIT 1', $parametros) !== null;
    }

    public static function total(): int
    {
        $fila = Database::uno('SELECT COUNT(*) AS total FROM apoderados');

        return (int) ($fila['total'] ?? 0);
    }
}
