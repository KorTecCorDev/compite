<?php

declare(strict_types=1);

namespace App\Models;

use Core\Database;

/**
 * La organización que monta el concurso (el inquilino).
 *
 * Existe por una sola razón (D-37): guardar **qué colegio del catálogo es ella
 * misma**, cuando además de organizar inscribe a sus propios estudiantes.
 *
 * Esa marca vive aquí y no como un valor de `instituciones_educativas.tipo`
 * por cuatro motivos, todos comprobados antes de decidir:
 *
 *   · `tipo` alimenta el cálculo de la tarifa a través de Concurso::modalidad().
 *     Un tercer valor ahí haría que se buscara una tarifa con ese nombre, y no
 *     existe: la fila se llama 'organizadora'. Serían dos ENUM que mantener
 *     sincronizados para siempre.
 *   · Siendo UNA columna de UNA organización, tener dos anfitriones a la vez es
 *     imposible por construcción: marcar un colegio desmarca al anterior. Con un
 *     valor de ENUM nada lo impediría, y ninguna consulta sabría cuál manda.
 *   · El colegio anfitrión es de gestión pública, y eso es cierto y se conserva.
 *   · `instituciones_educativas` es un catálogo GLOBAL, compartido entre
 *     organizaciones (§3 del plan). «Anfitrión» no diría de qué concurso: el día
 *     que otro colegio organice, el mismo colegio sería anfitrión de uno y
 *     delegación normal de otro, y un solo valor no puede decir las dos cosas.
 */
final class Organizacion
{
    /**
     * @return array<string, mixed>|null
     */
    public static function porId(int $id): ?array
    {
        return Database::uno(
            'SELECT * FROM organizaciones WHERE id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    /**
     * El colegio del catálogo que ES esta organización, si lo hay.
     */
    public static function institucionAnfitriona(int $organizacionId): ?int
    {
        $fila = Database::uno(
            'SELECT institucion_id FROM organizaciones WHERE id = :id LIMIT 1',
            ['id' => $organizacionId]
        );

        return isset($fila['institucion_id']) ? (int) $fila['institucion_id'] : null;
    }

    /**
     * Marca (o desmarca, con null) el colegio anfitrión.
     *
     * No hace falta limpiar la marca anterior: es la misma columna, así que
     * escribir el nuevo colegio borra al viejo en la misma sentencia. Esa es
     * justamente la propiedad que se perdería si la marca viviera en el
     * catálogo de colegios.
     *
     * @return bool si la marca cambió de verdad
     */
    public static function marcarAnfitriona(int $organizacionId, ?int $institucionId): bool
    {
        if (self::institucionAnfitriona($organizacionId) === $institucionId) {
            return false;
        }

        Database::ejecutar(
            'UPDATE organizaciones SET institucion_id = :ie WHERE id = :id',
            ['ie' => $institucionId, 'id' => $organizacionId]
        );

        return true;
    }
}
