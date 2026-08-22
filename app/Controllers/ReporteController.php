<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Concurso;
use App\Models\Inscripcion;
use App\Servicios\GeneradorActa;
use Core\Auth;
use Core\Controller;
use Core\Sesion;
use RuntimeException;
use ZipArchive;

/**
 * Reportes del concurso (Fase 5).
 *
 * De momento uno solo: el acta de los jurados. El reporte administrativo —con
 * montos y los filtros combinables del §8— es el siguiente y vive aparte a
 * propósito: uno circula por las mesas y no lleva dinero, el otro es de
 * dirección y no sale de ahí.
 */
final class ReporteController extends Controller
{
    /**
     * Las actas de evaluación: un ZIP con un libro por bolsa de competencia.
     *
     * **Solo administrador**, por decisión del propietario: es el documento
     * oficial del concurso y sale de una sola mano.
     *
     * Se genera al vuelo en cada descarga, como el carné (D-24): así refleja
     * los cobros del momento sin que nadie tenga que acordarse de regenerar
     * nada. El día del concurso importa, porque hay inscripción en la puerta y
     * al acta solo entra quien ya pagó.
     */
    public function acta(): void
    {
        Auth::exigirAdministrador();

        $concurso = Concurso::vigente();

        if ($concurso === null) {
            Sesion::flash('error', 'No hay ningún concurso registrado.');
            $this->redirigir('/panel');
        }

        $concursoId = (int) $concurso['id'];
        $inscritos  = Inscripcion::paraActa($concursoId);

        if ($inscritos === []) {
            Sesion::flash(
                'error',
                'Todavía no hay ninguna inscripción confirmada: las actas saldrían vacías. '
                . 'Al acta solo entra quien ya pagó.'
            );
            $this->redirigir('/inscripciones');
        }

        $libros = GeneradorActa::libros(
            $concurso,
            Concurso::categorias($concursoId),
            $inscritos
        );

        $this->entregarZip($libros, 'actas-cociap-2026-' . date('Y-m-d') . '.zip');
    }

    /**
     * Empaqueta los libros y los entrega como una sola descarga.
     *
     * **Este es el único sitio del sistema que escribe en disco para servir un
     * archivo**, y es una limitación de `ZipArchive`, que trabaja sobre una
     * ruta y no sobre memoria. Se mitiga como corresponde: el archivo vive en
     * el temporal del sistema, con nombre irrepetible, y se borra en `finally`
     * —también si la escritura revienta a mitad—, así que no queda basura ni
     * aunque falle. La alternativa, escribir el ZIP a mano byte a byte, sería
     * mucho más código propio para ahorrar un archivo temporal.
     *
     * `ext-zip` ya es requisito del proyecto en `composer.json`: PhpSpreadsheet
     * lo necesita para escribir cualquier `.xlsx`, así que no añade dependencia.
     *
     * @param array<string, string> $libros nombre de archivo => bytes
     */
    private function entregarZip(array $libros, string $nombre): never
    {
        $ruta = sys_get_temp_dir() . '/actas-' . bin2hex(random_bytes(8)) . '.zip';

        try {
            $zip = new ZipArchive();

            if ($zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('No se pudo crear el archivo comprimido de las actas.');
            }

            foreach ($libros as $archivo => $bytes) {
                $zip->addFromString($archivo, $bytes);
            }

            $zip->close();

            /*
             * Se leen los bytes y se sale del try ANTES de entregar. Entregar
             * termina con `exit`, y `exit` **no ejecuta los bloques finally**:
             * si la descarga se hiciera aquí dentro, el archivo temporal se
             * quedaría en el disco del servidor en cada descarga.
             */
            $bytes = (string) file_get_contents($ruta);
        } finally {
            if (is_file($ruta)) {
                unlink($ruta);
            }
        }

        $this->entregar($bytes, $nombre, 'application/zip');
    }

    /**
     * Entrega bytes como descarga.
     *
     * Mismo patrón que `CarneController::entregarPdf()`: `nosniff` evita que el
     * navegador se invente el tipo.
     */
    private function entregar(string $bytes, string $nombre, string $tipo): never
    {
        header('Content-Type: ' . $tipo);
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Content-Length: ' . strlen($bytes));
        header('X-Content-Type-Options: nosniff');
        echo $bytes;
        exit;
    }
}
