<?php

declare(strict_types=1);

namespace App\Controllers;

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
 *
 * Con una sola acción y una casilla «va a reinscribirse», olvidarse de marcarla
 * contaminaría el fondo de devoluciones con montos que nunca se van a devolver.
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
                    Inscripcion::anular($inscripcionId, $motivo, false);

                    Inscripcion::crear([
                        'participante_id' => (int) $inscripcion['participante_id'],
                        'categoria_id'    => $categoriaId,
                        'usuario_id'      => $usuario,
                        // Se conserva el estado y el monto: si ya estaba pagada,
                        // la nueva nace pagada. El estudiante no paga dos veces
                        // por un error de categoría.
                        'estado'          => $inscripcion['estado'],
                        'monto'           => (float) $inscripcion['monto'],
                    ]);
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

        Inscripcion::anular($inscripcionId, mb_substr($motivo, 0, 250), true);

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
