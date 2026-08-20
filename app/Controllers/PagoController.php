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

        /*
         * A dónde volver si el cobro no sale (D-48): al listado con los filtros
         * que el usuario tenía puestos, o al listado entero si no tenía ninguno.
         *
         * `parse_str` sobre un campo del formulario es entrada del cliente, así
         * que la URL la arma `Inscripcion::urlListado()`, que descarta cualquier
         * clave que no sea uno de los seis filtros del listado.
         */
        parse_str((string) ($_POST['volver'] ?? ''), $filtrosPrevios);
        $volver = Inscripcion::urlListado(is_array($filtrosPrevios) ? $filtrosPrevios : []);

        $v = new Validador($_POST);
        $v->requerido('medio_pago', 'El medio de pago')
          ->enLista('medio_pago', ['yape', 'transferencia', 'efectivo'], 'El medio de pago');

        $medioPago = $v->limpio('medio_pago');

        /*
         * Con Yape, el código de seguridad es OBLIGATORIO (decisión del
         * propietario, 2026-08-18, que revierte D-16). Es el único dato que
         * ata el cobro a una operación concreta en la aplicación del banco:
         * sin él, cuadrar la caja al final del día es la palabra de quien
         * cobró contra un extracto sin forma de emparejar.
         *
         * Para transferencia y efectivo queda NULL: pedirlo sería inventar un
         * dato que no existe.
         */
        $codigoYape = $v->limpio('yape_codigo');

        if ($medioPago === 'yape') {
            if ($codigoYape === '') {
                $v->fallar('yape_codigo', 'Con Yape, el código de seguridad de 3 dígitos es obligatorio.');
            } elseif (preg_match('/^\d{3}$/', $codigoYape) !== 1) {
                $v->fallar('yape_codigo', 'El código de seguridad de Yape son 3 dígitos.');
            }
        }

        if ($ids === []) {
            $v->fallar('ids', 'Selecciona al menos una inscripción por confirmar.');
        }

        if ($v->tieneErrores()) {
            foreach ($v->mensajes() as $mensaje) {
                Sesion::flash('error', $mensaje);
            }
            $this->redirigir($volver);
        }

        $pendientes = Inscripcion::pendientesPorIds($ids, $concursoId);

        if ($pendientes === []) {
            Sesion::flash('error', 'Ninguna de las inscripciones seleccionadas sigue pendiente.');
            $this->redirigir($volver);
        }

        // Solo Yape trae código; en transferencia y efectivo la columna queda NULL.
        $yapeCodigo = $medioPago === 'yape' ? $codigoYape : null;

        // Quién está cobrando. Queda escrito en cada inscripción (D-39): con
        // varias secretarias trabajando a la vez, un cobro mal hecho tiene que
        // poder atribuirse.
        $usuario = (int) Auth::id();

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
                    static function () use ($inscripcionId, $medioPago, $yapeCodigo, $usuario): void {
                        Inscripcion::confirmarPago($inscripcionId, $medioPago, $yapeCodigo, $usuario);
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
            /*
             * El mensaje tiene que distinguir los dos casos. Decir «el resto sí
             * quedó confirmado» cuando no se confirmó ninguna es peor que no
             * decir nada: la secretaria se queda sin saber si cobró o no, y con
             * dinero de por medio esa duda se resuelve mirando la caja.
             */
            $mensaje = $confirmadas > 0
                ? 'No se pudieron procesar ' . count($fallosCarne) . ' inscripción(es); '
                  . "las otras {$confirmadas} sí quedaron confirmadas."
                : 'No se pudo procesar ninguna de las ' . count($fallosCarne)
                  . ' inscripción(es): siguen pendientes y no se cobró nada.';

            Sesion::flash(
                'error',
                $mensaje . ' El detalle está en storage/logs/php-error.log.'
            );
        }

        /*
         * De vuelta al listado SIN filtrar (D-48).
         *
         * Aquí había `?estado=confirmada`, y no era solo una molestia: tras
         * cobrar, las pendientes que NO se cobraron desaparecían de la pantalla,
         * y con ellas la casilla de «seleccionar todas las pendientes», que solo
         * se dibuja si queda alguna a la vista. El listado afirmaba que el
         * trabajo estaba terminado justo cuando no lo estaba.
         *
         * Tampoco se restauran aquí los filtros previos: el cobro salió bien y
         * lo que toca es ver el estado real de la caja, no volver al recorte con
         * el que se estaba trabajando. El recuento y el importe van en el aviso.
         */
        $this->redirigir('/inscripciones');
    }

    /**
     * Vuelve a emitir el carné de una inscripción confirmada.
     *
     * Con el PDF generándose al vuelo (D-24) el diseño y los datos ya salen
     * siempre al día, así que esto solo hace falta en un caso: que el pago se
     * confirmara pero el registro del carné no llegara a escribirse.
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
            Sesion::flash('exito', 'Carné emitido.');
        } catch (Throwable $e) {
            error_log((string) $e);
            Sesion::flash('error', 'No se pudo emitir el carné.');
        }

        $this->redirigir('/inscripciones#ins-' . (int) $id);
    }

    /**
     * Deja constancia de que la inscripción ya tiene carné.
     *
     * Desde D-24 esto no escribe ningún archivo: el PDF se arma al vuelo cuando
     * alguien lo descarga. Emitir el carné es, por tanto, una operación de
     * registro —y por eso ya no puede fallar por permisos de disco ni dejar el
     * pago confirmado sin carné.
     *
     * Se guarda el código, no la URL: la URL depende del entorno y se deriva
     * cuando hace falta con GeneradorCarne::urlPublica(). Ver D-21.
     */
    private static function emitirCarne(int $inscripcionId): void
    {
        $inscripcion = Inscripcion::porId($inscripcionId);

        if ($inscripcion === null) {
            return;
        }

        Carne::registrar($inscripcionId, (string) $inscripcion['codigo_correlativo']);
    }
}
