<?php

declare(strict_types=1);

namespace App\Models;

use Core\Database;

/**
 * Catálogo GLOBAL de Instituciones Educativas.
 *
 * No está aislado por organización (regla confirmada, sección 3 del plan):
 * si el mismo colegio participa en concursos de distintas organizaciones,
 * debe existir una sola vez.
 *
 * Los datos del director viven aquí, no en cada inscripción: son datos
 * persistentes de la I.E. y si cambian, se actualizan sobre el mismo registro.
 *
 * El docente delegado ya no (D-28). Es el encargado de la delegación y por
 * tanto el apoderado de los participantes que inscribe, así que vive en
 * `apoderados` y aquí solo se guarda `docente_delegado_id`. El director se
 * queda embebido porque no es apoderado de nadie.
 */
final class InstitucionEducativa
{
    /** Columnas que se leen en los listados. */
    private const CAMPOS_LISTA = 'id, nombre, tipo, distrito, provincia, departamento';

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listar(string $busqueda = '', ?string $tipo = null, int $limite = 100): array
    {
        $sql = 'SELECT ' . self::CAMPOS_LISTA . ' FROM instituciones_educativas WHERE 1 = 1';
        $parametros = [];

        if (trim($busqueda) !== '') {
            // LIKE con comodines a ambos lados: la secretaria rara vez recuerda
            // el nombre completo del colegio, pero sí un trozo.
            $sql .= ' AND (nombre LIKE :busqueda OR distrito LIKE :busqueda2)';
            $parametros['busqueda']  = '%' . trim($busqueda) . '%';
            $parametros['busqueda2'] = '%' . trim($busqueda) . '%';
        }

        if ($tipo !== null && $tipo !== '') {
            $sql .= ' AND tipo = :tipo';
            $parametros['tipo'] = $tipo;
        }

        // Orden alfabético español: la Ñ va después de la N, no mezclada.
        $sql .= ' ORDER BY nombre' . Database::ordenEspanol() . ' ASC'
              . ' LIMIT ' . max(1, min($limite, 500));

        return Database::todos($sql, $parametros);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function porId(int $id): ?array
    {
        /*
         * El docente delegado ya no vive aquí (D-28): es una fila de
         * `apoderados` y esta tabla solo guarda a cuál apunta. Se trae con un
         * JOIN y se expone con los mismos nombres `docente_delegado_*` que
         * usaban las columnas embebidas, para que el formulario y las vistas
         * sigan leyendo lo mismo. El JOIN es interno y no externo a propósito:
         * la columna es NOT NULL, así que una I.E. sin encargado es una
         * inconsistencia que debe verse, no esconderse tras un LEFT JOIN.
         */
        return Database::uno(
            'SELECT ie.*,
                    a.dni        AS docente_delegado_dni,
                    a.ap_paterno AS docente_delegado_ap_paterno,
                    a.ap_materno AS docente_delegado_ap_materno,
                    a.nombres    AS docente_delegado_nombres,
                    a.celular    AS docente_delegado_celular,
                    a.correo     AS docente_delegado_correo
               FROM instituciones_educativas ie
               JOIN apoderados a ON a.id = ie.docente_delegado_id
              WHERE ie.id = :id
              LIMIT 1',
            ['id' => $id]
        );
    }

    /**
     * Posibles duplicados antes de crear una I.E. nueva.
     *
     * El plan pide "buscador para evitar duplicados" (Fase 2). No se impone un
     * UNIQUE sobre el nombre porque dos colegios distintos pueden llamarse
     * igual en distritos distintos — algo bastante común en Áncash. Se avisa
     * y decide la secretaria.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function posiblesDuplicados(string $nombre, ?int $exceptoId = null): array
    {
        $sql = 'SELECT ' . self::CAMPOS_LISTA . '
                  FROM instituciones_educativas
                 WHERE nombre LIKE :nombre';
        $parametros = ['nombre' => '%' . trim($nombre) . '%'];

        if ($exceptoId !== null) {
            $sql .= ' AND id <> :id';
            $parametros['id'] = $exceptoId;
        }

        return Database::todos(
            $sql . ' ORDER BY nombre' . Database::ordenEspanol() . ' ASC LIMIT 10',
            $parametros
        );
    }

    /**
     * @param array<string, mixed> $datos
     */
    public static function crear(array $datos): int
    {
        return Database::insertar(
            'INSERT INTO instituciones_educativas (
                nombre, distrito, provincia, departamento, tipo, direccion,
                docente_delegado_id,
                director_ap_paterno, director_ap_materno,
                director_nombres, director_celular,
                director_correo, director_dni
             ) VALUES (
                :nombre, :distrito, :provincia, :departamento, :tipo, :direccion,
                :docente_delegado_id,
                :di_ap_paterno, :di_ap_materno, :di_nombres, :di_celular,
                :di_correo, :di_dni
             )',
            $datos
        );
    }

    /**
     * @param array<string, mixed> $datos
     */
    public static function actualizar(int $id, array $datos): void
    {
        Database::ejecutar(
            'UPDATE instituciones_educativas SET
                nombre        = :nombre,
                distrito      = :distrito,
                provincia     = :provincia,
                departamento  = :departamento,
                tipo          = :tipo,
                direccion     = :direccion,
                docente_delegado_id = :docente_delegado_id,
                director_ap_paterno = :di_ap_paterno,
                director_ap_materno = :di_ap_materno,
                director_nombres    = :di_nombres,
                director_celular    = :di_celular,
                director_correo     = :di_correo,
                director_dni        = :di_dni
             WHERE id = :id',
            $datos + ['id' => $id]
        );
    }

    /**
     * Cuántos participantes dependen de esta I.E.
     *
     * Se consulta antes de ofrecer borrar: una I.E. con delegación inscrita
     * no puede eliminarse sin romper el historial.
     */
    public static function participantesAsociados(int $id): int
    {
        $fila = Database::uno(
            'SELECT COUNT(*) AS total FROM participantes WHERE institucion_id = :id',
            ['id' => $id]
        );

        return (int) ($fila['total'] ?? 0);
    }

    public static function eliminar(int $id): void
    {
        Database::ejecutar('DELETE FROM instituciones_educativas WHERE id = :id', ['id' => $id]);
    }

    public static function total(): int
    {
        $fila = Database::uno('SELECT COUNT(*) AS total FROM instituciones_educativas');

        return (int) ($fila['total'] ?? 0);
    }
}
