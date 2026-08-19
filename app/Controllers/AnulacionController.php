<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Carne;
use App\Models\Concurso;
use App\Models\Inscripcion;
use Core\Auth;
use Core\Controller;
use Core\Database;
use Core\Sesion;
use Core\Validador;
use Throwable;

/**
 * Anulación de inscripciones, en sus dos formas.
 *
 * Decisión del propietario (2026-08-16): son **dos acciones distintas**, con
 * dos botones distintos, para que la intención quede explícita:
 *
 *   1. «Corregir categoría»       → anula y reinscribe en un solo paso.
 *                                    No genera devolución: el dinero se queda.
 *   2. «Anular definitivamente»   → pide motivo y, si había pago confirmado,
 *                                    marca el monto para el fondo de devoluciones.
 *   3. «Reinscribir»              → deshace la anterior. Solo aparece cuando el
 *                                    participante se quedó SIN ninguna inscripción
 *                                    viva, que es cuando queda fuera del concurso.
 *
 * Con una sola acción y una casilla «va a reinscribirse», olvidarse de marcarla
 * contaminaría el fondo de devoluciones con montos que nunca se van a devolver.
 *
 * La tercera existe desde el 2026-08-19 (D-38) porque las dos primeras dejaban
 * un callejón sin salida: con el documento único de D-31, un participante cuya
 * única inscripción se anulaba no podía volver a darse de alta —su DNI ya estaba
 * tomado— ni corregirse —«Corregir categoría» rechaza lo anulado—. La única
 * salida era SQL a mano, y eso durante dos días de registro con el tutor delante.
 */
final class AnulacionController extends Controller
{
    /**
     * Formulario de corrección de categoría.
     */
    public function formularioCorregir(string $id): void
    {
        Auth::exigirSesion();

        $inscripcion = $this->inscripcionVigenteOFallar((int) $id);
        $concurso    = Concurso::vigente();

        $this->ver('inscripciones.corregir', [
            'titulo'      => 'Corregir categoría',
            'inscripcion' => $inscripcion,
            'categorias'  => Concurso::categorias((int) $concurso['id']),
            'errores'     => [],
        ]);
    }

    /**
     * Anula la inscripción actual y crea una nueva con la categoría corregida.
     *
     * El participante conserva su código correlativo: la corrección vive en la
     * inscripción, no en el participante (decisión D-01). La inscripción
     * anulada conserva la categoría errónea, que es justo el rastro que el
     * futuro módulo de calificación necesitará.
     */
    public function corregir(string $id): void
    {
        Auth::exigirSesion();
        $this->exigirCsrf();

        $inscripcionId = (int) $id;
        $inscripcion   = $this->inscripcionVigenteOFallar($inscripcionId);
        $concurso      = Concurso::vigente();
        $concursoId    = (int) $concurso['id'];

        $v = new Validador($_POST);
        $v->requerido('categoria_id', 'La nueva categoría');

        $categoriaId = (int) $v->limpio('categoria_id');

        if ($categoriaId > 0 && !Concurso::categoriaPertenece($categoriaId, $concursoId)) {
            $v->fallar('categoria_id', 'La categoría no pertenece a este concurso.');
        }

        if ($categoriaId === (int) $inscripcion['categoria_id']) {
            $v->fallar('categoria_id', 'Esa ya es la categoría actual: no hay nada que corregir.');
        }

        if ($v->tieneErrores()) {
            $this->ver('inscripciones.corregir', [
                'titulo'      => 'Corregir categoría',
                'inscripcion' => $inscripcion,
                'categorias'  => Concurso::categorias($concursoId),
                'errores'     => $v->errores(),
            ]);

            return;
        }

        $motivo  = trim((string) ($_POST['motivo'] ?? '')) ?: 'Corrección de categoría';
        $usuario = (int) Auth::id();

        try {
            Database::transaccion(
                static function () use ($inscripcion, $inscripcionId, $categoriaId, $motivo, $usuario): void {
                    // esDefinitiva = false: no hay devolución, el dinero se traslada.
                    Inscripcion::anular($inscripcionId, $motivo, false, $usuario);

                    $nuevaId = Inscripcion::crear([
                        'participante_id' => (int) $inscripcion['participante_id'],
                        'categoria_id'    => $categoriaId,
                        'usuario_id'      => $usuario,
                        // Se conserva el estado, la modalidad y el monto: si ya
                        // estaba pagada, la nueva nace pagada. El estudiante no
                        // paga dos veces por un error de categoría, y corregir
                        // el grado no puede cambiarle la bolsa en la que compite
                        // ni la modalidad con la que se le cobró (D-37).
                        //
                        // Y con ellos el medio de pago: la nueva nacía pagada
                        // pero sin decir CÓMO, así que cuadrar la caja al final
                        // del día dejaba de salir y el código de Yape —la prueba
                        // de esa transacción— se perdía.
                        'estado'                => $inscripcion['estado'],
                        'tipo_origen'           => $inscripcion['tipo_origen'],
                        'monto'                 => (float) $inscripcion['monto'],
                        'medio_pago'            => $inscripcion['medio_pago'],
                        'yape_codigo_seguridad' => $inscripcion['yape_codigo_seguridad'],
                        'fecha_pago'            => $inscripcion['fecha_pago'],
                    ]);

                    /*
                     * El carné se emite para la inscripción NUEVA. El carné
                     * pertenece a una inscripción concreta, así que la corregida
                     * nacía confirmada y sin carné: el enlace «PDF» del listado
                     * respondía «todavía no tiene carné emitido» a un estudiante
                     * que ya había pagado, y había que acordarse de pulsar
                     * «Regenerar» a mano.
                     */
                    if ($inscripcion['estado'] === 'confirmada') {
                        Carne::registrar($nuevaId, (string) $inscripcion['codigo_correlativo']);
                    }
                }
            );
        } catch (Throwable $e) {
            error_log((string) $e);
            Sesion::flash('error', 'No se pudo corregir la categoría. No se cambió nada.');
            $this->redirigir('/inscripciones');
        }

        Sesion::flash(
            'exito',
            'Categoría corregida. El participante conserva su código '
            . $inscripcion['codigo_correlativo'] . '.'
        );

        $this->redirigir('/inscripciones?q=' . urlencode((string) $inscripcion['codigo_correlativo']));
    }

