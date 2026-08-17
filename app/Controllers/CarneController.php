<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Carne;
use App\Models\Inscripcion;
use App\Models\Participante;
use Core\Auth;
use Core\Config;
use Core\Controller;
use Core\Correlativo;
use Core\Sesion;
use Core\View;

final class CarneController extends Controller
{
    /**
     * Vista digital del carné. **Pública, sin login** — es lo que abre el QR.
     *
     * Regla confirmada del propietario: cualquiera con el enlace puede verla.
     * Por eso el código lleva sufijo aleatorio (D-04): sin él, esta ruta sería
     * un directorio navegable de datos de menores de edad.
     */
    public function publico(string $codigo): void
    {
        $codigo = mb_strtoupper(trim($codigo));

        /*
         * Se descarta la basura antes de tocar la base. Reduce el ruido en el
         * log y evita gastar consultas en escaneos automáticos.
         */
        if (!Correlativo::esValido($codigo)) {
            $this->noEncontrado();
        }

        $ficha = Participante::porCodigo($codigo);

        if ($ficha === null) {
            $this->noEncontrado();
        }

        /*
         * Una inscripción pendiente de pago todavía no da derecho a carné: el
         * plan es claro en que el carné se emite al confirmar el pago.
         */
        $estado = (string) ($ficha['estado'] ?? '');

        echo View::renderizar('carne.publico', [
            'titulo' => 'Carné ' . $codigo,
            'ficha'  => $ficha,
            'estado' => $estado,
        ], 'publico');
    }

    /**
     * Descarga del PDF. Esta sí exige sesión: el archivo vive fuera de
     * `public/` a propósito, y se sirve por PHP para poder controlar quién
     * lo baja y no dejar el directorio de carnés expuesto en el servidor web.
     */
    public function descargar(string $id): void
    {
        Auth::exigirSesion();

        $inscripcion = Inscripcion::porId((int) $id);

        if ($inscripcion === null) {
            Sesion::flash('error', 'Esa inscripción no existe.');
            $this->redirigir('/inscripciones');
        }

        $carne = Carne::porInscripcion((int) $id);

        if ($carne === null) {
            Sesion::flash('error', 'Esa inscripción todavía no tiene carné emitido.');
            $this->redirigir('/inscripciones');
        }

        $ruta = Config::ruta((string) $carne['ruta_pdf']);

        if (!is_file($ruta)) {
            Sesion::flash('error', 'El archivo del carné no está en el servidor. Vuelve a generarlo.');
            $this->redirigir('/inscripciones');
        }

        $nombre = 'carne-' . $inscripcion['codigo_correlativo'] . '.pdf';

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Content-Length: ' . filesize($ruta));
        header('X-Content-Type-Options: nosniff');
        readfile($ruta);
        exit;
    }

    private function noEncontrado(): never
    {
        http_response_code(404);

        echo View::renderizar('carne.no-encontrado', [
            'titulo' => 'Carné no encontrado',
        ], 'publico');

        exit;
    }
}
