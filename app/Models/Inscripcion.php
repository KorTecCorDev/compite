<?php

declare(strict_types=1);

namespace App\Models;

use Core\Database;
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
         */
        return Database::insertar(
            'INSERT INTO inscripciones (
                participante_id, categoria_id, usuario_id, estado, tipo_origen, monto,
                medio_pago, yape_codigo_seguridad, fecha_pago
             ) VALUES (
                :participante, :categoria, :usuario, :estado, :tipo_origen, :monto,
                :medio_pago, :yape, :fecha_pago
             )',
            [
                'participante' => $datos['participante_id'],
                'categoria'    => $datos['categoria_id'],
                'usuario'      => $datos['usuario_id'],
                'estado'       => $datos['estado'] ?? 'pendiente',
                'tipo_origen'  => $datos['tipo_origen'],
                'monto'        => $datos['monto'],
                'medio_pago'   => $datos['medio_pago'] ?? null,
                'yape'         => $datos['yape_codigo_seguridad'] ?? null,
                'fecha_pago'   => $datos['fecha_pago'] ?? null,
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
}
