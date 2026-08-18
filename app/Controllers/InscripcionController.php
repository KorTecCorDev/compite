<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Apoderado;
use App\Models\Concurso;
use App\Models\Inscripcion;
use App\Models\InstitucionEducativa;
use App\Models\Participante;
use Core\Auth;
use Core\Controller;
use Core\Database;
use Core\Sesion;
use Core\Validador;
use Throwable;

final class InscripcionController extends Controller
{
    /**
     * Listado con los filtros combinables del plan: delegación, tipo de
     * origen y grado.
     */
    public function index(): void
    {
        Auth::exigirSesion();

        $concurso = $this->concursoOFallar();

        $filtros = [
            'institucion_id' => $_GET['institucion_id'] ?? '',
            'tipo_origen'    => $_GET['tipo_origen'] ?? '',
            'nivel'          => $_GET['nivel'] ?? '',
            'grado'          => $_GET['grado'] ?? '',
            'estado'         => $_GET['estado'] ?? '',
            'q'              => $_GET['q'] ?? '',
        ];

        $this->ver('inscripciones.index', [
            'titulo'        => 'Inscripciones',
            'concurso'      => $concurso,
            'inscripciones' => Inscripcion::listar((int) $concurso['id'], $filtros),
            'instituciones' => InstitucionEducativa::listar('', null, 500),
            'filtros'       => $filtros,
            'resumen'       => Inscripcion::resumen((int) $concurso['id']),
        ]);
    }

    // ==================================================================
    // Flujo 1 — Inscripción institucional por lote
    // ==================================================================

    public function formularioDelegacion(): void
    {
        Auth::exigirSesion();

        $concurso = $this->concursoOFallar();
        $ieId     = isset($_GET['institucion_id']) ? (int) $_GET['institucion_id'] : null;

        $institucion = $ieId !== null ? InstitucionEducativa::porId($ieId) : null;

        $this->ver('inscripciones.delegacion', [
            'titulo'        => 'Inscripción por delegación',
            'concurso'      => $concurso,
            'instituciones' => InstitucionEducativa::listar('', null, 500),
            'institucion'   => $institucion,
            'categorias'    => Concurso::categorias((int) $concurso['id']),
            'tarifas'       => Concurso::tarifas((int) $concurso['id']),
            'filas'         => [],
            'errores'       => [],
        ]);
    }

