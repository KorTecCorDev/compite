<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Carne;
use App\Models\Concurso;
use App\Models\Inscripcion;
use App\Models\InstitucionEducativa;
use App\Models\Participante;
use App\Servicios\GeneradorCarne;
use Core\Auth;
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

        $this->mostrar(Participante::porCodigo($codigo), $codigo);
    }

    /**
     * La misma vista, entrando por la ruta corta que codifica el QR (`/c/K7M9XA`).
     *
     * Existe por una razón puramente física: el QR se imprime a 15 mm y cada
     * carácter de la URL le añade módulos. Con el código completo hacían falta
     * 37 × 37 módulos —0.4 mm cada uno, por debajo de lo que lee la cámara de
     * un celular—; con el sufijo solo bajan a 29 × 29. Ver GeneradorCarne.
     */
    public function publicoCorto(string $sufijo): void
    {
        $sufijo = mb_strtoupper(trim($sufijo));

        if (!Correlativo::esSufijoValido($sufijo)) {
            $this->noEncontrado();
        }

        $ficha = Participante::porSufijo($sufijo);

        $this->mostrar($ficha, (string) ($ficha['codigo_correlativo'] ?? $sufijo));
    }

    /**
     * Descarga del PDF de un carné.
     *
     * Exige sesión: aunque la vista digital sea pública, el PDF es el documento
     * que se presenta el día del concurso y su emisión es cosa de la secretaría.
     *
     * Desde D-24 el PDF no existe en disco, se arma aquí mismo. Eso hace que
     * cualquier corrección de datos o de diseño se refleje en la siguiente
     * descarga, sin regenerar nada.
     */
    public function descargar(string $id): void
    {
        Auth::exigirSesion();

        $inscripcion = Inscripcion::porId((int) $id);

        if ($inscripcion === null) {
            Sesion::flash('error', 'Esa inscripción no existe.');
            $this->redirigir('/inscripciones');
        }

        if (Carne::porInscripcion((int) $id) === null) {
            Sesion::flash('error', 'Esa inscripción todavía no tiene carné emitido.');
            $this->redirigir('/inscripciones');
        }

        $ficha = Participante::porCodigo((string) $inscripcion['codigo_correlativo']);

        if ($ficha === null) {
            Sesion::flash('error', 'No se encontró la ficha del participante.');
            $this->redirigir('/inscripciones');
        }

        $this->entregarPdf(
            GeneradorCarne::individual($ficha),
            'carne-' . $inscripcion['codigo_correlativo'] . '.pdf'
        );
    }

    /**
     * Hoja A4 con los carnés de una delegación completa, 10 por página.
     *
     * El flujo real de la secretaría es imprimir de golpe los treinta carnés de
     * un colegio, no descargarlos de uno en uno. Se genera por delegación y no
     * «todos los del concurso» a propósito: Dompdf tarda ~0.4 s cada diez
     * carnés, y quinientos de una sentada se comen el `max_execution_time` de
     * un hosting compartido.
     */
    public function delegacion(string $institucionId): void
    {
        Auth::exigirSesion();

        $concurso   = Concurso::vigente();
        $concursoId = (int) ($concurso['id'] ?? 0);
        $institucion = InstitucionEducativa::porId((int) $institucionId);

        if ($institucion === null) {
            Sesion::flash('error', 'Esa institución educativa no existe.');
            $this->redirigir('/inscripciones');
        }

        /*
         * Solo confirmadas. No es un filtro cosmético: imprimir el carné de una
         * inscripción pendiente de pago o anulada pone en circulación un
         * documento que parece válido y no lo es.
         */
        $filtros = [
            'institucion_id' => (int) $institucionId,
            'estado'         => 'confirmada',
        ];

        /*
         * El listado se corta en `TOPE_LISTADO` filas, y esta hoja usa esa misma
         * consulta (D-40). Un PDF al que le faltan carnés no se nota hasta que
         * faltan en la puerta el día del concurso, así que aquí se prefiere no
         * generarlo antes que generarlo incompleto.
         *
         * Es defensivo: con el tope actual haría falta una delegación de más de
         * dos mil confirmadas, y un PDF de ese tamaño tampoco terminaría de
         * generarse en un hosting compartido.
         */
        /*
         * Los dos redirects de abajo conservan `institucion_id`, y eso NO
         * contradice a D-48: el botón que trae aquí solo existe cuando el
         * listado ya estaba filtrado por esa delegación, así que volver con el
         * filtro es devolver al usuario donde estaba, no imponerle un recorte
         * que no eligió.
         */
        $cuantas = Inscripcion::contarFiltradas($concursoId, $filtros);

        if ($cuantas > Inscripcion::TOPE_LISTADO) {
            Sesion::flash(
                'error',
                "Esa delegación tiene {$cuantas} carnés confirmados y la hoja se corta en "
                . Inscripcion::TOPE_LISTADO . '. Se generaría incompleta, así que no se generó. '
                . 'Imprímelos por grado desde el listado.'
            );
            $this->redirigir('/inscripciones?institucion_id=' . (int) $institucionId);
        }

        $fichas = Inscripcion::listar($concursoId, $filtros);

        if ($fichas === []) {
            Sesion::flash('error', 'Esa delegación todavía no tiene inscripciones confirmadas.');
            $this->redirigir('/inscripciones?institucion_id=' . (int) $institucionId);
        }

        $this->entregarPdf(
            GeneradorCarne::hoja($this->conDatosDelConcurso($fichas, $concurso)),
            'carnes-' . $this->comoNombreDeArchivo((string) $institucion['nombre']) . '.pdf'
        );
    }

    /**
     * El listado de inscripciones no arrastra los datos del concurso —no los
     * necesita para pintar la tabla—, pero el carné sí los imprime. Se añaden
     * aquí en vez de engordar la consulta que alimenta todas las pantallas.
     *
     * @param array<int, array<string, mixed>> $fichas
     * @param array<string, mixed>|null $concurso
     * @return array<int, array<string, mixed>>
     */
    private function conDatosDelConcurso(array $fichas, ?array $concurso): array
    {
        $datos = [
            'concurso'     => (string) ($concurso['nombre'] ?? ''),
            'sede'         => (string) ($concurso['sede'] ?? ''),
            'fecha_evento' => (string) ($concurso['fecha_evento'] ?? ''),
        ];

        return array_map(static fn (array $f): array => $f + $datos, $fichas);
    }

    /**
     * Nombre de archivo legible a partir del de la institución.
     *
     * «I.E. JOSÉ MARTÍ» no puede viajar tal cual en una cabecera HTTP: los
     * puntos, los espacios y las tildes hacen que algunos navegadores corten el
     * nombre o guarden el archivo sin extensión.
     */
    private function comoNombreDeArchivo(string $nombre): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $nombre);
        $ascii = strtolower((string) ($ascii === false ? $nombre : $ascii));
        $ascii = preg_replace('/[^a-z0-9]+/', '-', $ascii) ?? '';

        return trim($ascii, '-') ?: 'delegacion';
    }

    private function entregarPdf(string $pdf, string $nombre): never
    {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Content-Length: ' . strlen($pdf));
        header('X-Content-Type-Options: nosniff');
        echo $pdf;
        exit;
    }

    /**
     * @param array<string, mixed>|null $ficha
     */
    private function mostrar(?array $ficha, string $codigo): void
    {
        if ($ficha === null) {
            $this->noEncontrado();
        }

        /*
         * Una inscripción pendiente de pago todavía no da derecho a carné: el
         * plan es claro en que el carné se emite al confirmar el pago. La vista
         * lo dice con un sello en lugar de esconderlo, para que quien escanee
         * sepa qué pasa.
         */
        echo View::renderizar('carne.publico', [
            'titulo' => 'Carné ' . $codigo,
            'ficha'  => $ficha,
            'estado' => (string) ($ficha['estado'] ?? ''),
        ], 'publico');
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
