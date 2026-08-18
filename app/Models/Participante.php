<?php

declare(strict_types=1);

namespace App\Models;

use Core\Correlativo;
use Core\Database;
use RuntimeException;

/**
 * Participante del concurso.
 *
 * El identificador único es el `codigo_correlativo` generado por el sistema,
 * no el DNI (regla confirmada, sección 3 del plan). El DNI se captura porque
 * es obligatorio en la ficha oficial, pero no identifica el registro.
 *
 * `categoria_id` NO vive aquí: se movió a `inscripciones` (decisión D-01),
 * para que "anular y reinscribir" pueda corregir la categoría conservando el
 * código del estudiante.
 */
final class Participante
{
    /**
     * Crea el participante y le asigna su código correlativo.
     *
     * Se hace en dos pasos dentro de la misma transacción: primero se inserta
     * con un código provisional para obtener el id que MySQL asigna, y luego
     * se escribe el código definitivo usando ese id como número correlativo.
     *
     * Es la forma de evitar una carrera: si dos secretarias registran a la vez,
     * calcular MAX(numero)+1 podría darles el mismo número. El AUTO_INCREMENT
     * de la base ya resuelve ese problema, así que se aprovecha en vez de
     * reimplementarlo.
     *
     * @param array<string, mixed> $datos
     */
    public static function crear(array $datos, string $prefijoConcurso): int
    {
        $provisional = 'TMP-' . bin2hex(random_bytes(12));

        $id = Database::insertar(
            'INSERT INTO participantes (
                codigo_correlativo, concurso_id, tipo_participante,
                dni, ap_paterno, ap_materno, nombres,
                institucion_id, apoderado_id
             ) VALUES (
                :codigo, :concurso, :tipo,
                :dni, :ap_paterno, :ap_materno, :nombres,
                :institucion, :apoderado
             )',
            [
                'codigo'      => $provisional,
                'concurso'    => $datos['concurso_id'],
                'tipo'        => $datos['tipo_participante'],
                'dni'         => $datos['dni'],
                'ap_paterno'  => $datos['ap_paterno'],
                'ap_materno'  => $datos['ap_materno'],
                'nombres'     => $datos['nombres'],
                'institucion' => $datos['institucion_id'] ?? null,
                'apoderado'   => $datos['apoderado_id'] ?? null,
            ]
        );

        /*
         * El sufijo aleatorio tiene que ser único por sí solo, no solo dentro
         * del código completo: es lo que viaja en la URL corta del QR (`/c/K7M9X3`)
         * y lo que resuelve el carné. Dos sufijos iguales harían ambigua esa ruta.
         *
         * La colisión es improbabilísima —hay 30^6 combinaciones— pero «improbable»
         * no es «imposible», y el precio de comprobarlo es una sola consulta
         * contra un puñado de filas. Se reintenta un número acotado de veces para
         * que un fallo de la base no se convierta en un bucle infinito.
         */
        $codigo = null;

        for ($intento = 0; $intento < 5; $intento++) {
            $candidato = Correlativo::generar($prefijoConcurso, $id);

            if (!self::existeSufijo((string) Correlativo::sufijoDe($candidato))) {
                $codigo = $candidato;
                break;
            }
        }

        if ($codigo === null) {
            throw new RuntimeException('No se pudo generar un código único para el participante.');
        }

