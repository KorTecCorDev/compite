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
 * Los datos del docente delegado y del director viven aquí, no en cada
 * inscripción: son datos persistentes de la I.E. y si cambian, se actualizan
 * sobre el mismo registro.
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
        return Database::uno(
            'SELECT * FROM instituciones_educativas WHERE id = :id LIMIT 1',
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
                docente_delegado_ap_paterno, docente_delegado_ap_materno,
                docente_delegado_nombres, docente_delegado_celular,
                docente_delegado_correo, docente_delegado_dni,
                director_ap_paterno, director_ap_materno,
                director_nombres, director_celular,
                director_correo, director_dni
             ) VALUES (
                :nombre, :distrito, :provincia, :departamento, :tipo, :direccion,
                :dd_ap_paterno, :dd_ap_materno, :dd_nombres, :dd_celular,
                :dd_correo, :dd_dni,
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
                docente_delegado_ap_paterno = :dd_ap_paterno,
                docente_delegado_ap_materno = :dd_ap_materno,
                docente_delegado_nombres    = :dd_nombres,
                docente_delegado_celular    = :dd_celular,
                docente_delegado_correo     = :dd_correo,
                docente_delegado_dni        = :dd_dni,
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
