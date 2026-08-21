<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Apoderado;
use App\Models\Concurso;
use App\Models\Correccion;
use App\Models\Inscripcion;
use App\Models\InstitucionEducativa;
use App\Models\Participante;
use Core\Auth;
use Core\Controller;
use Core\Database;
use Core\Sesion;
use Core\Validador;
use Throwable;

/**
 * Corrección del registro de participación (D-50).
 *
 * Vive aquí y no en `AnulacionController` porque **la acción ya no anula
 * nada**. Antes «Corregir categoría» anulaba la inscripción y creaba otra, así
 * que era razonable que compartiera casa con la anulación; ahora es un UPDATE
 * firmado y esa vecindad solo confundiría a quien busque el código.
 *
 * La ruta `/inscripciones/{id}/corregir` NO cambia: ningún enlace se rompe.
 *
 * Qué se puede corregir, y quién:
 *
 *   · Datos del estudiante (documento, apellidos, nombres) ...... ambos roles
 *   · Categoría (el grado en el que compite) .................... ambos roles
 *   · Procedencia (delegación ↔ libre, y de qué colegio) ........ solo administrador
 *   · Motivo, obligatorio ....................................... siempre
 *
 * Lo que queda FUERA, y por qué:
 *
 *   · `codigo_correlativo` — va impreso en carnés ya entregados y es lo que la
 *     mesa de la puerta teclea. Cambiarlo invalida papel que ya está en la
 *     mochila de un niño.
 *   · El flujo de caja —estado, medio de pago, fecha, código de Yape—. Eso se
 *     cobra o se anula, no se corrige.
 *   · `tipo_origen` y `monto` a mano: se derivan de la procedencia. Poder
 *     escribirlos sueltos es lo que permitía que el carné dijera «privada»
 *     mientras la caja decía S/ 10.00 (D-37).
 */
final class CorreccionController extends Controller
{
    /**
     * Campos del bloque de procedencia.
     *
     * Se listan en un solo sitio porque sirven para dos cosas a la vez: saber
     * si el POST trae procedencia, y rechazarlo si quien lo envía no es
     * administrador.
     */
    public const CAMPOS_PROCEDENCIA = [
        'tipo_participante', 'institucion_id',
        'ap_dni', 'ap_ap_paterno', 'ap_ap_materno', 'ap_nombres', 'ap_celular', 'ap_correo',
    ];

    /**
     * ¿Este envío trae el bloque de procedencia?
     *
     * Público y estático para que se pueda comprobar sin montar una petición
     * entera: el camino que usa —una secretaria enviando estos campos— termina
     * en un redirect, y un redirect no se puede observar desde una prueba de
     * consola. La regla vive aquí para poder mirarla de frente.
     *
     * @param array<string, mixed> $entrada
     */
    public static function traeProcedencia(array $entrada): bool
    {
        foreach (self::CAMPOS_PROCEDENCIA as $campo) {
            if (array_key_exists($campo, $entrada) && trim((string) $entrada[$campo]) !== '') {
                return true;
            }
        }

        return false;
    }

    public function formulario(string $id): void
    {
        Auth::exigirSesion();

        $inscripcion = $this->inscripcionCorregibleOFallar((int) $id);

        $this->pintar($inscripcion, [
            'dni'               => (string) $inscripcion['dni'],
            'ap_paterno'        => (string) $inscripcion['ap_paterno'],
            'ap_materno'        => (string) $inscripcion['ap_materno'],
            'nombres'           => (string) $inscripcion['nombres'],
            'categoria_id'      => (string) $inscripcion['categoria_id'],
            'tipo_participante' => (string) $inscripcion['tipo_participante'],
            'institucion_id'    => (string) ($inscripcion['institucion_id'] ?? ''),
        ], []);
    }