    public function guardarDelegacion(): void
    {
        Auth::exigirSesion();
        $this->exigirCsrf();

        $concurso   = $this->concursoOFallar();
        $concursoId = (int) $concurso['id'];

        $ieId        = (int) ($_POST['institucion_id'] ?? 0);
        $institucion = $ieId > 0 ? InstitucionEducativa::porId($ieId) : null;

        /** @var array<int, array<string, mixed>> $filasEnviadas */
        $filasEnviadas = is_array($_POST['p'] ?? null) ? $_POST['p'] : [];

        $errores = [];

        if ($institucion === null) {
            $errores['institucion_id'] = 'Selecciona la institución educativa de la delegación.';
        }

        // Se descartan las filas totalmente vacías: el formulario ofrece
        // varias de entrada y la secretaria no tiene por qué llenarlas todas.
        $filas = [];
        foreach ($filasEnviadas as $indice => $fila) {
            if (!is_array($fila) || $this->filaVacia($fila)) {
                continue;
            }
            $filas[$indice] = $fila;
        }

        if ($filas === []) {
            $errores['filas'] = 'Registra al menos un participante.';
        }

        $validadas = [];

        foreach ($filas as $indice => $fila) {
            $v = new Validador($fila);
            $n = $indice + 1;

            $v->requerido('dni', "Fila {$n}: el documento")->dni('dni', "Fila {$n}: el documento");
            $v->requerido('ap_paterno', "Fila {$n}: el apellido paterno")->maximo('ap_paterno', 100, "Fila {$n}: el apellido paterno");
            $v->requerido('ap_materno', "Fila {$n}: el apellido materno")->maximo('ap_materno', 100, "Fila {$n}: el apellido materno");
            $v->requerido('nombres', "Fila {$n}: los nombres")->maximo('nombres', 150, "Fila {$n}: los nombres");
            $v->requerido('categoria_id', "Fila {$n}: la categoría");

            $categoriaId = (int) $v->limpio('categoria_id');

            if ($categoriaId > 0 && !Concurso::categoriaPertenece($categoriaId, $concursoId)) {
                $v->fallar('categoria_id', "Fila {$n}: la categoría no pertenece a este concurso.");
            }

            foreach ($v->errores() as $mensaje) {
                $errores['fila_' . $indice . '_' . md5($mensaje)] = $mensaje;
            }

            if (!$v->tieneErrores()) {
                $validadas[] = [
                    'dni'          => $v->limpio('dni'),
                    'ap_paterno'   => $v->limpio('ap_paterno'),
                    'ap_materno'   => $v->limpio('ap_materno'),
                    'nombres'      => $v->limpio('nombres'),
                    'categoria_id' => $categoriaId,
                ];
            }
        }

        if ($errores !== []) {
            $this->ver('inscripciones.delegacion', [
                'titulo'        => 'Inscripción por delegación',
                'concurso'      => $concurso,
                'instituciones' => InstitucionEducativa::listar('', null, 500),
                'institucion'   => $institucion,
                'categorias'    => Concurso::categorias($concursoId),
                'tarifas'       => Concurso::tarifas($concursoId),
                'filas'         => $filasEnviadas,
                'errores'       => $errores,
            ]);

            return;
        }

        /*
         * Decisión D-11: el monto se deriva del tipo de la I.E., no lo elige
         * la secretaria. Se resuelve una sola vez para toda la delegación
         * porque todos comparten institución.
         */
        $monto   = Concurso::tarifa($concursoId, (string) $institucion['tipo']);
        $prefijo = Participante::prefijoConcurso($concursoId);
        $usuario = (int) Auth::id();

        /*
         * El encargado de la delegación es el apoderado de todos sus
         * participantes (D-28). Se resuelve una sola vez para el lote entero
         * porque todos comparten institución, y se guarda en cada participante
         * en vez de deducirse después por la I.E.: si el año que viene encabeza
         * otro docente, estas inscripciones tienen que seguir diciendo quién las
         * hizo, no quién manda hoy.
         */
        $encargadoId = (int) $institucion['docente_delegado_id'];

        try {
            /*
             * Todo el lote en una transacción: o entran los N participantes con
             * sus N inscripciones, o no entra ninguno. A media delegación
             * cargada, un fallo dejaría a la secretaria sin saber por dónde iba.
             */
            $codigos = Database::transaccion(
                static function () use ($validadas, $concursoId, $ieId, $monto, $prefijo, $usuario, $encargadoId): array {
                    $generados = [];

                    foreach ($validadas as $fila) {
                        $participanteId = Participante::crear([
                            'concurso_id'       => $concursoId,
                            'tipo_participante' => 'delegacion',
                            'dni'               => $fila['dni'],
                            'ap_paterno'        => $fila['ap_paterno'],
                            'ap_materno'        => $fila['ap_materno'],
                            'nombres'           => $fila['nombres'],
                            'institucion_id'    => $ieId,
                            'apoderado_id'      => $encargadoId,
                        ], $prefijo);

                        Inscripcion::crear([
                            'participante_id' => $participanteId,
                            'categoria_id'    => $fila['categoria_id'],
                            'usuario_id'      => $usuario,
                            'monto'           => $monto,
                        ]);

                        $participante = Participante::porId($participanteId);
                        $generados[]  = $participante['codigo_correlativo'] ?? '';
                    }

                    return $generados;
                }
            );
        } catch (Throwable $e) {
            error_log((string) $e);
            Sesion::flash('error', 'No se pudo registrar la delegación. No se guardó nada.');
            $this->redirigir('/inscripciones/delegacion?institucion_id=' . $ieId);
        }

        $cantidad = count($codigos);
        $total    = number_format($monto * $cantidad, 2);

        Sesion::flash(
            'exito',
            "Se registraron {$cantidad} participante(s) de «{$institucion['nombre']}». "
            . "Total por cobrar: S/ {$total}."
        );

        $this->redirigir('/inscripciones?institucion_id=' . $ieId);
    }

    // ==================================================================
    // Flujo 2 — Inscripción individual (estudiante libre)
    // ==================================================================

    public function formularioLibre(): void
    {
        Auth::exigirSesion();

        $concurso = $this->concursoOFallar();

        $this->ver('inscripciones.libre', [
            'titulo'     => 'Inscripción de estudiante libre',
            'concurso'   => $concurso,
            'categorias' => Concurso::categorias((int) $concurso['id']),
            'tarifa'     => Concurso::tarifa((int) $concurso['id'], 'libre'),
            'valores'    => [],
            'errores'    => [],
        ]);
    }

