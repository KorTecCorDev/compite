<?php

declare(strict_types=1);

namespace App\Models;

use Core\Database;
use Core\Fecha;
use InvalidArgumentException;

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
     * Tope de filas del listado.
     *
     * Existe para que una consulta sin filtrar no intente pintar el concurso
     * entero en una sola tabla. Era 500, y 500 se quedó corto cuando el colegio
     * anfitrión pasó a inscribir a sus propios estudiantes (D-37): todas sus
     * secciones cuelgan de UN solo `institucion_id`, así que filtrar por esa
     * delegación puede traer cientos de filas.
     *
     * Lo grave no era el número sino el silencio: se cortaba sin avisar, y la
     * misma consulta alimenta `/delegaciones/{id}/carnes.pdf`, así que la hoja
     * de carnés habría salido incompleta y nadie lo habría notado hasta que
     * faltaran carnés en la puerta. Ahora el listado compara con
     * `contarFiltradas()` y lo dice.
     */
    public const TOPE_LISTADO = 2000;

    /**
     * Claves de filtro que entiende el listado, en un solo sitio.
     *
     * Existe desde D-48. El listado ya no puede volver con un filtro que el
     * usuario no eligió, pero sí tiene que poder devolverle EL SUYO cuando una
     * validación lo saca de la pantalla a medio cobrar. Esta lista es la que
     * decide qué se acepta al reconstruir esa URL: sin ella, el «volver» del
     * formulario de cobro sería una cadena arbitraria puesta por el cliente y
     * quedaría a un paso de una redirección abierta.
     *
     * Comparte propósito con `condiciones()` —son las mismas seis claves— y va
     * junto a ella a propósito: quien añada un filtro nuevo tiene los dos sitios
     * a la vista.
     */
    public const FILTROS = ['institucion_id', 'tipo_origen', 'nivel', 'grado', 'estado', 'q'];

    /**
     * Los filtros de la grilla de cobros (D-61).
     *
     * Son los seis de arriba —el WHERE lo arma la misma `condiciones()`— más
     * los cuatro que solo tienen sentido mirando dinero: con qué se pagó, quién
     * lo confirmó y entre qué fechas.
     *
     * Es una lista aparte y no una ampliación de `FILTROS` porque `FILTROS` es
     * lo que `urlListado()` acepta al reconstruir la vuelta al listado de
     * `/inscripciones`, y esa pantalla no sabe nada de estos cuatro.
     */
    public const FILTROS_COBROS = [
        'institucion_id', 'tipo_origen', 'nivel', 'grado', 'estado', 'q',
        'medio_pago', 'confirmado_por', 'desde', 'hasta',
    ];

    /**
     * Reconstruye la URL del listado quedándose solo con las claves conocidas.
     *
     * @param array<string, mixed> $origen
     */
    public static function urlListado(array $origen): string
    {
        $limpios = [];

        foreach (self::FILTROS as $clave) {
            $valor = trim((string) ($origen[$clave] ?? ''));

            if ($valor !== '') {
                $limpios[$clave] = $valor;
            }
        }

        return '/inscripciones' . ($limpios === [] ? '' : '?' . http_build_query($limpios));
    }

    /**
     * @param array<string, mixed> $datos
     */
    public static function crear(array $datos): int
    {
        /*
         * `tipo_origen` es obligatorio (D-37): es la modalidad que eligió el
         * monto, y se congela con él. Quien crea una inscripción tiene que
         * decir con cuál se cobró — deducirla después del colegio es justo lo
         * que permitía que las dos se contradijeran.
         *
         * La comprobación va aquí porque LA BASE NO PROTEGE. Comprobado sobre
         * MariaDB 10.4 con STRICT_TRANS_TABLES activo: un INSERT que omite una
         * columna ENUM NOT NULL sin default no se rechaza — se rellena con el
         * PRIMER valor del ENUM, que aquí es 'publica'. Un olvido no explotaría:
         * marcaría como pública una inscripción privada de S/ 15.00 y el carné
         * saldría contradiciendo a la tarifa cobrada, que es exactamente lo que
         * D-37 vino a cerrar. Preferimos el fallo ruidoso.
         */
        $modalidades = ['publica', 'privada', 'libre', 'organizadora'];

        if (!in_array($datos['tipo_origen'] ?? null, $modalidades, true)) {
            throw new InvalidArgumentException(
                'La inscripción necesita una modalidad válida (' . implode(', ', $modalidades)
                . '); se recibió ' . var_export($datos['tipo_origen'] ?? null, true) . '.'
            );
        }

        /*
         * `medio_pago` y `fecha_pago` viajan cuando la inscripción nace ya
         * pagada, que ocurre al corregir una categoría o al reinscribir a quien
         * ya había pagado. Sin ellos, la nueva quedaba «confirmada» pero sin
         * rastro de CÓMO se cobró: la secretaria cuadrando la caja al final del
         * día veía un pago confirmado sin medio, y el código de seguridad de
         * Yape —que es la prueba de esa transacción— desaparecía.
         *
         * `confirmado_por` viaja con ellos desde D-60, y faltaba: la fila nacía
         * confirmada, con fecha y medio de pago, pero SIN FIRMA. Un cobro sin
         * dueño es justo lo que D-39 vino a cerrar, y la reinscripción lo
         * reintroducía en silencio. Es la firma del cobrador ORIGINAL: esa plata
         * la recibió él, y quién reinscribe ya queda en `usuario_id`.
         */
        return Database::insertar(
            'INSERT INTO inscripciones (
                participante_id, categoria_id, usuario_id, estado, tipo_origen, monto,
                medio_pago, yape_codigo_seguridad, fecha_pago, confirmado_por
             ) VALUES (
                :participante, :categoria, :usuario, :estado, :tipo_origen, :monto,
                :medio_pago, :yape, :fecha_pago, :confirmado_por
             )',
            [
                'participante'   => $datos['participante_id'],
                'categoria'      => $datos['categoria_id'],
                'usuario'        => $datos['usuario_id'],
                'estado'         => $datos['estado'] ?? 'pendiente',
                'tipo_origen'    => $datos['tipo_origen'],
                'monto'          => $datos['monto'],
                'medio_pago'     => $datos['medio_pago'] ?? null,
                'yape'           => $datos['yape_codigo_seguridad'] ?? null,
                'fecha_pago'     => $datos['fecha_pago'] ?? null,
                'confirmado_por' => $datos['confirmado_por'] ?? null,
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
                    ie.nombre AS institucion,
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
                       ie.nombre AS institucion, i.tipo_origen,
                       cat.nivel, cat.grado,
                       -- Quién registró la inscripción (D-39). Es el único de los
                       -- tres firmantes que se muestra en el listado, por decisión
                       -- del propietario; los otros dos —quién cobró y quién anuló—
                       -- quedan guardados en `confirmado_por` y `anulado_por`.
                       u.nombres AS registrado_por,
                       -- El DUEÑO de la fila (D-52). El nombre de arriba es para
                       -- leerlo; este id es con lo que la vista decide si dibuja
                       -- «Corregir» y «Reinscribir», y no se puede deducir del
                       -- nombre sin volver a consultar la tabla de usuarios.
                       i.usuario_id,
                       /*
                        * ¿Le queda al participante alguna inscripción viva?
                        *
                        * Distingue las dos anuladas que se ven igual en el
                        * listado y no lo son: la que dejó atrás una corrección
                        * —el estudiante sigue inscrito, no hay nada que hacer—
                        * y la que dejó a alguien FUERA del concurso, que es la
                        * única que se puede reinscribir. Sin esto, el enlace de
                        * reinscribir saldría en todas y sería erróneo en casi
                        * todas, porque cada corrección deja una anulada detrás.
                        */
                       EXISTS (
                           SELECT 1 FROM inscripciones i2
                            WHERE i2.participante_id = p.id
                              AND i2.estado <> 'anulada'
                       ) AS participante_activo
                  FROM inscripciones i
                  JOIN participantes p ON p.id = i.participante_id
                  JOIN categorias cat ON cat.id = i.categoria_id
                  JOIN usuarios u ON u.id = i.usuario_id
             LEFT JOIN instituciones_educativas ie ON ie.id = p.institucion_id
                 WHERE p.concurso_id = :concurso";

        [$filtro, $parametros] = self::condiciones($concursoId, $filtros);
        $sql .= $filtro;

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
                  LIMIT ' . self::TOPE_LISTADO;

        return Database::todos($sql, $parametros);
    }

    /**
     * Cuántas inscripciones cumplen esos filtros, sin tope.
     *
     * La usa el listado para saber si `TOPE_LISTADO` dejó filas fuera. Comparte
     * las condiciones con `listar()` a través de `condiciones()`: con el WHERE
     * duplicado, cualquier filtro nuevo se aplicaría en un sitio y no en el
     * otro, y el aviso de «hay más» mentiría.
     *
     * @param array<string, mixed> $filtros
     */
    public static function contarFiltradas(int $concursoId, array $filtros = []): int
    {
        [$filtro, $parametros] = self::condiciones($concursoId, $filtros);

        $fila = Database::uno(
            'SELECT COUNT(*) AS total
               FROM inscripciones i
               JOIN participantes p ON p.id = i.participante_id
               JOIN categorias cat ON cat.id = i.categoria_id
          LEFT JOIN instituciones_educativas ie ON ie.id = p.institucion_id
              WHERE p.concurso_id = :concurso' . $filtro,
            $parametros
        );

        return (int) ($fila['total'] ?? 0);
    }

    /**
     * Las condiciones del listado, compartidas por `listar()` y por
     * `contarFiltradas()`.
     *
     * @param array<string, mixed> $filtros
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function condiciones(int $concursoId, array $filtros): array
    {
        $sql = '';
        $parametros = ['concurso' => $concursoId];

        if (!empty($filtros['institucion_id'])) {
            $sql .= ' AND p.institucion_id = :institucion';
            $parametros['institucion'] = (int) $filtros['institucion_id'];
        }

        /*
         * La modalidad se lee de la inscripción, no se reconstruye (D-37).
         * Antes esto tenía dos ramas —una mirando `ie.tipo` y otra
         * `p.tipo_participante`— porque la modalidad no se guardaba en ningún
         * sitio. Ahora es la columna que decidió el monto, así que filtrar es
         * comparar, y el filtro no puede discrepar de lo que dice el carné.
         */
        if (!empty($filtros['tipo_origen'])) {
            $sql .= ' AND i.tipo_origen = :tipo_origen';
            $parametros['tipo_origen'] = $filtros['tipo_origen'];
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
         * A partir de aquí, los filtros de la GRILLA DE COBROS (D-61). Ninguno
         * lo envía el listado de `/inscripciones`, así que allí no cambian nada.
         *
         * Van en esta misma función y no en una paralela porque es aquí donde un
         * filtro se convierte en SQL: con dos funciones, `contarFiltradas()`
         * contaría con unas condiciones y la grilla pintaría con otras, y el
         * aviso de «hay más filas» mentiría en cuanto se usara un filtro nuevo.
         *
         * Solo se referencian columnas de `i`, `p` y `cat`: `contarFiltradas()`
         * no une `usuarios`, así que filtrar por quién cobró se hace contra
         * `i.confirmado_por`, que es el id y no necesita el JOIN.
         */
        if (!empty($filtros['medio_pago'])) {
            if ($filtros['medio_pago'] === 'sin_cobrar') {
                $sql .= ' AND i.medio_pago IS NULL';
            } else {
                $sql .= ' AND i.medio_pago = :medio_pago';
                $parametros['medio_pago'] = $filtros['medio_pago'];
            }
        }

        if (!empty($filtros['confirmado_por'])) {
            // `sin_firma` son los cobros que nadie firmó: los anteriores a D-39.
            // No es un usuario, así que no puede viajar como id.
            if ($filtros['confirmado_por'] === 'sin_firma') {
                $sql .= ' AND i.fecha_pago IS NOT NULL AND i.confirmado_por IS NULL';
            } else {
                $sql .= ' AND i.confirmado_por = :confirmado_por';
                $parametros['confirmado_por'] = (int) $filtros['confirmado_por'];
            }
        }

        /*
         * El rango de fechas se aplica sobre `fecha_pago` —es una grilla de
         * cobros— y por tanto **excluye lo no cobrado**: una pendiente no tiene
         * fecha que comparar. Que eso ocurra por el rango y no en silencio es
         * justamente lo que la pantalla explica al lado del filtro.
         *
         * `hasta` se compara contra el final del día y no contra la fecha a
         * secas: `fecha_pago` es DATETIME, así que `<= '2026-08-22'` dejaría
         * fuera todo lo cobrado ese día después de medianoche, que es todo.
         */
        /*
         * Las dos fechas llegan del `<input type="date">`, o sea en hora de
         * Ancash, y `fecha_pago` está guardada en la del servidor (D-62). Se
         * compara contra la columna ya convertida, porque si no «el viernes»
         * significaría dos cosas distintas a cada lado del `>=` y los 191
         * cobros del viernes por la noche saldrían filtrados como del sábado.
         *
         * Convertir la columna impide usar su índice; con 809 filas eso no se
         * nota, y la alternativa —desplazar los límites en vez de la columna—
         * es más rápida y bastante más fácil de equivocar al leerla.
         */
        if (!empty($filtros['desde'])) {
            $sql .= ' AND ' . Fecha::sqlLocal('i.fecha_pago') . ' >= :desde';
            $parametros['desde'] = $filtros['desde'] . ' 00:00:00';
        }

        if (!empty($filtros['hasta'])) {
            $sql .= ' AND ' . Fecha::sqlLocal('i.fecha_pago') . ' <= :hasta';
            $parametros['hasta'] = $filtros['hasta'] . ' 23:59:59';
        }

        return [$sql, $parametros];
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
     * Corrige la categoría, sin anular ni reinscribir (D-50).
     *
     * Hasta hoy «Corregir categoría» anulaba la inscripción y creaba otra. Eso
     * funcionaba, pero cobraba un precio en cada corrección: dejaba una fila
     * anulada de adorno en el listado, cambiaba el id de la inscripción, movía
     * el carné a la fila nueva y hacía que «Reinscribir» tuviera que
     * distinguir las anuladas de verdad de las anuladas por corrección.
     *
     * Un grado mal apuntado no es un cambio de historia: es un dato que estaba
     * mal escrito. Se escribe bien y se firma en `correcciones`.
     *
     * La inscripción CONSERVA su id, su estado, su monto y su carné. Cambiar de
     * categoría cambia la bolsa en la que se compite, no lo que se cobró.
     */
    public static function cambiarCategoria(int $id, int $categoriaId): void
    {
        Database::ejecutar(
            'UPDATE inscripciones SET categoria_id = :categoria WHERE id = :id',
            ['categoria' => $categoriaId, 'id' => $id]
        );
    }

    /**
     * ¿Se puede cambiar la procedencia de esta inscripción? (D-50)
     *
     * Pendiente: siempre. No hay dinero de por medio; el monto se recalcula con
     * la tarifa nueva.
     *
     * Confirmada: solo si la tarifa nueva cuesta lo mismo que se cobró. Con las
     * tarifas de hoy eso deja pasar `publica ↔ organizadora` (S/ 10.00) y
     * `privada ↔ libre` (S/ 15.00), y bloquea cualquier cruce entre esos
     * grupos. **Los grupos NO están escritos a mano a propósito**: D-37 avisó de
     * que la tarifa COCIAP puede cambiar, y el día que se mueva esta regla se
     * ajusta sola porque compara números, no nombres.
     *
     * Se compara contra `$montoCobrado` —lo que de verdad entró en caja— y no
     * contra la tarifa vigente de la modalidad vieja. Casi siempre son el mismo
     * número, pero no tienen por qué serlo: si una tarifa se moviera después de
     * cobrar, comparar tarifas dejaría pasar un cambio que descuadra la caja.
     *
     * La tolerancia de medio céntimo es porque los dos lados vienen de
     * DECIMAL(6,2) convertido a float, y comparar floats con `===` es una
     * lotería que se pierde de vez en cuando.
     */
    public static function cambioDeProcedenciaPermitido(
        string $estado,
        float $montoCobrado,
        float $tarifaNueva
    ): bool {
        if ($estado !== 'confirmada') {
            return true;
        }

        return abs($tarifaNueva - $montoCobrado) < 0.005;
    }

    /**
     * Corrige la modalidad y el monto, que solo se mueven juntos (D-50).
     *
     * Los dos en un único UPDATE y en un único método porque son un solo hecho:
     * `tipo_origen` es la modalidad que ELIGIÓ ese monto y se congela con él
     * (D-37). Poder escribir uno sin el otro es justamente lo que permitía que
     * un carné dijera «privada» mientras la caja decía S/ 10.00.
     *
     * Quien llama decide el monto: si la inscripción ya está pagada, pasa el
     * que ya tenía —la regla de D-50 solo deja cambiar de procedencia cuando la
     * tarifa nueva coincide, así que el número no se mueve—; si está pendiente,
     * pasa la tarifa nueva.
     */
    public static function cambiarProcedencia(int $id, string $tipoOrigen, float $monto): void
    {
        $modalidades = ['publica', 'privada', 'libre', 'organizadora'];

        if (!in_array($tipoOrigen, $modalidades, true)) {
            throw new InvalidArgumentException(
                'Modalidad no válida (' . implode(', ', $modalidades) . '); se recibió '
                . var_export($tipoOrigen, true) . '.'
            );
        }

        Database::ejecutar(
            'UPDATE inscripciones SET tipo_origen = :tipo, monto = :monto WHERE id = :id',
            ['tipo' => $tipoOrigen, 'monto' => $monto, 'id' => $id]
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
        ?string $yapeCodigo,
        int $usuarioId
    ): void {
        // `confirmado_por` no es opcional (D-39): cobrar es el acto que mueve
        // dinero, y con varias secretarias a la vez un cobro mal hecho tiene que
        // tener dueño. Se exige en la firma del método para que no se pueda
        // llamar sin decirlo.
        Database::ejecutar(
            "UPDATE inscripciones
                SET estado = 'confirmada',
                    medio_pago = :medio,
                    yape_codigo_seguridad = :yape,
                    fecha_pago = NOW(),
                    confirmado_por = :usuario
              WHERE id = :id AND estado = 'pendiente'",
            [
                'medio'   => $medioPago,
                // Solo se guarda para Yape; en transferencia y efectivo queda NULL.
                'yape'    => $medioPago === 'yape' ? $yapeCodigo : null,
                'usuario' => $usuarioId,
                'id'      => $id,
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
    public static function anular(int $id, string $motivo, bool $esDefinitiva, int $usuarioId): void
    {
        $actual = self::porId($id);

        if ($actual === null || $actual['estado'] === 'anulada') {
            return;
        }

        $requiereDevolucion = $esDefinitiva && $actual['estado'] === 'confirmada';

        // `anulado_por` obligatorio por el mismo motivo que en el cobro (D-39):
        // anular saca a alguien del concurso y, si había pago, manda un monto al
        // fondo de devoluciones.
        Database::ejecutar(
            "UPDATE inscripciones
                SET estado = 'anulada',
                    motivo_anulacion = :motivo,
                    requiere_devolucion = :devolucion,
                    anulado_por = :usuario
              WHERE id = :id",
            [
                'motivo'     => $motivo,
                'devolucion' => $requiereDevolucion ? 1 : 0,
                'usuario'    => $usuarioId,
                'id'         => $id,
            ]
        );
    }

    /**
     * Añade una nota al motivo de anulación, sin borrar lo que ya decía.
     *
     * La anulada es la fila que cuenta qué pasó, así que la reinscripción se
     * anota ahí y no en la nueva. Se concatena en vez de sobrescribir: el motivo
     * original es la razón por la que alguien quedó fuera, y perderlo dejaría el
     * historial diciendo solo la mitad.
     */
    public static function anotarEnAnulacion(int $id, string $nota): void
    {
        Database::ejecutar(
            "UPDATE inscripciones
                SET motivo_anulacion = LEFT(
                        CONCAT(COALESCE(motivo_anulacion, ''), ' · ', :nota), 250
                    )
              WHERE id = :id",
            ['nota' => $nota, 'id' => $id]
        );
    }

    /**
     * Saca una inscripción anulada del fondo de devoluciones.
     *
     * Se usa al reinscribir a quien había pagado: el dinero **no se devolvió**,
     * se está reutilizando en la inscripción nueva. Si el marcador se quedara
     * puesto, el reporte de devoluciones pediría entregarle un dinero que la
     * secretaria acaba de aplicar otra vez — lo pagaría dos veces el concurso.
     */
    public static function limpiarDevolucion(int $id): void
    {
        Database::ejecutar(
            'UPDATE inscripciones SET requiere_devolucion = 0 WHERE id = :id',
            ['id' => $id]
        );
    }

    /**
     * Los inscritos que compiten, para el acta de los jurados (Fase 5).
     *
     * **Solo confirmadas**, por decisión del propietario: al acta entra quien
     * pagó. Quien se inscriba el día del concurso y no haya cobrado todavía no
     * aparece hasta que se regenere el documento.
     *
     * **Ni un solo dato de dinero.** No es un olvido: el acta circula por las
     * mesas de jurado y se fotocopia, y ahí no pinta nada el monto ni el medio
     * de pago. El reporte con dinero es el otro, el de dirección.
     *
     * No agrupa: devuelve filas planas con su categoría y su modalidad, y el
     * reparto en bolsas lo hace `Concurso::bolsa()` sobre esta lista. Es lo que
     * mantiene UNA sola copia de la regla de D-54 en vez de un `CASE` en el SQL
     * que pudiera divergir del dominio.
     *
     * El orden es alfabético con la colación española, así que la Ñ cae entre
     * la N y la O y no al final — con apellidos como Ñopo o Ñiquén no es
     * hipotético.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function paraActa(int $concursoId): array
    {
        $es = Database::ordenEspanol();

        return Database::todos(
            'SELECT i.id, i.tipo_origen,
                    p.codigo_correlativo, p.dni,
                    p.ap_paterno, p.ap_materno, p.nombres,
                    p.tipo_participante,
                    ie.nombre AS institucion,
                    cat.id AS categoria_id, cat.nivel, cat.grado
               FROM inscripciones i
               JOIN participantes p ON p.id = i.participante_id
               JOIN categorias cat ON cat.id = i.categoria_id
          LEFT JOIN instituciones_educativas ie ON ie.id = p.institucion_id
              WHERE p.concurso_id = :con
                AND i.estado = \'confirmada\'
           ORDER BY p.ap_paterno' . $es . ' ASC,
                    p.ap_materno' . $es . ' ASC,
                    p.nombres'    . $es . ' ASC',
            ['con' => $concursoId]
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

    /* ------------------------------------------------------------------
     * Reportes contables (D-59)
     * ------------------------------------------------------------------ */

    /**
     * El SQL canónico del dinero: **una fila por cada pago que existe de
     * verdad**, ni una más.
     *
     * Es el corazón de los tres reportes y por eso está escrito UNA vez. Si el
     * arqueo y el saldo cada uno se lo montara por su cuenta, cuadrarían entre
     * sí solo por casualidad — y un reporte contable que no se cuadra a sí
     * mismo no es contable.
     *
     * **Por qué no basta con `estado = 'confirmada'`:** el dinero de una
     * inscripción cobrada y luego anulada desaparecería del reporte, pero sigue
     * físicamente en el cajón hasta que alguien lo devuelva.
     *
     * **Por qué no basta con `fecha_pago IS NOT NULL`:** cobraría dos veces al
     * mismo estudiante. Al reinscribir (D-38), la fila nueva **copia**
     * `medio_pago`, `fecha_pago` y el código de Yape, y la anulada **conserva
     * los suyos**. Están escritos dos veces a propósito, para que la nueva sepa
     * cómo se cobró.
     *
     * **Lo que sí funciona: elegir una fila por participante.** De todas las
     * filas pagadas de una persona se toma la viva si la tiene, y si no la más
     * reciente. Eso vale también para la cadena larga —pagó, se anuló, se
     * reinscribió, se volvió a anular—, donde contar «anuladas sin hermana
     * viva» sumaría el mismo importe dos veces.
     *
     * El `ORDER BY (i2.estado <> 'anulada') DESC` pone la viva primero, porque
     * en MySQL una comparación vale 1 o 0. Un participante no puede tener dos
     * vivas: lo impide `activaDe()` en todos los caminos de escritura.
     *
     * **Es `public` para que la rendición (D-62) la use tal cual, no por ser
     * API.** Es un fragmento de SQL, no un contrato: quien lo use tiene que
     * poner `i` como alias de `inscripciones`. Se comparte en vez de copiarse
     * porque dos versiones de esta regla son dos contabilidades distintas.
     */
    public const FILA_DE_PAGO_VIGENTE = <<<'SQL'
        (
             SELECT i2.id
               FROM inscripciones i2
              WHERE i2.participante_id = i.participante_id
                AND i2.fecha_pago IS NOT NULL
           ORDER BY (i2.estado <> 'anulada') DESC, i2.id DESC
              LIMIT 1
        )
    SQL;

    /**
     * El `FROM ... WHERE` de los tres reportes de dinero, ya acotado a las
     * filas que cuentan.
     *
     * Se apoya en `FILA_DE_PAGO_VIGENTE`, que es la regla de arriba y vive
     * separada porque **la grilla de cobros también la necesita** —para marcar
     * qué filas ya están contadas en otra— y dos copias de esa consulta serían
     * dos reglas que pueden divergir.
     */
    public const DESDE_COBROS_VIGENTES = '
          FROM inscripciones i
          JOIN participantes p ON p.id = i.participante_id
     LEFT JOIN usuarios u ON u.id = i.confirmado_por
         WHERE p.concurso_id = :con
           AND i.fecha_pago IS NOT NULL
           AND i.id = ' . self::FILA_DE_PAGO_VIGENTE;

    /**
     * Dónde está hoy cada pago. Tres destinos, y ninguno se puede omitir.
     *
     * `sin_reasignar` es el que no existía en ninguna pantalla: una anulación
     * **no definitiva** sobre algo ya pagado deja `requiere_devolucion = 0`
     * (ver `anular()`), así que ese dinero no salía ni en lo recaudado ni en el
     * fondo. Está en el cajón esperando la reinscripción. Es el hueco entre los
     * dos botones de D-15, y aquí se ve.
     */
    private const DESTINO = <<<'SQL'
        CASE
            WHEN i.estado <> 'anulada'     THEN 'en_firme'
            WHEN i.requiere_devolucion = 1 THEN 'por_devolver'
            ELSE 'sin_reasignar'
        END
    SQL;

    /**
     * La grilla de cobros (D-61): **todas** las inscripciones, con el detalle
     * de su pago y ordenadas por cuándo se confirmó.
     *
     * Es la pantalla de auditoría, no la de totales. Devuelve **filas crudas**,
     * una por inscripción, incluidas las pendientes y las anuladas: es lo que
     * permite responder «¿qué pasó con este estudiante?», que es una pregunta
     * distinta de «¿cuánto hay en caja?».
     *
     * Y precisamente por eso **aquí no se suma dinero**. Una reinscripción deja
     * el mismo pago escrito en dos filas (D-59), así que sumar esta lista
     * cobraría dos veces al mismo estudiante. Para no dejarlo a la
     * interpretación de quien mire, cada fila trae `pago_contado`: vale 1 en la
     * fila que los reportes de dinero cuentan y 0 en la copia. La pantalla lo
     * dice con una marca, y los totales se piden en `/reportes/saldos`.
     *
     * **El orden es por fecha de confirmación, de lo más reciente a lo más
     * antiguo**, y lo no cobrado va al final: es lo que hace que al abrir la
     * pantalla se vea el último cobro arriba. Ordenar por una columna que puede
     * ser NULL sin decidir dónde caen los nulos deja ese trozo del listado al
     * criterio del motor.
     *
     * @param array<string, mixed> $filtros
     * @return array<int, array<string, mixed>>
     */
    public static function cobros(int $concursoId, array $filtros = []): array
    {
        [$filtro, $parametros] = self::condiciones($concursoId, $filtros);

        return Database::todos(
            "SELECT i.id, i.estado, i.monto, i.tipo_origen,
                    i.medio_pago, i.yape_codigo_seguridad, i.fecha_pago,
                    i.requiere_devolucion, i.motivo_anulacion, i.created_at,
                    i.confirmado_por,
                    u.nombres AS cobrador,
                    reg.nombres AS registrado_por,
                    p.codigo_correlativo, p.dni, p.tipo_participante,
                    p.ap_paterno, p.ap_materno, p.nombres,
                    ie.nombre AS institucion,
                    cat.nivel, cat.grado,
                    (i.fecha_pago IS NOT NULL AND i.id = " . self::FILA_DE_PAGO_VIGENTE . ") AS pago_contado
               FROM inscripciones i
               JOIN participantes p ON p.id = i.participante_id
               JOIN categorias cat ON cat.id = i.categoria_id
               JOIN usuarios reg ON reg.id = i.usuario_id
          LEFT JOIN usuarios u ON u.id = i.confirmado_por
          LEFT JOIN instituciones_educativas ie ON ie.id = p.institucion_id
              WHERE p.concurso_id = :concurso" . $filtro . '
           ORDER BY (i.fecha_pago IS NULL) ASC,
                    i.fecha_pago DESC,
                    i.id DESC
              LIMIT ' . self::TOPE_LISTADO,
            $parametros
        );
    }

    /**
     * Las cinco líneas del saldo, cuadradas contra la caja física.
     *
     * `devuelto` sale siempre en 0 y **no es un cálculo**: el sistema no
     * registra la devolución efectuada en ninguna parte —`limpiarDevolucion()`
     * borra el marcador sin dejar rastro—, así que la línea existe para que el
     * cuadre no mienta por omisión. El día que se registre, se calcula aquí.
     *
     * @return array<string, mixed>
     */
    public static function saldos(int $concursoId): array
    {
        $filas = Database::todos(
            'SELECT ' . self::DESTINO . ' AS destino,
                    COUNT(*) AS n,
                    COALESCE(SUM(i.monto), 0) AS monto
             ' . self::DESDE_COBROS_VIGENTES . '
          GROUP BY destino',
            ['con' => $concursoId]
        );

        $saldo = [
            'en_firme'      => ['n' => 0, 'monto' => 0.0],
            'por_devolver'  => ['n' => 0, 'monto' => 0.0],
            'sin_reasignar' => ['n' => 0, 'monto' => 0.0],
        ];

        foreach ($filas as $fila) {
            $destino = (string) $fila['destino'];

            if (isset($saldo[$destino])) {
                $saldo[$destino] = [
                    'n'     => (int) $fila['n'],
                    'monto' => (float) $fila['monto'],
                ];
            }
        }

        $pendientes = Database::uno(
            "SELECT COUNT(*) AS n, COALESCE(SUM(i.monto), 0) AS monto
               FROM inscripciones i
               JOIN participantes p ON p.id = i.participante_id
              WHERE p.concurso_id = :con
                AND i.estado = 'pendiente'",
            ['con' => $concursoId]
        );

        $bruto = $saldo['en_firme']['monto']
               + $saldo['por_devolver']['monto']
               + $saldo['sin_reasignar']['monto'];

        return [
            'en_firme'      => $saldo['en_firme'],
            'por_devolver'  => $saldo['por_devolver'],
            'sin_reasignar' => $saldo['sin_reasignar'],
            'bruto'         => $bruto,
            'devuelto'      => 0.0,
            'en_poder'      => $bruto,
            'por_cobrar'    => [
                'n'     => (int) ($pendientes['n'] ?? 0),
                'monto' => (float) ($pendientes['monto'] ?? 0),
            ],
        ];
    }

    /**
     * El arqueo: cuánto recibió cada persona, desglosado por medio de pago.
     *
     * `$usuarioId` acota a un solo cobrador. La secretaria ve solo el suyo
     * (D-59), y de paso eso es su cierre de caja: el papel con el que entrega
     * el dinero.
     *
     * La fila **«(sin firma)»** agrupa los cobros con `confirmado_por` nulo, y
     * no se esconde ni se reparte entre los demás: son los anteriores a D-39
     * —y, hasta D-60, los nacidos de una reinscripción—. Repartirlos sería
     * inventar quién recibió un dinero.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function arqueoPorUsuario(int $concursoId, ?int $usuarioId = null): array
    {
        $parametros = ['con' => $concursoId];
        $filtro     = '';

        if ($usuarioId !== null) {
            // Los cobros sin firma no son de nadie, así que `= :usuario` los
            // deja fuera por sí solo. Es lo correcto: no son suyos.
            $filtro = ' AND i.confirmado_por = :usuario';
            $parametros['usuario'] = $usuarioId;
        }

        return Database::todos(
            "SELECT i.confirmado_por,
                    COALESCE(u.nombres, '(sin firma)') AS cobrador,
                    SUM(i.medio_pago = 'yape')          AS n_yape,
                    SUM(i.medio_pago = 'transferencia') AS n_transferencia,
                    SUM(i.medio_pago = 'efectivo')      AS n_efectivo,
                    COALESCE(SUM(CASE WHEN i.medio_pago = 'yape'          THEN i.monto END), 0) AS monto_yape,
                    COALESCE(SUM(CASE WHEN i.medio_pago = 'transferencia' THEN i.monto END), 0) AS monto_transferencia,
                    COALESCE(SUM(CASE WHEN i.medio_pago = 'efectivo'      THEN i.monto END), 0) AS monto_efectivo,
                    COUNT(*) AS n_total,
                    COALESCE(SUM(i.monto), 0) AS monto_total
             " . self::DESDE_COBROS_VIGENTES . $filtro . '
          GROUP BY i.confirmado_por, u.nombres
          ORDER BY (i.confirmado_por IS NULL) ASC, u.nombres ASC',
            $parametros
        );
    }

    /**
     * Las operaciones de cobro, reconstruidas.
     *
     * **Esto es una reconstrucción, no un hecho, y el reporte lo dice.** No
     * existe la entidad «operación»: la confirmación es masiva (D-14), así que
     * un Yape de S/ 300 por treinta estudiantes escribe el mismo código de tres
     * dígitos en treinta filas. Lo que las une es haber sido confirmadas por la
     * misma persona, con el mismo medio y el mismo código, **en el mismo
     * minuto** — que es lo que ocurre cuando se pulsa «Confirmar» una vez.
     *
     * Es la única forma de conciliar contra la aplicación del banco, y por eso
     * la tabla `pagos` de verdad está anotada como deuda en D-59.
     *
     * El minuto, y no el segundo: el bucle de `PagoController` abre una
     * transacción por inscripción, así que treinta carnés pueden repartirse
     * entre dos segundos consecutivos y partirían la operación en dos.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function operacionesDeCobro(int $concursoId, ?int $usuarioId = null): array
    {
        $parametros = ['con' => $concursoId];
        $filtro     = '';

        if ($usuarioId !== null) {
            $filtro = ' AND i.confirmado_por = :usuario';
            $parametros['usuario'] = $usuarioId;
        }

        $es = Database::ordenEspanol();

        /*
         * **Una sola consulta, agrupada en PHP, y no dos consultas.** (D-64)
         *
         * La alternativa evidente —un `GROUP BY` para las cabeceras y otro
         * `SELECT` para el detalle— repetiría la clave de agrupación en dos
         * sitios, y el día que una de las dos cambie el total de la cabecera
         * dejaría de ser la suma de lo que hay debajo. En un papel que se firma
         * al entregar dinero, esa discrepancia es exactamente lo que no puede
         * pasar: aquí **el total se calcula a partir de las filas que se
         * listan**, así que no puede contradecirlas.
         *
         * El orden dentro de cada operación es alfabético con la colación
         * española, que es como se coteja contra la nómina de la delegación.
         */
        $filas = Database::todos(
            "SELECT i.id, i.monto, i.medio_pago, i.yape_codigo_seguridad, i.fecha_pago,
                    i.confirmado_por,
                    COALESCE(u.nombres, '(sin firma)') AS cobrador,
                    DATE_FORMAT(i.fecha_pago, '%Y-%m-%d %H:%i') AS minuto,
                    p.codigo_correlativo, p.dni,
                    p.ap_paterno, p.ap_materno, p.nombres,
                    p.tipo_participante,
                    (SELECT ie.nombre
                       FROM instituciones_educativas ie
                      WHERE ie.id = p.institucion_id) AS institucion,
                    (SELECT CONCAT(cat.nivel, ' ', cat.grado)
                       FROM categorias cat
                      WHERE cat.id = i.categoria_id) AS categoria
             " . self::DESDE_COBROS_VIGENTES . $filtro . '
          ORDER BY minuto DESC,
                   cobrador' . $es . ' ASC,
                   i.medio_pago ASC,
                   p.ap_paterno' . $es . ' ASC,
                   p.ap_materno' . $es . ' ASC,
                   p.nombres'    . $es . ' ASC',
            $parametros
        );

        $operaciones = [];

        foreach ($filas as $fila) {
            // La clave es la misma de siempre: quién cobró, con qué medio, con
            // qué código y en qué minuto. Ver la nota de arriba sobre por qué el
            // minuto y no el segundo.
            $clave = implode('|', [
                (string) $fila['confirmado_por'],
                (string) $fila['medio_pago'],
                (string) $fila['yape_codigo_seguridad'],
                (string) $fila['minuto'],
            ]);

            if (!isset($operaciones[$clave])) {
                $operaciones[$clave] = [
                    'momento'               => $fila['fecha_pago'],
                    'medio_pago'            => $fila['medio_pago'],
                    'yape_codigo_seguridad' => $fila['yape_codigo_seguridad'],
                    'confirmado_por'        => $fila['confirmado_por'],
                    'cobrador'              => $fila['cobrador'],
                    'cantidad'              => 0,
                    'monto'                 => 0.0,
                    'procedencias'          => [],
                    'participantes'         => [],
                ];
            }

            $procedencia = $fila['tipo_participante'] === 'libre'
                ? 'Libre'
                : (string) ($fila['institucion'] ?? '—');

            $operaciones[$clave]['cantidad']++;
            $operaciones[$clave]['monto'] += (float) $fila['monto'];
            $operaciones[$clave]['procedencias'][$procedencia] = true;
            $operaciones[$clave]['participantes'][] = [
                'id'                 => (int) $fila['id'],
                'codigo_correlativo' => $fila['codigo_correlativo'],
                'dni'                => $fila['dni'],
                'ap_paterno'         => $fila['ap_paterno'],
                'ap_materno'         => $fila['ap_materno'],
                'nombres'            => $fila['nombres'],
                'procedencia'        => $procedencia,
                'categoria'          => $fila['categoria'],
                'monto'              => (float) $fila['monto'],
            ];

            // La más antigua del grupo es el momento de la operación, igual que
            // hacía el `MIN(fecha_pago)` de la versión anterior.
            if ($fila['fecha_pago'] < $operaciones[$clave]['momento']) {
                $operaciones[$clave]['momento'] = $fila['fecha_pago'];
            }
        }

        /*
         * Cuántas procedencias distintas toca la operación. Con más de una, es
         * señal de que la reconstrucción pudo haber fundido dos cobros reales
         * del mismo minuto: dos delegaciones que pagaron en efectivo seguidas.
         * La pantalla lo avisa en vez de presentarlo como un hecho.
         */
        foreach ($operaciones as &$operacion) {
            $operacion['procedencias'] = array_keys($operacion['procedencias']);
        }

        unset($operacion);

        return array_values($operaciones);
    }

    /**
     * Cobrado pendiente de reasignar: el dinero que no está en ningún sitio.
     *
     * Anuladas **para reinscribir** que todavía no se reinscribieron, con su
     * pago dentro. No van al fondo de devoluciones a propósito: esa plata no se
     * devuelve, se está reutilizando, y pedir que se entregue haría que el
     * concurso la pagara dos veces (es justo lo que evita `limpiarDevolucion()`).
     *
     * Lo que hace falta es **verlas**, porque mientras tanto están en el cajón
     * sin figurar en ninguna cuenta.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function cobradoSinReasignar(int $concursoId): array
    {
        $es = Database::ordenEspanol();

        return Database::todos(
            "SELECT i.id, i.monto, i.medio_pago, i.fecha_pago, i.motivo_anulacion,
                    COALESCE(u.nombres, '(sin firma)') AS cobrador,
                    p.codigo_correlativo, p.dni, p.ap_paterno, p.ap_materno, p.nombres,
                    p.tipo_participante,
                    /* Subconsulta y no JOIN: el FROM viene del fragmento
                       canónico, que es común a los tres reportes y no se
                       ensancha para una columna de una sola pantalla. */
                    (SELECT ie.nombre
                       FROM instituciones_educativas ie
                      WHERE ie.id = p.institucion_id) AS institucion
             " . self::DESDE_COBROS_VIGENTES . "
               AND i.estado = 'anulada'
               AND i.requiere_devolucion = 0
          ORDER BY p.ap_paterno" . $es . ' ASC,
                   p.ap_materno' . $es . ' ASC,
                   p.nombres'    . $es . ' ASC',
            ['con' => $concursoId]
        );
    }
}
