<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Models\Inscripcion;
use Core\Database;
use Core\Fecha;

/**
 * La rendición de cuentas del concurso (D-62).
 *
 * Es el documento que se entrega a dirección cuando el concurso ya terminó:
 * cuánto se recaudó, por qué vías, en manos de quién, y **por qué el número de
 * inscripciones no es el número de personas ni el número de soles**.
 *
 * **Todo es de solo lectura, y esa es la decisión de fondo.** Los sobre
 * registros encontrados no se borran ni se corrigen en la base: se identifican,
 * se cuantifican y se declaran en un anexo. Un registro contable no se arregla
 * borrando, se arregla revelando — y el volcado del concurso es prueba de lo
 * que pasó, no un borrador.
 *
 * Las cifras salen todas del mismo conjunto de filas —el de
 * `Inscripcion::DESDE_COBROS_VIGENTES`, que cuenta cada pago una sola vez— así
 * que los cuatro desgloses cuadran contra el mismo total por construcción y no
 * por casualidad.
 *
 * Las horas se leen convertidas (`Core\Fecha`): tal como están guardadas, 191
 * cobros del viernes por la noche figuran como del sábado, y un cierre por día
 * sin esa corrección reparte mal S/ 1 965,00.
 */
final class Rendicion
{
    /**
     * Umbral de evidencia para descontar un cobro como indebido.
     *
     * Dos personas con el **mismo nombre completo** pueden existir; dos con el
     * mismo nombre completo **en el mismo colegio y el mismo grado** son la
     * misma persona registrada dos veces. Solo ese caso se descuenta del
     * ingreso; el resto se declara como observación para que lo juzgue una
     * persona, que es lo que corresponde cuando se trata de decidir si a un
     * niño se le cobró dos veces.
     */
    private const AGRUPACION_ESTRICTA = 'p.ap_paterno, p.ap_materno, p.nombres, p.institucion_id, i.categoria_id';

    /**
     * @param array<string, mixed> $concurso
     * @return array<string, mixed>
     */
    public static function armar(array $concurso): array
    {
        $con = (int) $concurso['id'];

        $duplicados = self::duplicadosEstrictos($con);
        $indebido   = 0.0;

        foreach ($duplicados as $grupo) {
            $indebido += (float) $grupo['importe_indebido'];
        }

        $bruto = self::bruto($con);

        return [
            'concurso'       => $concurso,
            'padron'         => self::padron($con),
            'recuento'       => self::recuento($con),
            'bruto'          => $bruto,
            'indebido'       => $indebido,
            'neto'           => $bruto - $indebido,
            'por_dia'        => self::porDia($con),
            'por_medio'      => self::porDimension($con, 'i.medio_pago'),
            'por_modalidad'  => self::porDimension($con, 'i.tipo_origen'),
            'por_cobrador'   => self::porCobrador($con),
            'duplicados'     => $duplicados,
            'homonimos'      => self::homonimos($con),
            'pagos_dobles'   => self::pagosEscritosDosVeces($con),
            'anulaciones'    => self::anulaciones($con),
            'nominal'        => self::nominal($con),
        ];
    }

    /**
     * El total cobrado, contando cada pago una sola vez.
     */
    private static function bruto(int $con): float
    {
        $fila = Database::uno(
            'SELECT COALESCE(SUM(i.monto), 0) AS soles ' . Inscripcion::DESDE_COBROS_VIGENTES,
            ['con' => $con]
        );

        return (float) ($fila['soles'] ?? 0);
    }

    /**
     * Los recuentos que sostienen la cadena de conciliación.
     *
     * @return array<string, int>
     */
    private static function recuento(int $con): array
    {
        $fila = Database::uno(
            "SELECT COUNT(*) AS inscripciones,
                    SUM(i.estado = 'confirmada') AS confirmadas,
                    SUM(i.estado = 'pendiente')  AS pendientes,
                    SUM(i.estado = 'anulada')    AS anuladas
               FROM inscripciones i
               JOIN participantes p ON p.id = i.participante_id
              WHERE p.concurso_id = :con",
            ['con' => $con]
        );

        return [
            'inscripciones' => (int) ($fila['inscripciones'] ?? 0),
            'confirmadas'   => (int) ($fila['confirmadas'] ?? 0),
            'pendientes'    => (int) ($fila['pendientes'] ?? 0),
            'anuladas'      => (int) ($fila['anuladas'] ?? 0),
        ];
    }