        Database::ejecutar(
            'UPDATE participantes SET codigo_correlativo = :codigo WHERE id = :id',
            ['codigo' => $codigo, 'id' => $id]
        );

        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function porId(int $id): ?array
    {
        return Database::uno('SELECT * FROM participantes WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    /**
     * Ficha completa por código correlativo, para la vista pública del carné.
     *
     * Toma la inscripción **activa** si la hay; si el participante solo tiene
     * inscripciones anuladas, devuelve la última anulada para que la vista
     * pública pueda mostrarla como ANULADA en lugar de un 404 sin explicación
     * (decisión P-03, resuelta el 2026-08-16).
     *
     * @return array<string, mixed>|null
     */
    public static function porCodigo(string $codigo): ?array
    {
        return Database::uno(
            "SELECT p.*,
                    ie.nombre AS institucion, ie.tipo AS institucion_tipo,
                    c.nombre AS concurso, c.fecha_evento, c.sede,
                    i.id AS inscripcion_id, i.estado, i.monto, i.motivo_anulacion,
                    cat.nivel, cat.grado
               FROM participantes p
               JOIN concursos c ON c.id = p.concurso_id
          LEFT JOIN instituciones_educativas ie ON ie.id = p.institucion_id
          LEFT JOIN inscripciones i
                 ON i.id = (
                      SELECT i2.id
                        FROM inscripciones i2
                       WHERE i2.participante_id = p.id
                    ORDER BY (i2.estado <> 'anulada') DESC, i2.id DESC
                       LIMIT 1
                    )
          LEFT JOIN categorias cat ON cat.id = i.categoria_id
              WHERE p.codigo_correlativo = :codigo
              LIMIT 1",
            ['codigo' => $codigo]
        );
    }

    /**
     * Participantes ya registrados en el concurso con el mismo documento.
     *
     * Decisión D-05: el duplicado se advierte, no se impide. Esta consulta
     * alimenta esa advertencia.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function mismoDocumento(int $concursoId, string $dni): array
    {
        return Database::todos(
            "SELECT p.id, p.codigo_correlativo, p.ap_paterno, p.ap_materno, p.nombres,
                    i.estado, cat.nivel, cat.grado
               FROM participantes p
          LEFT JOIN inscripciones i ON i.participante_id = p.id
          LEFT JOIN categorias cat ON cat.id = i.categoria_id
              WHERE p.concurso_id = :con AND p.dni = :dni
           ORDER BY p.id DESC
              LIMIT 5",
            ['con' => $concursoId, 'dni' => $dni]
        );
    }

    /**
     * Prefijo del concurso, necesario para armar el código.
     */
    public static function prefijoConcurso(int $concursoId): string
    {
        $fila = Database::uno('SELECT codigo FROM concursos WHERE id = :id', ['id' => $concursoId]);

        if ($fila === null) {
            throw new RuntimeException('El concurso indicado no existe.');
        }

        return (string) $fila['codigo'];
    }

    /**
     * ¿Ya hay algún participante cuyo código termine en este sufijo?
     *
     * Deliberadamente global, sin filtrar por concurso: la ruta corta del QR
     * (`/c/{sufijo}`) no lleva el concurso encima, así que el sufijo tiene que
     * ser único en toda la tabla para que esa ruta nunca sea ambigua.
     */
    public static function existeSufijo(string $sufijo): bool
    {
        if (!Correlativo::esSufijoValido($sufijo)) {
            return false;
        }

        $fila = Database::uno(
            'SELECT 1 AS existe FROM participantes WHERE codigo_correlativo LIKE :patron LIMIT 1',
            ['patron' => '%-' . $sufijo]
        );

        return $fila !== null;
    }

    /**
     * Ficha completa a partir del sufijo corto del QR.
     *
     * Resuelve el sufijo a su código correlativo y delega en porCodigo(), que
     * es donde vive la lógica de qué inscripción mostrar. Si por lo que fuera
     * hubiera dos coincidencias, devuelve null en vez de elegir una al azar:
     * ante ambigüedad, es preferible un «no encontrado» honesto que enseñar la
     * ficha del estudiante equivocado.
     */
    public static function porSufijo(string $sufijo): ?array
    {
        if (!Correlativo::esSufijoValido($sufijo)) {
            return null;
        }

        $filas = Database::todos(
            'SELECT codigo_correlativo FROM participantes
              WHERE codigo_correlativo LIKE :patron LIMIT 2',
            ['patron' => '%-' . $sufijo]
        );

        if (count($filas) !== 1) {
            return null;
        }

        return self::porCodigo((string) $filas[0]['codigo_correlativo']);
    }
}