    /**
     * Aplica la corrección y la firma.
     *
     * Todo ocurre dentro de una transacción: o se guardan los cuatro bloques y
     * su registro en `correcciones`, o no se guarda nada. Una corrección
     * aplicada sin firma sería exactamente el agujero que D-50 vino a cerrar.
     */
    public function guardar(string $id): void
    {
        Auth::exigirSesion();
        $this->exigirCsrf();

        $inscripcionId = (int) $id;
        $inscripcion   = $this->inscripcionCorregibleOFallar($inscripcionId);
        $concurso      = Concurso::vigente();
        $concursoId    = (int) $concurso['id'];
        $esAdmin       = Auth::esAdministrador();

        /*
         * Defensa en profundidad. La vista no dibuja el bloque de procedencia
         * para una secretaria, así que llegar aquí con esos campos significa
         * que alguien los añadió por su cuenta.
         *
         * Se RECHAZA en vez de ignorarlos en silencio. Ignorar es peor: la
         * pantalla diría «corregido» y ella se quedaría creyendo que el colegio
         * cambió, cuando no cambió nada. Un fallo silencioso en un dato que
         * decide la tarifa y la bolsa de competencia se descubre el día de la
         * premiación.
         */
        $traeProcedencia = self::traeProcedencia($_POST);

        if ($traeProcedencia && !$esAdmin) {
            Sesion::flash(
                'error',
                'Cambiar la procedencia de una inscripción es exclusivo del administrador. '
                . 'No se corrigió nada: pídeselo a él.'
            );
            $this->redirigir('/inscripciones#ins-' . $inscripcionId);
        }

        $v = new Validador($_POST);

        $v->requerido('dni', 'El documento del estudiante')->dni('dni', 'El documento del estudiante');
        $v->requerido('ap_paterno', 'El apellido paterno')->maximo('ap_paterno', 100, 'El apellido paterno');
        $v->requerido('ap_materno', 'El apellido materno')->maximo('ap_materno', 100, 'El apellido materno');
        $v->requerido('nombres', 'Los nombres')->maximo('nombres', 150, 'Los nombres');
        $v->requerido('categoria_id', 'La categoría');

        /*
         * El motivo es OBLIGATORIO, al revés que en el formulario viejo, donde
         * era opcional y se rellenaba solo con «Corrección de categoría». Un
         * motivo automático no explica nada: dentro de un mes, «¿por qué este
         * chico cambió de colegio?» tiene que poder responderse leyendo la fila.
         */
        $v->requerido('motivo', 'El motivo de la corrección')->maximo('motivo', 250, 'El motivo');

        $categoriaId = (int) $v->limpio('categoria_id');

        if ($categoriaId > 0 && !Concurso::categoriaPertenece($categoriaId, $concursoId)) {
            $v->fallar('categoria_id', 'La categoría no pertenece a este concurso.');
        }

        /*
         * Un documento, un participante por concurso (D-31). Solo se comprueba
         * si el documento cambia: comparar contra sí mismo se rechazaría a sí
         * mismo. El nombre del otro registro y su código van en el mensaje
         * porque sin ellos la secretaria no sabe a quién tiene delante — y el
         * caso real es que sea la MISMA persona registrada dos veces.
         */
        $documento = $v->limpio('dni');

        if ($documento !== '' && $documento !== (string) $inscripcion['dni'] && !isset($v->errores()['dni'])) {
            $otro = Participante::porDocumento($concursoId, $documento);

            if ($otro !== null && (int) $otro['id'] !== (int) $inscripcion['participante_id']) {
                $v->fallar('dni', "El documento {$documento} ya está registrado en este concurso como "
                    . $otro['ap_paterno'] . ' ' . $otro['ap_materno'] . ', ' . $otro['nombres']
                    . ' (' . $otro['codigo_correlativo'] . '). Si son la misma persona, hay que decidir '
                    . 'cuál de los dos registros se queda.');
            }
        }

        // --- Procedencia -----------------------------------------------------
        $tipoActual       = (string) $inscripcion['tipo_participante'];
        $institucionAntes = $inscripcion['institucion_id'] !== null ? (int) $inscripcion['institucion_id'] : null;

        $tipoNuevo        = $tipoActual;
        $institucionNueva = $institucionAntes;
        $apoderadoNuevo   = $inscripcion['apoderado_id'] !== null ? (int) $inscripcion['apoderado_id'] : null;
        $datosApoderado   = null;

        if ($esAdmin && $traeProcedencia) {
            $v->enLista('tipo_participante', ['delegacion', 'libre'], 'El tipo de participante');
            $tipoNuevo = $v->limpio('tipo_participante') ?: $tipoActual;

            if ($tipoNuevo === 'delegacion') {
                $v->requerido('institucion_id', 'La institución educativa');
                $institucionNueva = (int) $v->limpio('institucion_id');

                $ie = $institucionNueva > 0 ? InstitucionEducativa::porId($institucionNueva) : null;

                if ($ie === null) {
                    $v->fallar('institucion_id', 'Esa institución educativa no existe.');
                } else {
                    /*
                     * El encargado de la delegación ES el apoderado de sus
                     * participantes (D-28). Al volver a una delegación, el
                     * apoderado deja de ser el particular y pasa a ser el
                     * docente delegado del colegio: si no, el carné y los
                     * avisos seguirían apuntando a la persona equivocada.
                     */
                    $apoderadoNuevo = (int) $ie['docente_delegado_id'];
                }
            } else {
                /*
                 * Pasar a libre exige un apoderado propio: el estudiante deja
                 * de colgar de un colegio, y `apoderado_id` es lo único que
                 * queda para saber a quién llamar.
                 *
                 * Se reutiliza el mismo buscador por documento de la pantalla
                 * de inscripción libre, así que un apoderado ya registrado se
                 * reconoce en vez de duplicarse — y `apoderados.dni` es UNIQUE
                 * global, de modo que crearlo en limpio reventaría con un error
                 * de base en la cara de quien corrige.
                 */
                $institucionNueva = null;

                $v->requerido('ap_dni', 'El documento del apoderado')->dni('ap_dni', 'El documento del apoderado');
                $v->requerido('ap_ap_paterno', 'El apellido paterno del apoderado');
                $v->requerido('ap_ap_materno', 'El apellido materno del apoderado');
                $v->requerido('ap_nombres', 'Los nombres del apoderado');
                $v->requerido('ap_celular', 'El celular del apoderado')->celular('ap_celular', 'El celular del apoderado');
                $v->correo('ap_correo', 'El correo del apoderado');

                $datosApoderado = [
                    'dni'        => $v->limpio('ap_dni'),
                    'ap_paterno' => $v->limpio('ap_ap_paterno'),
                    'ap_materno' => $v->limpio('ap_ap_materno'),
                    'nombres'    => $v->limpio('ap_nombres'),
                    'celular'    => $v->limpio('ap_celular'),
                ];

                // El correo solo viaja si trae valor: la clave ausente le dice a
                // Apoderado::actualizar() que no toque esa columna, y así no se
                // le borra el correo a quien además es docente delegado.
                if ($v->limpioONulo('ap_correo') !== null) {
                    $datosApoderado['correo'] = $v->limpio('ap_correo');
                }
            }
        }

        // --- La regla de la tarifa ------------------------------------------
        $modalidadAntes = (string) $inscripcion['tipo_origen'];
        $montoAntes     = (float) $inscripcion['monto'];
        $modalidadNueva = $modalidadAntes;
        $montoNuevo     = $montoAntes;

        $cambiaProcedencia = $tipoNuevo !== $tipoActual || $institucionNueva !== $institucionAntes;

        if ($cambiaProcedencia && !$v->tieneErrores()) {
            $ieNueva = $institucionNueva !== null ? InstitucionEducativa::porId($institucionNueva) : null;

            $modalidadNueva = Concurso::modalidad($concurso, $ieNueva);
            $tarifaNueva    = Concurso::tarifa($concursoId, $modalidadNueva);

            // La regla vive en el modelo (`Inscripcion::cambioDeProcedenciaPermitido`)
            // porque es de negocio, no de pantalla: allí se puede comprobar de
            // frente, sin montar una petición entera.
            $permitido = Inscripcion::cambioDeProcedenciaPermitido(
                (string) $inscripcion['estado'],
                $montoAntes,
                $tarifaNueva
            );

            if (!$permitido) {
                $campo = $tipoNuevo === 'delegacion' ? 'institucion_id' : 'tipo_participante';

                $v->fallar($campo, sprintf(
                    'Esta inscripción ya está pagada con S/ %s (%s) y la procedencia nueva '
                    . 'cuesta S/ %s (%s). Cambiarla movería el dinero cobrado, así que hay que '
                    . 'anular la inscripción y volver a registrarla.',
                    number_format($montoAntes, 2),
                    Concurso::etiquetaModalidad($modalidadAntes),
                    number_format($tarifaNueva, 2),
                    Concurso::etiquetaModalidad($modalidadNueva)
                ));
            } elseif ($inscripcion['estado'] !== 'confirmada') {
                // Pendiente: no hay dinero de por medio, se recalcula.
                $montoNuevo = $tarifaNueva;
            }
            // Pagada y con la misma tarifa: la modalidad se corrige y el monto
            // se queda EXACTAMENTE en el número cobrado. No se reescribe con la
            // tarifa, que podría diferir en céntimos.
        }

        // --- Qué cambió de verdad -------------------------------------------
        $participanteId = (int) $inscripcion['participante_id'];
        $categorias     = Concurso::categorias($concursoId);

        $cambiosParticipante = [];
        $auditoria           = [];

        foreach (['dni', 'ap_paterno', 'ap_materno', 'nombres'] as $columna) {
            $nuevo = $v->limpio($columna);

            if ($nuevo !== (string) $inscripcion[$columna]) {
                $cambiosParticipante[$columna]              = $nuevo;
                $auditoria['participante.' . $columna] = [(string) $inscripcion[$columna], $nuevo];
            }
        }

        $categoriaCambia = $categoriaId > 0 && $categoriaId !== (int) $inscripcion['categoria_id'];

        if ($categoriaCambia) {
            $auditoria['inscripcion.categoria_id'] = [
                ucfirst((string) $inscripcion['nivel']) . ' ' . (int) $inscripcion['grado'] . '°',
                $this->etiquetaCategoria($categorias, $categoriaId),
            ];
        }

        if ($cambiaProcedencia) {
            if ($tipoNuevo !== $tipoActual) {
                $cambiosParticipante['tipo_participante'] = $tipoNuevo;
                $auditoria['participante.tipo_participante'] = [
                    $tipoActual === 'libre' ? 'Estudiante libre' : 'Delegación',
                    $tipoNuevo === 'libre' ? 'Estudiante libre' : 'Delegación',
                ];
            }

            if ($institucionNueva !== $institucionAntes) {
                $cambiosParticipante['institucion_id'] = $institucionNueva;
                $auditoria['participante.institucion_id'] = [
                    $inscripcion['institucion'] !== null ? (string) $inscripcion['institucion'] : 'Sin institución',
                    $institucionNueva !== null
                        ? (string) (InstitucionEducativa::porId($institucionNueva)['nombre'] ?? '')
                        : 'Sin institución',
                ];
            }

            if ($modalidadNueva !== $modalidadAntes) {
                $auditoria['inscripcion.tipo_origen'] = [
                    Concurso::etiquetaModalidad($modalidadAntes),
                    Concurso::etiquetaModalidad($modalidadNueva),
                ];
            }

            if (abs($montoNuevo - $montoAntes) >= 0.005) {
                $auditoria['inscripcion.monto'] = [
                    'S/ ' . number_format($montoAntes, 2),
                    'S/ ' . number_format($montoNuevo, 2),
                ];
            }
        }

        if ($auditoria === [] && !$v->tieneErrores()) {
            $v->fallar('motivo', 'No cambiaste ningún dato: no hay nada que corregir.');
        }

        if ($v->tieneErrores()) {
            $this->pintar($inscripcion, $_POST, $v->errores());

            return;
        }

        $motivo  = $v->limpio('motivo');
        $usuario = (int) Auth::id();

        try {
            $apoderadoAntes = $inscripcion['apoderado_id'] !== null ? (int) $inscripcion['apoderado_id'] : null;

            Database::transaccion(
                static function () use (
                    $participanteId, $inscripcionId, $cambiosParticipante, $auditoria,
                    $categoriaCambia, $categoriaId, $cambiaProcedencia, $modalidadNueva,
                    $montoNuevo, $datosApoderado, $apoderadoNuevo, $apoderadoAntes,
                    $motivo, $usuario
                ): void {
                    /*
                     * El apoderado se resuelve primero porque su id entra en el
                     * UPDATE del participante. Se reutiliza el existente si el
                     * documento ya está registrado —caso hermanos, y caso del
                     * apoderado que además es docente delegado— y se le
                     * actualizan los datos de contacto, que es lo que cambia.
                     */
                    if ($datosApoderado !== null) {
                        $existente = Apoderado::porDni($datosApoderado['dni']);

                        if ($existente !== null) {
                            $apoderadoNuevo = (int) $existente['id'];
                            Apoderado::actualizar($apoderadoNuevo, $datosApoderado);
                        } else {
                            $apoderadoNuevo = Apoderado::crear($datosApoderado);
                        }
                    }

                    /*
                     * El apoderado cambia ARRASTRADO por la procedencia, no
                     * porque nadie lo haya elegido: al volver a una delegación
                     * pasa a ser su docente delegado (D-28), y al salir a libre
                     * pasa a ser el particular que se acaba de capturar.
                     *
                     * Se audita igual que lo demás. Es el dato de a quién se
                     * llama si al chico le pasa algo el día del concurso, y un
                     * cambio silencioso ahí es de los que no se descubren hasta
                     * que hace falta llamar.
                     */
                    if ($cambiaProcedencia && $apoderadoNuevo !== null) {
                        $cambiosParticipante['apoderado_id'] = $apoderadoNuevo;

                        if ($apoderadoNuevo !== $apoderadoAntes) {
                            $anterior = $apoderadoAntes !== null ? Apoderado::porId($apoderadoAntes) : null;
                            $actual   = Apoderado::porId($apoderadoNuevo);

                            $legible = static fn (?array $a): string => $a === null
                                ? 'Sin apoderado'
                                : $a['ap_paterno'] . ' ' . $a['ap_materno'] . ', ' . $a['nombres'];

                            $auditoria['participante.apoderado_id'] = [$legible($anterior), $legible($actual)];
                        }
                    }

                    if ($cambiosParticipante !== []) {
                        Participante::actualizar($participanteId, $cambiosParticipante);
                    }

                    if ($categoriaCambia) {
                        Inscripcion::cambiarCategoria($inscripcionId, $categoriaId);
                    }

                    if ($cambiaProcedencia) {
                        // Los dos juntos, siempre: la modalidad es la que eligió
                        // el monto y se congela con él (D-37).
                        Inscripcion::cambiarProcedencia($inscripcionId, $modalidadNueva, $montoNuevo);
                    }

                    Correccion::registrar($participanteId, $inscripcionId, $auditoria, $motivo, $usuario);
                }
            );
        } catch (Throwable $e) {
            error_log((string) $e);
            Sesion::flash('error', 'No se pudo corregir. No se cambió nada.');
            $this->redirigir('/inscripciones#ins-' . $inscripcionId);
        }

        $mensaje = 'Corrección guardada: ' . $this->resumirCambios($auditoria) . '.';

        /*
         * El carné digital y la vista pública se corrigen solos —el PDF se
         * genera al vuelo y no se guarda (D-24)—, y el QR sigue siendo válido
         * porque el código correlativo no se toca. Lo único que queda
         * desactualizado es el PAPEL que ya se imprimió, y eso no lo puede
         * arreglar el sistema: hay que decirlo.
         */
        if ($inscripcion['estado'] === 'confirmada' && $this->afectaAlCarne($auditoria)) {
            $mensaje .= ' El carné ya impreso quedó desactualizado: vuelve a descargarlo e imprimirlo. '
                . 'El código ' . $inscripcion['codigo_correlativo'] . ' y su QR siguen siendo válidos.';
        }

        Sesion::flash('exito', $mensaje);

        // La inscripción CONSERVA su id, así que se vuelve a su propia fila. El
        // `$nuevaId` que D-48 tuvo que introducir aquí ya no hace falta: no hay
        // fila nueva a la que apuntar.
        $this->redirigir('/inscripciones#ins-' . $inscripcionId);
    }