    public function guardarLibre(): void
    {
        Auth::exigirSesion();
        $this->exigirCsrf();

        $concurso   = $this->concursoOFallar();
        $concursoId = (int) $concurso['id'];

        $v = new Validador($_POST);

        // Datos del estudiante
        $v->requerido('dni', 'El documento del estudiante')->dni('dni', 'El documento del estudiante');
        $v->requerido('ap_paterno', 'El apellido paterno del estudiante')->maximo('ap_paterno', 100, 'El apellido paterno');
        $v->requerido('ap_materno', 'El apellido materno del estudiante')->maximo('ap_materno', 100, 'El apellido materno');
        $v->requerido('nombres', 'Los nombres del estudiante')->maximo('nombres', 150, 'Los nombres');
        $v->requerido('categoria_id', 'La categoría');

        // Datos del apoderado
        $v->requerido('ap_dni', 'El documento del apoderado')->dni('ap_dni', 'El documento del apoderado');
        $v->requerido('ap_ap_paterno', 'El apellido paterno del apoderado');
        $v->requerido('ap_ap_materno', 'El apellido materno del apoderado');
        $v->requerido('ap_nombres', 'Los nombres del apoderado');
        $v->requerido('ap_celular', 'El celular del apoderado')->celular('ap_celular', 'El celular del apoderado');
        $v->correo('ap_correo', 'El correo del apoderado');

        $categoriaId = (int) $v->limpio('categoria_id');

        if ($categoriaId > 0 && !Concurso::categoriaPertenece($categoriaId, $concursoId)) {
            $v->fallar('categoria_id', 'La categoría no pertenece a este concurso.');
        }

        if ($v->tieneErrores()) {
            $this->ver('inscripciones.libre', [
                'titulo'     => 'Inscripción de estudiante libre',
                'concurso'   => $concurso,
                'categorias' => Concurso::categorias($concursoId),
                'tarifa'     => Concurso::tarifa($concursoId, 'libre'),
                'valores'    => $_POST,
                'errores'    => $v->errores(),
            ]);

            return;
        }

        $monto   = Concurso::tarifa($concursoId, 'libre');
        $prefijo = Participante::prefijoConcurso($concursoId);
        $usuario = (int) Auth::id();

        $datosApoderado = [
            'dni'        => $v->limpio('ap_dni'),
            'ap_paterno' => $v->limpio('ap_ap_paterno'),
            'ap_materno' => $v->limpio('ap_ap_materno'),
            'nombres'    => $v->limpio('ap_nombres'),
            'celular'    => $v->limpio('ap_celular'),
        ];

        /*
         * El correo solo viaja si trae valor. La clave ausente le dice a
         * Apoderado::actualizar() que no toque esa columna, y eso protege un
         * caso real: si el apoderado de este estudiante resulta ser también el
         * docente delegado de un colegio, dejar el campo en blanco aquí no puede
         * borrarle el correo por el que se coordina con su delegación entera.
         * Para vaciarlo a propósito está su ficha en /apoderados.
         */
        if ($v->limpioONulo('ap_correo') !== null) {
            $datosApoderado['correo'] = $v->limpio('ap_correo');
        }

        $datosEstudiante = [
            'dni'        => $v->limpio('dni'),
            'ap_paterno' => $v->limpio('ap_paterno'),
            'ap_materno' => $v->limpio('ap_materno'),
            'nombres'    => $v->limpio('nombres'),
        ];

        try {
            $codigo = Database::transaccion(
                static function () use (
                    $datosApoderado, $datosEstudiante, $concursoId,
                    $categoriaId, $monto, $prefijo, $usuario
                ): string {
                    /*
                     * El apoderado se reutiliza si ya existe (caso hermanos) y
                     * se actualizan sus datos de contacto, que es lo que suele
                     * cambiar. Si no existe, se crea.
                     */
                    $existente = Apoderado::porDni($datosApoderado['dni']);

                    if ($existente !== null) {
                        $apoderadoId = (int) $existente['id'];
                        Apoderado::actualizar($apoderadoId, $datosApoderado);
                    } else {
                        $apoderadoId = Apoderado::crear($datosApoderado);
                    }

                    $participanteId = Participante::crear($datosEstudiante + [
                        'concurso_id'       => $concursoId,
                        'tipo_participante' => 'libre',
                        'institucion_id'    => null,
                        'apoderado_id'      => $apoderadoId,
                    ], $prefijo);

                    Inscripcion::crear([
                        'participante_id' => $participanteId,
                        'categoria_id'    => $categoriaId,
                        'usuario_id'      => $usuario,
                        'monto'           => $monto,
                    ]);

                    $participante = Participante::porId($participanteId);

                    return (string) ($participante['codigo_correlativo'] ?? '');
                }
            );
        } catch (Throwable $e) {
            error_log((string) $e);
            Sesion::flash('error', 'No se pudo registrar la inscripción. No se guardó nada.');
            $this->redirigir('/inscripciones/libre');
        }

        Sesion::flash(
            'exito',
            "Estudiante libre inscrito con el código {$codigo}. Monto por cobrar: S/ "
            . number_format($monto, 2) . '.'
        );

        $this->redirigir('/inscripciones?q=' . urlencode($codigo));
    }

    // ==================================================================
    // Apoyo
    // ==================================================================

    /**
     * Advertencia de documento repetido (decisión D-05: se avisa, no se
     * impide). La consume el formulario mientras la secretaria escribe.
     */
    public function verificarDocumento(): void
    {
        Auth::exigirSesion();

        $concurso = Concurso::vigente();
        $dni      = preg_replace('/[\s\-]/', '', (string) ($_GET['dni'] ?? '')) ?? '';

        if ($concurso === null || mb_strlen($dni) < 8) {
            $this->json(['repetidos' => []]);
        }

        $this->json([
            'repetidos' => Participante::mismoDocumento((int) $concurso['id'], mb_strtoupper($dni)),
        ]);
    }

    /**
     * @param array<string, mixed> $fila
     */
    private function filaVacia(array $fila): bool
    {
        foreach (['dni', 'ap_paterno', 'ap_materno', 'nombres'] as $campo) {
            if (trim((string) ($fila[$campo] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function concursoOFallar(): array
    {
        $concurso = Concurso::vigente();

        if ($concurso === null) {
            Sesion::flash('error', 'No hay ningún concurso configurado. Ejecuta el seed inicial.');
            $this->redirigir('/panel');
        }

        return $concurso;
    }
}