    /**
     * Anulación definitiva, sin reinscripción.
     */
    public function anular(string $id): void
    {
        Auth::exigirSesion();
        $this->exigirCsrf();

        $inscripcionId = (int) $id;
        $inscripcion   = $this->inscripcionVigenteOFallar($inscripcionId);

        $motivo = trim((string) ($_POST['motivo'] ?? ''));

        if ($motivo === '') {
            Sesion::flash('error', 'Indica el motivo de la anulación definitiva.');
            $this->redirigir('/inscripciones?q=' . urlencode((string) $inscripcion['codigo_correlativo']));
        }

        $estabaPagada = $inscripcion['estado'] === 'confirmada';

        Inscripcion::anular($inscripcionId, mb_substr($motivo, 0, 250), true, (int) Auth::id());

        $mensaje = 'Inscripción anulada definitivamente.';

        if ($estabaPagada) {
            $mensaje .= ' Como tenía pago confirmado, S/ '
                . number_format((float) $inscripcion['monto'], 2)
                . ' se sumó al fondo de devoluciones.';
        }

        Sesion::flash('exito', $mensaje);
        $this->redirigir('/inscripciones');
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Formulario de reinscripción de un participante que quedó fuera.
     */
    public function formularioReinscribir(string $id): void
    {
        Auth::exigirSesion();

        $inscripcion = $this->inscripcionReinscribibleOFallar((int) $id);
        $concurso    = Concurso::vigente();

        $this->ver('inscripciones.reinscribir', [
            'titulo'      => 'Reinscribir participante',
            'inscripcion' => $inscripcion,
            'categorias'  => Concurso::categorias((int) $concurso['id']),
            'errores'     => [],
        ]);
    }

    /**
     * Devuelve al concurso a un participante cuya única inscripción se anuló.
     *
     * No revive la inscripción anulada: crea una nueva, igual que hace
     * «Corregir categoría». La anulada se queda donde está, con su motivo, que
     * es el rastro de lo que pasó.
     *
     * Si el estudiante **ya había pagado**, la nueva nace confirmada con su
     * mismo medio de pago y con su carné emitido, y el monto sale del fondo de
     * devoluciones: ese dinero no se devolvió, se está volviendo a aplicar. Si
     * se quedara marcado, el reporte pediría entregar un dinero que ya se gastó
     * en esta misma inscripción, y el concurso lo pagaría dos veces.
     */
    public function reinscribir(string $id): void
    {
        Auth::exigirSesion();
        $this->exigirCsrf();

        $inscripcionId = (int) $id;
        $inscripcion   = $this->inscripcionReinscribibleOFallar($inscripcionId);
        $concurso      = Concurso::vigente();
        $concursoId    = (int) $concurso['id'];

        $v = new Validador($_POST);
        $v->requerido('categoria_id', 'La categoría');

        $categoriaId = (int) $v->limpio('categoria_id');

        if ($categoriaId > 0 && !Concurso::categoriaPertenece($categoriaId, $concursoId)) {
            $v->fallar('categoria_id', 'La categoría no pertenece a este concurso.');
        }

        if ($v->tieneErrores()) {
            $this->ver('inscripciones.reinscribir', [
                'titulo'      => 'Reinscribir participante',
                'inscripcion' => $inscripcion,
                'categorias'  => Concurso::categorias($concursoId),
                'errores'     => $v->errores(),
            ]);

            return;
        }

        /*
         * Que hubiera pago se deduce de `fecha_pago` y no de `requiere_devolucion`:
         * la fecha la escribe el cobro y la anulación no la borra, así que es el
         * dato que de verdad dice si este estudiante pagó.
         */
        $habiaPagado = $inscripcion['fecha_pago'] !== null;
        $motivo      = trim((string) ($_POST['motivo'] ?? ''));
        $usuario     = (int) Auth::id();

        try {
            Database::transaccion(
                static function () use ($inscripcion, $inscripcionId, $categoriaId, $habiaPagado, $motivo, $usuario): void {
                    $nuevaId = Inscripcion::crear([
                        'participante_id'       => (int) $inscripcion['participante_id'],
                        'categoria_id'          => $categoriaId,
                        'usuario_id'            => $usuario,
                        'estado'                => $habiaPagado ? 'confirmada' : 'pendiente',
                        'tipo_origen'           => $inscripcion['tipo_origen'],
                        'monto'                 => (float) $inscripcion['monto'],
                        'medio_pago'            => $habiaPagado ? $inscripcion['medio_pago'] : null,
                        'yape_codigo_seguridad' => $habiaPagado ? $inscripcion['yape_codigo_seguridad'] : null,
                        'fecha_pago'            => $habiaPagado ? $inscripcion['fecha_pago'] : null,
                    ]);

                    if ($habiaPagado) {
                        Carne::registrar($nuevaId, (string) $inscripcion['codigo_correlativo']);
                        Inscripcion::limpiarDevolucion($inscripcionId);
                    }

                    if ($motivo !== '') {
                        Inscripcion::anotarEnAnulacion($inscripcionId, 'Reinscrito: ' . $motivo);
                    }
                }
            );
        } catch (Throwable $e) {
            error_log((string) $e);
            Sesion::flash('error', 'No se pudo reinscribir. No se cambió nada.');
            $this->redirigir('/inscripciones');
        }

        $mensaje = 'Participante reinscrito, conservando su código '
                 . $inscripcion['codigo_correlativo'] . '.';

        if ($habiaPagado) {
            $mensaje .= ' Como ya había pagado, la inscripción nace confirmada con su carné,'
                      . ' y sus S/ ' . number_format((float) $inscripcion['monto'], 2)
                      . ' salen del fondo de devoluciones.';
        } else {
            $mensaje .= ' Queda pendiente de pago.';
        }

        Sesion::flash('exito', $mensaje);
        $this->redirigir('/inscripciones?q=' . urlencode((string) $inscripcion['codigo_correlativo']));
    }

    /**
     * Una inscripción solo se puede reinscribir si está anulada **y** su
     * participante no tiene ninguna otra viva.
     *
     * La segunda condición es la que impide el registro raro: sin ella, se
     * podría reinscribir sobre la anulada que deja atrás cada corrección de
     * categoría, y el estudiante acabaría con dos inscripciones activas, dos
     * carnés y dos montos.
     *
     * @return array<string, mixed>
     */
    private function inscripcionReinscribibleOFallar(int $id): array
    {
        $inscripcion = Inscripcion::porId($id);

        if ($inscripcion === null) {
            Sesion::flash('error', 'Esa inscripción no existe.');
            $this->redirigir('/inscripciones');
        }

        if ($inscripcion['estado'] !== 'anulada') {
            Sesion::flash('error', 'Solo se reinscribe desde una inscripción anulada.');
            $this->redirigir('/inscripciones');
        }

        $activa = Inscripcion::activaDe((int) $inscripcion['participante_id']);

        if ($activa !== null) {
            Sesion::flash(
                'error',
                'Ese participante ya tiene una inscripción vigente: no hay nada que reinscribir. '
                . 'Si lo que quieres es cambiarle el grado, usa «Corregir categoría» sobre la vigente.'
            );
            $this->redirigir('/inscripciones?q=' . urlencode((string) $inscripcion['codigo_correlativo']));
        }

        return $inscripcion;
    }

    private function inscripcionVigenteOFallar(int $id): array
    {
        $inscripcion = Inscripcion::porId($id);

        if ($inscripcion === null) {
            Sesion::flash('error', 'Esa inscripción no existe.');
            $this->redirigir('/inscripciones');
        }

        if ($inscripcion['estado'] === 'anulada') {
            Sesion::flash('error', 'Esa inscripción ya está anulada.');
            $this->redirigir('/inscripciones');
        }

        return $inscripcion;
    }
}