    /**
     * Datos comunes de la pantalla, para no repetirlos en las tres salidas.
     *
     * @param array<string, mixed> $inscripcion
     * @param array<string, mixed> $valores
     * @param array<string, string> $errores
     */
    private function pintar(array $inscripcion, array $valores, array $errores): void
    {
        $concurso   = Concurso::vigente();
        $concursoId = (int) $concurso['id'];

        $this->ver('inscripciones.corregir', [
            'titulo'       => 'Corregir inscripción',
            'inscripcion'  => $inscripcion,
            'categorias'   => Concurso::categorias($concursoId),
            'instituciones' => InstitucionEducativa::listar('', null, 1000),
            'historial'    => Correccion::porParticipante((int) $inscripcion['participante_id']),
            'esAdmin'      => Auth::esAdministrador(),
            'valores'      => $valores,
            'errores'      => $errores,
        ]);
    }

    /**
     * La inscripción tiene que existir, ser de este concurso y NO estar anulada.
     *
     * Sobre una anulada no se corrige (decisión del propietario, 20-ago): ahí
     * no hay nada que cobrar ni bolsa que asignar, y el caso del dato mal
     * escrito tiene salida en dos pasos —Reinscribir y luego Corregir sobre la
     * fila viva—, porque la reinscripción trabaja sobre el MISMO participante.
     *
     * @return array<string, mixed>
     */
    private function inscripcionCorregibleOFallar(int $id): array
    {
        $inscripcion = Inscripcion::porId($id);

        if ($inscripcion === null) {
            Sesion::flash('error', 'Esa inscripción no existe.');
            $this->redirigir('/inscripciones');
        }

        if ($inscripcion['estado'] === 'anulada') {
            Sesion::flash(
                'error',
                'No se corrige una inscripción anulada. Si el estudiante tiene que volver al '
                . 'concurso, usa «Reinscribir» y corrige después sobre la inscripción viva.'
            );
            $this->redirigir('/inscripciones#ins-' . $id);
        }

        /*
         * Cada quien corrige lo suyo (D-52).
         *
         * La vista ya no dibuja «Corregir» en las filas ajenas, así que llegar
         * hasta aquí significa una URL tecleada a mano o un enlace guardado de
         * antes. Se rechaza en voz alta y no en silencio: la corrección es un
         * UPDATE sobre datos que ya están impresos en un carné, y creer que se
         * aplicó cuando no se aplicó es peor que el propio bloqueo.
         *
         * Sin `http_response_code(403)`: un `Location:` posterior degrada la
         * respuesta a 302 y el 403 nunca sale por el cable. El porqué completo
         * está en AnulacionController::anular().
         */
        if (!Auth::puedeOperar((int) $inscripcion['usuario_id'])) {
            Sesion::flash(
                'error',
                'Esa inscripción la registró ' . $inscripcion['registrado_por']
                . ', y cada quien corrige solo lo suyo. No se cambió nada: '
                . 'pídeselo a esa persona o al administrador.'
            );
            $this->redirigir('/inscripciones#ins-' . $id);
        }

        return $inscripcion;
    }

