<?php

declare(strict_types=1);

namespace App\Models;

use Core\Database;

/**
 * Adulto responsable de uno o varios participantes.
 *
 * Cubre los dos casos del concurso (D-28):
 *
 *   · el **apoderado de un estudiante libre** — varios hermanos comparten uno
 *     (regla confirmada, sección 3 del plan);
 *   · el **docente delegado de una I.E.**, que es el encargado de su delegación
 *     y por tanto el apoderado de los treinta estudiantes que inscribe.
 *
 * Es una sola tabla y no dos porque es una sola persona: el mismo docente puede
 * además inscribir a su propio hijo como libre. Con dos tablas existiría dos
 * veces, con dos celulares que acabarían divergiendo y sin forma de saber cuál
 * es el bueno.
 *
 * Por eso el DNI es NOT NULL UNIQUE: es lo único que permite reconocer a la
 * persona y reutilizarla en vez de duplicarla.
 */
final class Apoderado
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listar(string $busqueda = '', int $limite = 100): array
    {
        /*
         * Los tres recuentos van como subconsultas y no como JOIN + GROUP BY a
         * propósito: al haber dos relaciones distintas hacia el mismo apoderado
         * —sus participantes y las instituciones que encabeza—, unirlas en la
         * misma consulta multiplicaría las filas y cada recuento saldría
         * inflado por el tamaño de la otra relación.
         *
         * Con ellos la vista distingue la modalidad de cada apoderado, que
         * puede ser las dos a la vez: el docente que encabeza la delegación de
         * su colegio y además inscribió a su propio hijo como estudiante libre.
         */
        $sql = 'SELECT a.id, a.dni, a.ap_paterno, a.ap_materno, a.nombres, a.celular, a.correo,
                       (SELECT COUNT(*) FROM participantes p
                         WHERE p.apoderado_id = a.id) AS estudiantes,
                       (SELECT COUNT(*) FROM participantes p
                         WHERE p.apoderado_id = a.id
                           AND p.tipo_participante = \'libre\') AS estudiantes_libres,
                       (SELECT COUNT(*) FROM instituciones_educativas ie
                         WHERE ie.docente_delegado_id = a.id) AS delegaciones
                  FROM apoderados a
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

        $sql .= ' ORDER BY a.ap_paterno' . $es . ' ASC,
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
            'INSERT INTO apoderados (dni, ap_paterno, ap_materno, nombres, celular, correo)
                  VALUES (:dni, :ap_paterno, :ap_materno, :nombres, :celular, :correo)',
            $datos + ['correo' => null]
        );
    }

    /**
     * @param array<string, mixed> $datos
     */
    public static function actualizar(int $id, array $datos): void
    {
        /*
         * El correo solo se toca si quien llama lo trae. No es un capricho: al
         * docente delegado se le exige correo, pero el formulario de inscripción
         * libre no tiene ese campo. Si actualizara siempre, inscribir a un
         * estudiante libre cuyo apoderado resulta ser también docente delegado
         * de un colegio le borraría el correo por el que se le escribe a su
         * delegación entera, sin que nadie tocara ese formulario.
         */
        $correo = array_key_exists('correo', $datos) ? ', correo = :correo' : '';

        if ($correo === '') {
            unset($datos['correo']);
        }

        Database::ejecutar(
            'UPDATE apoderados SET
                dni        = :dni,
                ap_paterno = :ap_paterno,
                ap_materno = :ap_materno,
                nombres    = :nombres,
                celular    = :celular' . $correo . '
             WHERE id = :id',
            $datos + ['id' => $id]
        );
    }

    /**
     * Participantes vinculados —libres y de delegación—. Se consulta antes de
     * permitir borrar.
     */
    public static function estudiantesVinculados(int $id): int
    {
        $fila = Database::uno(
            'SELECT COUNT(*) AS total FROM participantes WHERE apoderado_id = :id',
            ['id' => $id]
        );

        return (int) ($fila['total'] ?? 0);
    }

    /**
     * Instituciones que lo tienen como docente delegado.
     *
     * Se consulta antes de borrar para dar un motivo entendible. Sin esto la
     * clave foránea lo impediría igual, pero el error que vería la secretaria
     * sería un fallo de integridad de MySQL en lugar de «es el encargado de la
     * delegación de tal colegio».
     *
     * @return array<int, array<string, mixed>>
     */
    public static function delegacionesQueEncabeza(int $id): array
    {
        return Database::todos(
            'SELECT id, nombre FROM instituciones_educativas WHERE docente_delegado_id = :id',
            ['id' => $id]
        );
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
