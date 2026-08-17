<?php

declare(strict_types=1);

namespace App\Models;

use Core\Database;
use RuntimeException;

/**
 * Concurso vigente y sus catálogos: categorías y tarifas.
 */
final class Concurso
{
    /**
     * El concurso sobre el que se trabaja.
     *
     * En este MVP hay uno solo; se toma el de fecha de evento más reciente
     * para que el día que exista un segundo, el sistema no tenga que cambiar.
     *
     * @return array<string, mixed>|null
     */
    public static function vigente(): ?array
    {
        return Database::uno(
            'SELECT c.*, o.nombre AS organizacion
               FROM concursos c
               JOIN organizaciones o ON o.id = c.organizacion_id
           ORDER BY c.fecha_evento DESC
              LIMIT 1'
        );
    }

    /**
     * Las 11 categorías, ordenadas como las lee una persona:
     * primaria 1°…6°, luego secundaria 1°…5°.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function categorias(int $concursoId): array
    {
        return Database::todos(
            "SELECT id, nivel, grado,
                    CONCAT(UPPER(LEFT(nivel,1)), SUBSTRING(nivel,2), ' ', grado, '°') AS etiqueta
               FROM categorias
              WHERE concurso_id = :concurso
           ORDER BY FIELD(nivel,'primaria','secundaria'), grado",
            ['concurso' => $concursoId]
        );
    }

    /**
     * Verifica que una categoría pertenezca al concurso indicado.
     *
     * Sin esto, alguien podría enviar por POST el id de una categoría de otro
     * concurso: los `<select>` del navegador se editan sin esfuerzo.
     */
    public static function categoriaPertenece(int $categoriaId, int $concursoId): bool
    {
        return Database::uno(
            'SELECT 1 AS ok FROM categorias WHERE id = :cat AND concurso_id = :con LIMIT 1',
            ['cat' => $categoriaId, 'con' => $concursoId]
        ) !== null;
    }

    /**
     * Monto vigente para un tipo de origen.
     *
     * Decisión D-11: la secretaria no elige el monto. Para una delegación se
     * deriva del tipo de la I.E.; para un estudiante libre es siempre 'libre'.
     */
    public static function tarifa(int $concursoId, string $tipoOrigen): float
    {
        $fila = Database::uno(
            'SELECT monto FROM tarifas WHERE concurso_id = :con AND tipo_origen = :tipo LIMIT 1',
            ['con' => $concursoId, 'tipo' => $tipoOrigen]
        );

        if ($fila === null) {
            throw new RuntimeException(
                "No hay tarifa configurada para el tipo de origen '{$tipoOrigen}'."
            );
        }

        return (float) $fila['monto'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function tarifas(int $concursoId): array
    {
        return Database::todos(
            "SELECT tipo_origen, monto
               FROM tarifas
              WHERE concurso_id = :con
           ORDER BY FIELD(tipo_origen,'publica','privada','libre')",
            ['con' => $concursoId]
        );
    }
}