    /**
     * @param array<int, array<string, mixed>> $categorias
     */
    private function etiquetaCategoria(array $categorias, int $categoriaId): string
    {
        foreach ($categorias as $categoria) {
            if ((int) $categoria['id'] === $categoriaId) {
                return (string) $categoria['etiqueta'];
            }
        }

        return (string) $categoriaId;
    }

    /**
     * @param array<string, array{0: string|null, 1: string|null}> $auditoria
     */
    private function resumirCambios(array $auditoria): string
    {
        $rotulos = array_map(
            static fn (string $campo): string => mb_strtolower(Correccion::etiqueta($campo)),
            array_keys($auditoria)
        );

        if (count($rotulos) === 1) {
            return 'se corrigió ' . $rotulos[0];
        }

        $ultimo = array_pop($rotulos);

        return 'se corrigieron ' . implode(', ', $rotulos) . ' y ' . $ultimo;
    }

    /**
     * ¿Alguno de los campos corregidos va impreso en el carné?
     *
     * @param array<string, array{0: string|null, 1: string|null}> $auditoria
     */
    private function afectaAlCarne(array $auditoria): bool
    {
        $impresos = [
            'participante.dni', 'participante.ap_paterno', 'participante.ap_materno',
            'participante.nombres', 'participante.institucion_id',
            'inscripcion.categoria_id', 'inscripcion.tipo_origen',
        ];

        return array_intersect(array_keys($auditoria), $impresos) !== [];
    }
}
