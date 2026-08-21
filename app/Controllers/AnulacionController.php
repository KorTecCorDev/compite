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
 * Anulación de inscripciones, y la vuelta atrás.
 *
 * Quedan aquí dos acciones:
 *
 *   1. «Anular definitivamente»  → pide motivo y, si había pago confirmado,
 *                                   marca el monto para el fondo de devoluciones.
 *   2. «Reinscribir»             → deshace la anterior. Solo aparece cuando el
 *                                   participante se quedó SIN ninguna inscripción
 *                                   viva, que es cuando queda fuera del concurso.
 *
 * Son dos botones distintos y no uno con una casilla «va a reinscribirse»
 * (decisión del propietario, 2026-08-16): olvidarse de marcarla contaminaría el
 * fondo de devoluciones con montos que nunca se van a devolver.
 *
 * «Reinscribir» existe desde el 2026-08-19 (D-38) porque la anulación dejaba un
 * callejón sin salida: con el documento único de D-31, un participante cuya
 * única inscripción se anulaba no podía volver a darse de alta —su DNI ya estaba
 * tomado—. La única salida era SQL a mano, y eso durante dos días de registro
 * con el tutor delante.
 *
 * **«Corregir» ya no vive aquí** (D-50, 2026-08-20). Vivía porque anulaba la
 * inscripción y creaba otra con el grado bueno; ahora corrige en su sitio, con
 * firma y motivo, y se mudó a `CorreccionController`. Un grado mal apuntado no
 * es un cambio de historia: es un dato que estaba mal escrito.
 */
final class AnulacionController extends Controller
{
    /**
     * Anulación definitiva, sin reinscripción.
     *
     * **Exclusiva del administrador** (D-51). Es la acción irreversible del
     * sistema: saca a un estudiante del concurso y, si había pago confirmado,
     * manda su monto al fondo de devoluciones. Con varias secretarias
     * registrando a la vez, una anulación indebida no se deshace — «Reinscribir»
     * crea una inscripción nueva, no revive la anulada.
     */
    public function anular(string $id): void
    {
        Auth::exigirSesion();
        $this->exigirCsrf();

        $inscripcionId = (int) $id;

        /*
         * Se comprueba aquí en vez de con `Auth::exigirAdministrador()` por una
         * razón de trato: ese método responde «esa sección es exclusiva del
         * administrador» y devuelve al panel, que es lo correcto para
         * /usuarios o /instituciones —secciones enteras que la secretaria no
         * pisa—. Pero /inscripciones **sí es suya**, la usa todo el día. Sacarla
         * de ella diciéndole que no es su sección sería desconcertante y la
         * dejaría lejos de la fila en la que estaba trabajando.
         *
         * Se rechaza en voz alta y no en silencio: si el POST se ignorara, la
         * pantalla volvería sin decir nada y ella creería que la inscripción
         * quedó anulada cuando sigue viva.
         */
        if (!Auth::esAdministrador()) {
            /*
             * Sin `http_response_code(403)`: no serviría de nada y mentiría al
             * leerlo. Comprobado sobre PHP 8.2 con el servidor embebido — un
             * `Location:` posterior **degrada la respuesta a 302** salvo que el
             * código ya fijado sea 3xx o 201, así que el 403 nunca sale por el
             * cable. La protección de verdad es que la anulación no se ejecuta
             * y que se dice por qué.
             */
            Sesion::flash(
                'error',
                'Anular una inscripción es exclusivo del administrador. No se anuló nada: '
                . 'pídeselo a él. Si solo hay un dato mal escrito, usa «Corregir».'
            );
            $this->redirigir('/inscripciones#ins-' . $inscripcionId);
        }

        $inscripcion   = $this->inscripcionVigenteOFallar($inscripcionId);

        $motivo = trim((string) ($_POST['motivo'] ?? ''));

        if ($motivo === '') {
            Sesion::flash('error', 'Indica el motivo de la anulación definitiva.');
            $this->redirigir('/inscripciones#ins-' . $inscripcionId);
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
        $this->redirigir('/inscripciones#ins-' . $inscripcionId);
    }

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
     * «Corregir categoría» antes de D-50. La anulada se queda donde está, con
     * su motivo, que es el rastro de lo que pasó.
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

        // La transacción devuelve el id de la inscripción nueva para poder
        // anclar el listado en ella (D-48).
        try {
            $nuevaId = Database::transaccion(
                static function () use ($inscripcion, $inscripcionId, $categoriaId, $habiaPagado, $motivo, $usuario): int {
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

                    return $nuevaId;
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
        $this->redirigir('/inscripciones#ins-' . (int) $nuevaId);
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
                . 'Si lo que quieres es arreglarle un dato —el grado, el documento, un apellido—, '
                . 'usa «Corregir» sobre la vigente.'
            );
            $this->redirigir('/inscripciones#ins-' . (int) $activa['id']);
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
