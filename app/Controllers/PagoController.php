<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Carne;
use App\Models\Concurso;
use App\Models\Inscripcion;
use App\Models\Participante;
use App\Servicios\GeneradorCarne;
use Core\Auth;
use Core\Controller;
use Core\Database;
use Core\Sesion;
use Core\Validador;
use Throwable;

/**
 * Confirmación de pagos y emisión del carné.
 *
 * Decisión del propietario (2026-08-16): la confirmación es **masiva**. Una
 * delegación paga con un solo Yape por sus 30 estudiantes, así que obligar a
 * confirmar de a uno sería inventar un trabajo que la realidad no pide.
 */
final class PagoController extends Controller
{
    public function confirmar(): void
    {
        Auth::exigirSesion();
        $this->exigirCsrf();

        $concurso   = Concurso::vigente();
        $concursoId = (int) ($concurso['id'] ?? 0);

        $ids = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [];

        $v = new Validador($_POST);
        $v->requerido('medio_pago', 'El medio de pago')
          ->enLista('medio_pago', ['yape', 'transferencia', 'efectivo'], 'El medio de pago');

        $medioPago = $v->limpio('medio_pago');

        /*
         * Lo obligatorio es el medio de pago. El código de seguridad de Yape es
         * OPCIONAL (decisión del propietario, 2026-08-17): la secretaria no
         * siempre lo tiene a la vista al momento de confirmar, y bloquear el
         * cobro por un dato de respaldo detendría la caja sin motivo.
         *
         * Si lo escribe, sí se valida el formato: un código a medias es peor que
         * ninguno, porque da falsa confianza al cuadrar después.
         *
         * Para transferencia y efectivo queda NULL: pedirlo sería inventar un
         * dato que no existe.
         */
        $codigoYape = $v->limpio('yape_codigo');

        if ($medioPago === 'yape' && $codigoYape !== '' && preg_match('/^\d{3}$/', $codigoYape) !== 1) {
            $v->fallar('yape_codigo', 'Si anotas el código de seguridad de Yape, deben ser 3 dígitos.');
        }

        if ($ids === []) {
            $v->fallar('ids', 'Selecciona al menos una inscripción por confirmar.');
        }

        if ($v->tieneErrores()) {
            foreach ($v->mensajes() as $mensaje) {
                Sesion::flash('error', $mensaje);
            }
            $this->redirigir('/inscripciones?estado=pendiente');
        }

        $pendientes = Inscripcion::pendientesPorIds($ids, $concursoId);

        if ($pendientes === []) {
            Sesion::flash('error', 'Ninguna de las inscripciones seleccionadas sigue pendiente.');
            $this->redirigir('/inscripciones?estado=pendiente');
        }

        // Cadena vacía se guarda como NULL: '' en la columna ensuciaría el cuadre.
        $yapeCodigo = ($medioPago === 'yape' && $codigoYape !== '') ? $codigoYape : null;

        $confirmadas = 0;
        $total       = 0.0;
        $fallosCarne = [];

        foreach ($pendientes as $pendiente) {
            $inscripcionId = (int) $pendiente['id'];

            try {
                /*
                 * Cada inscripción va en su propia transacción, no todas juntas.
                 * Si el carné número 27 falla al generarse, no tiene sentido
                 * deshacer los 26 pagos ya confirmados: el dinero se cobró.
                 * El fallo se reporta y el carné se puede regenerar después.
                 */
                Database::transaccion(
                    static function () use ($inscripcionId, $medioPago, $yapeCodigo): void {
                        Inscripcion::confirmarPago($inscripcionId, $medioPago, $yapeCodigo);
                        self::emitirCarne($inscripcionId);
                    }
                );

                $confirmadas++;
                $total += (float) $pendiente['monto'];
            } catch (Throwable $e) {
                error_log('Error al confirmar inscripción ' . $inscripcionId . ': ' . $e);
                $fallosCarne[] = $inscripcionId;
            }
        }

        if ($confirmadas > 0) {
            Sesion::flash(
                'exito',
                "Se confirmaron {$confirmadas} pago(s) por S/ " . number_format($total, 2)
                . '. Los carnés ya están disponibles para descarga.'
            );
        }

        if ($fallosCarne !== []) {
            Sesion::flash(
                'error',
                'No se pudieron procesar ' . count($fallosCarne) . ' inscripción(es). '
                . 'Revisa el log del servidor; el resto sí quedó confirmado.'
            );
        }

        $this->redirigir('/inscripciones?estado=confirmada');
    }

    /**
     * Regenera el carné de una inscripción confirmada.
     *
     * Sirve si el PDF se perdió del disco o si cambió algún dato de la ficha.
     */
    public function regenerarCarne(string $id): void
    {
        Auth::exigirSesion();
        $this->exigirCsrf();

        $inscripcion = Inscripcion::porId((int) $id);

        if ($inscripcion === null || $inscripcion['estado'] !== 'confirmada') {
            Sesion::flash('error', 'Solo se puede emitir el carné de una inscripción confirmada.');
            $this->redirigir('/inscripciones');
        }

        try {
            self::emitirCarne((int) $id);
            Sesion::flash('exito', 'Carné regenerado.');
        } catch (Throwable $e) {
            error_log((string) $e);
            Sesion::flash('error', 'No se pudo generar el carné.');
        }

        $this->redirigir('/inscripciones?q=' . urlencode((string) $inscripcion['codigo_correlativo']));
    }

    /**
     * Crea el PDF y lo registra. Compartido por la confirmación y la
     * regeneración, para que no existan dos caminos que puedan divergir.
     */
    private static function emitirCarne(int $inscripcionId): void
    {
        $inscripcion = Inscripcion::porId($inscripcionId);

        if ($inscripcion === null) {
            return;
        }

        $ficha = Participante::porCodigo((string) $inscripcion['codigo_correlativo']);

        if ($ficha === null) {
            return;
        }

        $ruta = GeneradorCarne::generar($ficha);

        Carne::registrar(
            $inscripcionId,
            GeneradorCarne::urlPublica((string) $inscripcion['codigo_correlativo']),
            $ruta
        );
    }
}
