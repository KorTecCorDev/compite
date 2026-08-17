<?php

declare(strict_types=1);

namespace App\Models;

use Core\Database;

/**
 * Inscripción: una fila POR PARTICIPANTE, aunque el alta se haga en bloque
 * para una delegación (regla confirmada, sección 3 del plan).
 *
 * Esa granularidad no es capricho: el futuro módulo de calificación necesita
 * atribuir resultados a la institución de origen para premiar a la mejor.
 */
final class Inscripcion
{
    /**
     * @param array<string, mixed> $datos
     */
    public static function crear(array $datos): int
    {
        return Database::insertar(
            'INSERT INTO inscripciones (participante_id, categoria_id, usuario_id, estado, monto)
                  VALUES (:participante, :categoria, :usuario, :estado, :monto)',
            [
                'participante' => $datos['participante_id'],
                'categoria'    => $datos['categoria_id'],
                'usuario'      => $datos['usuario_id'],
                'estado'       => $datos['estado'] ?? 'pendiente',
                'monto'        => $datos['monto'],
            ]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function porId(int $id): ?array
    {
        return Database::uno(
            'SELECT i.*, p.codigo_correlativo, p.dni, p.ap_paterno, p.ap_materno,
                    p.nombres, p.tipo_participante, p.institucion_id, p.apoderado_id,
                    ie.nombre AS institucion, ie.tipo AS institucion_tipo,
                    cat.nivel, cat.grado,
                    u.nombres AS registrado_por
               FROM inscripciones i
               JOIN participantes p ON p.id = i.participante_id
               JOIN categorias cat ON cat.id = i.categoria_id
               JOIN usuarios u ON u.id = i.usuario_id
          LEFT JOIN instituciones_educativas ie ON ie.id = p.institucion_id
              WHERE i.id = :id
              LIMIT 1',
            ['id' => $id]
        );
    }

    /**
     * Listado con los filtros que pide el plan: por delegación (I.E.), por
     * tipo de origen y por grado, combinables.
     *
     * @param array<string, mixed> $filtros
     * @return array<int, array<string, mixed>>
     */
    public static function listar(int $concursoId, array $filtros = []): array
    {
        $sql = "SELECT i.id, i.estado, i.monto, i.medio_pago, i.fecha_pago,
                       i.requiere_devolucion, i.created_at,
                       p.codigo_correlativo, p.dni, p.ap_paterno, p.ap_materno,
                       p.nombres, p.tipo_participante,
                       ie.nombre AS institucion, ie.tipo AS institucion_tipo,
                       cat.nivel, cat.grado
                  FROM inscripciones i
                  JOIN participantes p ON p.id = i.participante_id
                  JOIN categorias cat ON cat.id = i.categoria_id
             LEFT JOIN instituciones_educativas ie ON ie.id = p.institucion_id
                 WHERE p.concurso_id = :concurso";

        $parametros = ['concurso' => $concursoId];

        if (!empty($filtros['institucion_id'])) {
            $sql .= ' AND p.institucion_id = :institucion';
            $parametros['institucion'] = (int) $filtros['institucion_id'];
        }

        // 'publica' / 'privada' miran el tipo de la I.E.; 'libre' mira el tipo
        // de participante, porque un estudiante libre no tiene institución.
        if (!empty($filtros['tipo_origen'])) {
            if ($filtros['tipo_origen'] === 'libre') {
                $sql .= " AND p.tipo_participante = 'libre'";
            } else {
                $sql .= " AND p.tipo_participante = 'delegacion' AND ie.tipo = :tipo_ie";
                $parametros['tipo_ie'] = $filtros['tipo_origen'];
            }
        }

        if (!empty($filtros['nivel'])) {
            $sql .= ' AND cat.nivel = :nivel';
            $parametros['nivel'] = $filtros['nivel'];
        }

        if (!empty($filtros['grado'])) {
            $sql .= ' AND cat.grado = :grado';
            $parametros['grado'] = (int) $filtros['grado'];
        }

        if (!empty($filtros['estado'])) {
            $sql .= ' AND i.estado = :estado';
            $parametros['estado'] = $filtros['estado'];
        }

        if (!empty($filtros['q'])) {
            $sql .= ' AND (p.dni LIKE :q1
                        OR p.ap_paterno LIKE :q2
                        OR p.ap_materno LIKE :q3
                        OR p.nombres LIKE :q4
                        OR p.codigo_correlativo LIKE :q5)';
            $termino = '%' . trim((string) $filtros['q']) . '%';
            foreach (['q1', 'q2', 'q3', 'q4', 'q5'] as $clave) {
                $parametros[$clave] = $termino;
            }
        }

        /*
         * Orden de nómina peruana: apellido paterno, materno y nombres, con la
         * colación española para que la Ñ caiga después de la N y no mezclada
         * entre ellas.
         *
         * El desempate final por `i.id` importa cuando un participante tiene
         * una inscripción anulada y su reinscripción: quedan juntas y en el
         * orden en que ocurrieron, que es como se lee el historial.
         */
        $es = Database::ordenEspanol();

        $sql .= ' ORDER BY p.ap_paterno' . $es . ' ASC,
                           p.ap_materno' . $es . ' ASC,
                           p.nombres'    . $es . ' ASC,
                           i.id ASC
                  LIMIT 500';

        return Database::todos($sql, $parametros);
    }

    /**
     * Resumen por estado, para el panel.
     *
     * @return array<string, mixed>
     */
    public static function resumen(int $concursoId): array
    {
        $filas = Database::todos(
            'SELECT i.estado, COUNT(*) AS total, COALESCE(SUM(i.monto), 0) AS monto
               FROM inscripciones i
               JOIN participantes p ON p.id = i.participante_id
              WHERE p.concurso_id = :concurso
           GROUP BY i.estado',
            ['concurso' => $concursoId]
        );

        $resumen = [
            'pendientes'  => 0,
            'confirmadas' => 0,
            'anuladas'    => 0,
            'recaudado'   => 0.0,
            'por_cobrar'  => 0.0,
        ];

        foreach ($filas as $fila) {
            $estado = (string) $fila['estado'];
            $total  = (int) $fila['total'];
            $monto  = (float) $fila['monto'];

            if ($estado === 'pendiente') {
                $resumen['pendientes'] = $total;
                $resumen['por_cobrar'] = $monto;
            } elseif ($estado === 'confirmada') {
                $resumen['confirmadas'] = $total;
                $resumen['recaudado']   = $monto;
            } elseif ($estado === 'anulada') {
                $resumen['anuladas'] = $total;
            }
        }

        return $resumen;
    }

    /**
     * Inscripción activa (no anulada) de un participante, si la tiene.
     *
     * @return array<string, mixed>|null
     */
    public static function activaDe(int $participanteId): ?array
    {
        return Database::uno(
            "SELECT * FROM inscripciones
              WHERE participante_id = :p AND estado <> 'anulada'
           ORDER BY id DESC LIMIT 1",
            ['p' => $participanteId]
        );
    }

    // ==================================================================
    // Fase 4 — pagos y anulación
    // ==================================================================

    /**
     * Inscripciones pendientes, filtradas por ids y acotadas al concurso.
     *
     * Acotar al concurso no es paranoia: los ids llegan por POST desde las
     * casillas del listado y se pueden manipular. Sin este filtro, alguien
     * podría confirmar el pago de una inscripción de otro concurso.
     *
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>>
     */
    public static function pendientesPorIds(array $ids, int $concursoId): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0));

        if ($ids === []) {
            return [];
        }

        // Marcadores nombrados uno por id: nunca se interpola el array en el SQL.
        $marcadores = [];
        $parametros = ['concurso' => $concursoId];

        foreach ($ids as $posicion => $id) {
            $clave = 'id' . $posicion;
            $marcadores[] = ':' . $clave;
            $parametros[$clave] = $id;
        }

        return Database::todos(
            "SELECT i.id, i.estado, i.monto, i.participante_id
               FROM inscripciones i
               JOIN participantes p ON p.id = i.participante_id
              WHERE i.id IN (" . implode(',', $marcadores) . ")
                AND p.concurso_id = :concurso
                AND i.estado = 'pendiente'",
            $parametros
        );
    }

    /**
     * Marca una inscripción como pagada.
     *
     * El plan es explícito: el pago se considera cobrado en el momento en que
     * la secretaria confirma en el sistema.
     */
    public static function confirmarPago(
        int $id,
        string $medioPago,
        ?string $yapeCodigo
    ): void {
        Database::ejecutar(
            "UPDATE inscripciones
                SET estado = 'confirmada',
                    medio_pago = :medio,
                    yape_codigo_seguridad = :yape,
                    fecha_pago = NOW()
              WHERE id = :id AND estado = 'pendiente'",
            [
                'medio' => $medioPago,
                // Solo se guarda para Yape; en transferencia y efectivo queda NULL.
                'yape'  => $medioPago === 'yape' ? $yapeCodigo : null,
                'id'    => $id,
            ]
        );
    }

    /**
     * Anula una inscripción.
     *
     * `requiere_devolucion` solo tiene sentido si ya había pago confirmado:
     * una inscripción pendiente nunca cobró nada, así que no hay qué devolver.
     * Por eso se decide aquí y no se acepta desde el formulario.
     */
    public static function anular(int $id, string $motivo, bool $esDefinitiva): void
    {
        $actual = self::porId($id);

        if ($actual === null || $actual['estado'] === 'anulada') {
            return;
        }

        $requiereDevolucion = $esDefinitiva && $actual['estado'] === 'confirmada';

        Database::ejecutar(
            "UPDATE inscripciones
                SET estado = 'anulada',
                    motivo_anulacion = :motivo,
                    requiere_devolucion = :devolucion
              WHERE id = :id",
            [
                'motivo'     => $motivo,
                'devolucion' => $requiereDevolucion ? 1 : 0,
                'id'         => $id,
            ]
        );
    }

    /**
     * Fondo de devoluciones: no es una entidad, es este listado
     * (regla confirmada, sección 3 del plan).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fondoDevoluciones(int $concursoId): array
    {
        $es = Database::ordenEspanol();

        return Database::todos(
            'SELECT i.id, i.monto, i.motivo_anulacion, i.medio_pago, i.fecha_pago,
                    i.updated_at,
                    p.codigo_correlativo, p.dni, p.ap_paterno, p.ap_materno, p.nombres,
                    ie.nombre AS institucion,
                    cat.nivel, cat.grado
               FROM inscripciones i
               JOIN participantes p ON p.id = i.participante_id
               JOIN categorias cat ON cat.id = i.categoria_id
          LEFT JOIN instituciones_educativas ie ON ie.id = p.institucion_id
              WHERE p.concurso_id = :con
                AND i.requiere_devolucion = TRUE
           ORDER BY p.ap_paterno' . $es . ' ASC,
                    p.ap_materno' . $es . ' ASC,
                    p.nombres'    . $es . ' ASC',
            ['con' => $concursoId]
        );
    }
}
