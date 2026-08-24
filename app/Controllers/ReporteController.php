<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Concurso;
use App\Models\Inscripcion;
use App\Models\InstitucionEducativa;
use App\Models\Usuario;
use App\Servicios\GeneradorActa;
use App\Servicios\Rendicion;
use Core\Auth;
use Core\Controller;
use Core\Fecha;
use Core\Sesion;
use RuntimeException;
use ZipArchive;

/**
 * Reportes del concurso (Fase 5).
 *
 * Dos familias que no se mezclan. El **acta de los jurados** circula por las
 * mesas, se fotocopia y no lleva ni un dato de dinero (D-56). Los **reportes
 * contables** (D-59) llevan justo eso y no salen de dirección — con una
 * excepción deliberada: el arqueo propio de cada secretaria, que es el papel
 * con el que entrega lo que cobró.
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

        $this->entregarZip($libros, 'actas-cociap-2026-' . Fecha::ahora('Y-m-d') . '.zip');
    }

    /**
     * El arqueo: cuánto recibió cada quien, por medio de pago (D-59).
     *
     * **La única pantalla contable que ve la secretaria**, y solo con lo suyo:
     * es su cierre de caja, el papel con el que entrega el dinero. El
     * administrador ve las tres cajas. La regla es la de D-52 aplicada al
     * dinero, y se aplica en el controlador y no en la vista: una vista que
     * recibe filas ajenas y decide no pintarlas ya las tiene en memoria.
     */
    public function caja(): void
    {
        Auth::exigirSesion();

        $concurso   = $this->concursoOFallar();
        $concursoId = (int) $concurso['id'];

        // `null` = todas las cajas. Para la secretaria, la suya y nada más.
        $soloUsuario = Auth::esAdministrador() ? null : Auth::id();

        $this->ver('reportes.caja', [
            'titulo'       => 'Arqueo de caja',
            'columnaAncha' => true,
            'concurso'     => $concurso,
            'esPropia'     => $soloUsuario !== null,
            'filas'        => Inscripcion::arqueoPorUsuario($concursoId, $soloUsuario),
            'operaciones'  => Inscripcion::operacionesDeCobro($concursoId, $soloUsuario),
        ]);
    }

    /**
     * La rendición de cuentas del concurso (D-62).
     *
     * **Solo administrador.** Es el documento que se entrega a dirección con el
     * concurso cerrado: lleva el dinero, el padrón nominal completo y el anexo
     * de observaciones con nombre y documento de cada caso.
     *
     * Todo el trabajo lo hace `Servicios\Rendicion`, que solo lee. Ninguna de
     * las observaciones que declara se corrige en la base: el registro del
     * concurso es la prueba de lo que ocurrió.
     */
    public function rendicion(): void
    {
        Auth::exigirAdministrador();

        $this->ver('reportes.rendicion', [
            'titulo'       => 'Rendición de cuentas',
            'columnaAncha' => true,
            'r'            => Rendicion::armar($this->concursoOFallar()),
        ]);
    }

    /**
     * La grilla de cobros (D-61): todas las inscripciones, con el detalle de su
     * pago y ordenadas por cuándo se confirmó.
     *
     * **Solo administrador.** Enseña de golpe quién cobró cada inscripción y el
     * código de Yape de todas, que es lo que D-59 reservó a las filas propias
     * cuando mira una secretaria. El arqueo sigue dándole a cada una lo suyo.
     *
     * No es el listado de `/inscripciones` con más columnas: aquel es la
     * pantalla de trabajo —ordenada en nómina, con las acciones y la casilla de
     * cobro— y esta es de auditoría, ordenada por el reloj del dinero. Meterlas
     * en una sola obligaría a que un mismo listado tuviera dos órdenes y dos
     * públicos.
     */
    public function cobros(): void
    {
        Auth::exigirAdministrador();

        $concurso   = $this->concursoOFallar();
        $concursoId = (int) $concurso['id'];

        /*
         * Los filtros se leen por lista blanca. Lo que no está en
         * `FILTROS_COBROS` no llega a `condiciones()`, así que un parámetro
         * inventado en la URL no puede colarse hasta el SQL ni quedarse pegado
         * al formulario cuando se vuelve a enviar.
         */
        $filtros = [];

        foreach (Inscripcion::FILTROS_COBROS as $clave) {
            $filtros[$clave] = trim((string) ($_GET[$clave] ?? ''));
        }

        $this->ver('reportes.cobros', [
            'titulo'        => 'Cobros',
            'columnaAncha'  => true,
            'concurso'      => $concurso,
            'filtros'       => $filtros,
            'filas'         => Inscripcion::cobros($concursoId, $filtros),
            'total'         => Inscripcion::contarFiltradas($concursoId, $filtros),
            'tope'          => Inscripcion::TOPE_LISTADO,
            'instituciones' => InstitucionEducativa::listar('', null, 500),
            // Todos, no solo los activos: quien cobró en julio puede estar
            // desactivado hoy, y su nombre tiene que seguir en el desplegable o
            // sus cobros quedarían sin forma de filtrarse.
            'usuarios'      => Usuario::todos(),
        ]);
    }

    /**
     * El estado de la caja, cuadrado contra el dinero físico (D-59).
     *
     * **Solo administrador.** El panel ya enseña «Recaudado» a las dos
     * secretarias y es intencional —ellas cobran—, pero esto es otra cosa: el
     * reparto del dinero del concurso entero, con lo que hay que devolver y lo
     * que está sin asignar. Es de dirección.
     */
    public function saldos(): void
    {
        Auth::exigirAdministrador();

        $concurso   = $this->concursoOFallar();
        $concursoId = (int) $concurso['id'];

        $this->ver('reportes.saldos', [
            'titulo'        => 'Estado de la caja',
            'columnaAncha'  => true,
            'concurso'      => $concurso,
            'saldos'        => Inscripcion::saldos($concursoId),
            'sinReasignar'  => Inscripcion::cobradoSinReasignar($concursoId),
        ]);
    }

    /**
     * El fondo de devoluciones (flujo 7 del §6).
     *
     * El cálculo llevaba hecho desde la Fase 4 y **nunca tuvo pantalla**: se
     * consultaba a mano contra la base. Solo administrador, por coherencia con
     * D-51: anular —lo único que manda dinero a este fondo— ya es suyo.
     */
    public function devoluciones(): void
    {
        Auth::exigirAdministrador();

        $concurso = $this->concursoOFallar();

        $this->ver('reportes.devoluciones', [
            'titulo'       => 'Fondo de devoluciones',
            'columnaAncha' => true,
            'concurso'     => $concurso,
            'filas'        => Inscripcion::fondoDevoluciones((int) $concurso['id']),
        ]);
    }

    /**
     * El concurso vigente, o de vuelta al panel.
     *
     * Las tres pantallas contables empiezan igual y ninguna tiene sentido sin
     * concurso: sin él no hay ni tarifas ni cobros que contar.
     *
     * @return array<string, mixed>
     */
    private function concursoOFallar(): array
    {
        $concurso = Concurso::vigente();

        if ($concurso === null) {
            Sesion::flash('error', 'No hay ningún concurso registrado.');
            $this->redirigir('/panel');
        }

        return $concurso;
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