    private static function padron(int $con): int
    {
        $fila = Database::uno(
            'SELECT COUNT(*) AS total FROM participantes WHERE concurso_id = :con',
            ['con' => $con]
        );

        return (int) ($fila['total'] ?? 0);
    }

    /**
     * Recaudación por día, **en la fecha real** y no en la del servidor.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function porDia(int $con): array
    {
        $local = Fecha::sqlLocal('i.fecha_pago');

        return Database::todos(
            'SELECT DATE(' . $local . ') AS dia,
                    COUNT(*) AS cobros,
                    COALESCE(SUM(i.monto), 0) AS soles
             ' . Inscripcion::DESDE_COBROS_VIGENTES . '
          GROUP BY dia
          ORDER BY dia',
            ['con' => $con]
        );
    }

    /**
     * Recaudación agrupada por una columna de la propia inscripción.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function porDimension(int $con, string $columna): array
    {
        return Database::todos(
            'SELECT ' . $columna . ' AS clave,
                    COUNT(*) AS cobros,
                    COALESCE(SUM(i.monto), 0) AS soles
             ' . Inscripcion::DESDE_COBROS_VIGENTES . '
          GROUP BY clave
          ORDER BY soles DESC',
            ['con' => $con]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function porCobrador(int $con): array
    {
        return Database::todos(
            "SELECT COALESCE(u.nombres, '(sin firma)') AS clave,
                    COUNT(*) AS cobros,
                    COALESCE(SUM(i.monto), 0) AS soles
             " . Inscripcion::DESDE_COBROS_VIGENTES . '
          GROUP BY i.confirmado_por, u.nombres
          ORDER BY soles DESC',
            ['con' => $con]
        );
    }

    /**
     * **Cobros duplicados**: la misma persona, en el mismo colegio y el mismo
     * grado, inscrita y cobrada más de una vez.
     *
     * `importe_indebido` deja fuera el cobro más antiguo del grupo —ese es el
     * legítimo— y suma el resto. Es lo que se descuenta del ingreso, por
     * decisión del propietario (22-ago): ese dinero no es del concurso.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function duplicadosEstrictos(int $con): array
    {
        return Database::todos(
            "SELECT CONCAT(p.ap_paterno, ' ', p.ap_materno, ', ', p.nombres) AS nombre,
                    ie.nombre AS institucion,
                    cat.nivel, cat.grado,
                    COUNT(*) AS veces,
                    GROUP_CONCAT(p.dni ORDER BY p.id SEPARATOR ' · ')                 AS documentos,
                    GROUP_CONCAT(p.codigo_correlativo ORDER BY p.id SEPARATOR ' · ')  AS codigos,
                    GROUP_CONCAT(i.id ORDER BY i.id SEPARATOR ' · ')                  AS inscripciones,
                    COALESCE(SUM(i.monto), 0) AS cobrado,
                    COALESCE(SUM(i.monto), 0) - MIN(i.monto) AS importe_indebido
               FROM inscripciones i
               JOIN participantes p ON p.id = i.participante_id
               JOIN categorias cat ON cat.id = i.categoria_id
          LEFT JOIN instituciones_educativas ie ON ie.id = p.institucion_id
              WHERE p.concurso_id = :con
                AND i.estado = 'confirmada'
                AND i.fecha_pago IS NOT NULL
           GROUP BY " . self::AGRUPACION_ESTRICTA . ", ie.nombre, cat.nivel, cat.grado
             HAVING COUNT(DISTINCT p.id) > 1
           ORDER BY nombre",
            ['con' => $con]
        );
    }

    /**
     * **Homónimos**: mismo nombre completo, pero en distinto colegio o grado.
     *
     * No se descuentan. Pueden ser dos personas distintas que se llaman igual,
     * y decidirlo mirando una tabla sería inventar. Se declaran para que quien
     * firme la rendición sepa que existen y pueda comprobarlos.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function homonimos(int $con): array
    {
        $estrictos = [];

        foreach (self::duplicadosEstrictos($con) as $grupo) {
            $estrictos[$grupo['nombre']] = true;
        }

        $filas = Database::todos(
            "SELECT CONCAT(p.ap_paterno, ' ', p.ap_materno, ', ', p.nombres) AS nombre,
                    COUNT(*) AS veces,
                    GROUP_CONCAT(p.dni ORDER BY p.id SEPARATOR ' · ') AS documentos,
                    GROUP_CONCAT(p.codigo_correlativo ORDER BY p.id SEPARATOR ' · ') AS codigos,
                    GROUP_CONCAT(COALESCE(ie.nombre, 'Libre') ORDER BY p.id SEPARATOR ' · ') AS procedencias
               FROM participantes p
          LEFT JOIN instituciones_educativas ie ON ie.id = p.institucion_id
              WHERE p.concurso_id = :con
           GROUP BY p.ap_paterno, p.ap_materno, p.nombres
             HAVING COUNT(*) > 1
           ORDER BY nombre",
            ['con' => $con]
        );

        // Los que ya salen como cobro duplicado no se repiten aquí: se
        // declararían dos veces y con dos lecturas distintas.
        return array_values(array_filter(
            $filas,
            static fn (array $f): bool => !isset($estrictos[$f['nombre']])
        ));
    }

    /**
     * **Un pago escrito en dos filas**: la copia que deja la reinscripción.
     *
     * No es dinero de más —entró una sola vez— pero sí es un registro de más, y
     * quien sume la columna sin la regla de D-59 se lleva un total inflado. Por
     * eso se declara con su importe al lado: para que el lector vea de cuánto
     * sería el error si lo contara dos veces.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function pagosEscritosDosVeces(int $con): array
    {
        return Database::todos(
            "SELECT CONCAT(p.ap_paterno, ' ', p.ap_materno, ', ', p.nombres) AS nombre,
                    p.codigo_correlativo, p.dni,
                    COUNT(*) AS filas_con_pago,
                    MIN(i.monto) AS monto_real,
                    COALESCE(SUM(i.monto), 0) - MIN(i.monto) AS riesgo_de_doble_conteo,
                    GROUP_CONCAT(CONCAT(i.id, ' (', i.estado, ')') ORDER BY i.id SEPARATOR ' · ') AS inscripciones
               FROM inscripciones i
               JOIN participantes p ON p.id = i.participante_id
              WHERE p.concurso_id = :con
                AND i.fecha_pago IS NOT NULL
           GROUP BY p.id
             HAVING filas_con_pago > 1
           ORDER BY nombre",
            ['con' => $con]
        );
    }

    /**
     * Las anulaciones, con su motivo y si arrastraban dinero.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function anulaciones(int $con): array
    {
        return Database::todos(
            "SELECT i.id, i.monto, i.motivo_anulacion, i.requiere_devolucion,
                    i.fecha_pago,
                    CONCAT(p.ap_paterno, ' ', p.ap_materno, ', ', p.nombres) AS nombre,
                    p.codigo_correlativo,
                    COALESCE(ie.nombre, 'Libre') AS procedencia,
                    u.nombres AS anulada_por
               FROM inscripciones i
               JOIN participantes p ON p.id = i.participante_id
          LEFT JOIN instituciones_educativas ie ON ie.id = p.institucion_id
          LEFT JOIN usuarios u ON u.id = i.anulado_por
              WHERE p.concurso_id = :con
                AND i.estado = 'anulada'
           ORDER BY i.id",
            ['con' => $con]
        );
    }

    /**
     * El padrón nominal completo, anexo del documento.
     *
     * Va **todo el padrón** y no solo quien compitió, por decisión del
     * propietario (22-ago): una rendición que solo lista a los que pagaron no
     * permite rastrear las bajas, que es justo lo que alguien va a querer
     * comprobar.
     *
     * Orden alfabético con la colación española, como el acta.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function nominal(int $con): array
    {
        $es = Database::ordenEspanol();

        return Database::todos(
            "SELECT i.id, i.estado, i.monto, i.medio_pago, i.fecha_pago, i.tipo_origen,
                    i.yape_codigo_seguridad,
                    p.codigo_correlativo, p.dni,
                    p.ap_paterno, p.ap_materno, p.nombres,
                    COALESCE(ie.nombre, 'Libre') AS procedencia,
                    cat.nivel, cat.grado,
                    u.nombres AS cobrador,
                    (i.fecha_pago IS NOT NULL AND i.id = " . Inscripcion::FILA_DE_PAGO_VIGENTE . ") AS pago_contado
               FROM inscripciones i
               JOIN participantes p ON p.id = i.participante_id
               JOIN categorias cat ON cat.id = i.categoria_id
          LEFT JOIN usuarios u ON u.id = i.confirmado_por
          LEFT JOIN instituciones_educativas ie ON ie.id = p.institucion_id
              WHERE p.concurso_id = :con
           ORDER BY p.ap_paterno" . $es . ' ASC,
                    p.ap_materno' . $es . ' ASC,
                    p.nombres'    . $es . ' ASC,
                    i.id ASC',
            ['con' => $con]
        );
    }
}
