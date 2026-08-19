<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Apoderado;
use App\Models\Concurso;
use App\Models\InstitucionEducativa;
use App\Models\Organizacion;
use Core\Auth;
use Core\Controller;
use Core\Database;
use Core\Sesion;
use Core\Validador;

final class InstitucionController extends Controller
{
    public function index(): void
    {
        Auth::exigirSesion();

        $busqueda = trim((string) ($_GET['q'] ?? ''));
        $tipo     = (string) ($_GET['tipo'] ?? '');

        $this->ver('instituciones.index', [
            'titulo'        => 'Instituciones Educativas',
            'instituciones' => InstitucionEducativa::listar($busqueda, $tipo !== '' ? $tipo : null),
            'busqueda'      => $busqueda,
            'tipo'          => $tipo,
            'total'         => InstitucionEducativa::total(),
            // Para pintarle la píldora ANFITRIÓN en vez de la de gestión (D-37).
            'anfitriona'    => $this->institucionAnfitriona(),
        ]);
    }

    public function formularioNueva(): void
    {
        Auth::exigirSesion();

        $this->ver('instituciones.formulario', [
            'titulo'      => 'Nueva Institución Educativa',
            'institucion' => null,
            'valores'     => ['papel' => 'externa'],
            'errores'     => [],
        ]);
    }

    public function formularioEditar(string $id): void
    {
        Auth::exigirSesion();

        $institucion = InstitucionEducativa::porId((int) $id);

        if ($institucion === null) {
            Sesion::flash('error', 'Esa institución educativa no existe.');
            $this->redirigir('/instituciones');
        }

        $this->ver('instituciones.formulario', [
            'titulo'      => 'Editar Institución Educativa',
            'institucion' => $institucion,
            'valores'     => $institucion + [
                'papel' => $this->institucionAnfitriona() === (int) $id ? 'anfitriona' : 'externa',
            ],
            'errores'     => [],
        ]);
    }

    /**
     * El colegio marcado como anfitrión del concurso vigente, si lo hay (D-37).
     */
    private function institucionAnfitriona(): ?int
    {
        $concurso = Concurso::vigente();

        return $concurso === null
            ? null
            : Organizacion::institucionAnfitriona((int) $concurso['organizacion_id']);
    }

    public function guardar(?string $id = null): void
    {
        Auth::exigirSesion();
        $this->exigirCsrf();

        $idNumerico = $id === null ? null : (int) $id;
        $validador  = $this->validar();

        if ($validador->tieneErrores()) {
            $this->ver('instituciones.formulario', [
                'titulo'      => $idNumerico === null ? 'Nueva Institución Educativa' : 'Editar Institución Educativa',
                'institucion' => $idNumerico === null ? null : InstitucionEducativa::porId($idNumerico),
                'valores'     => $_POST,
                'errores'     => $validador->errores(),
            ]);

            return;
        }

        $datos = [
            'nombre'        => $validador->limpio('nombre'),
            'distrito'      => $validador->limpio('distrito'),
            'provincia'     => $validador->limpio('provincia'),
            'departamento'  => $validador->limpio('departamento'),
            'tipo'          => $validador->limpio('tipo'),
            'direccion'     => $validador->limpioONulo('direccion'),

            'di_ap_paterno' => $validador->limpioONulo('di_ap_paterno'),
            'di_ap_materno' => $validador->limpioONulo('di_ap_materno'),
            'di_nombres'    => $validador->limpioONulo('di_nombres'),
            'di_celular'    => $validador->limpioONulo('di_celular'),
            'di_correo'     => $validador->limpioONulo('di_correo'),
            'di_dni'        => $validador->limpioONulo('di_dni'),
        ];

        $encargado = [
            'dni'        => $validador->limpio('dd_dni'),
            'ap_paterno' => $validador->limpio('dd_ap_paterno'),
            'ap_materno' => $validador->limpio('dd_ap_materno'),
            'nombres'    => $validador->limpio('dd_nombres'),
            'celular'    => $validador->limpio('dd_celular'),
            /*
             * Va siempre, aun vacío: esta pantalla es la que mantiene al
             * encargado, así que desde aquí sí se puede borrarle un correo
             * equivocado. El formulario de inscripción libre, que está haciendo
             * otra cosa, solo lo manda cuando trae valor.
             */
            'correo'     => $validador->limpioONulo('dd_correo'),
        ];

        /*
         * El encargado y la I.E. se guardan juntos o no se guarda ninguno
         * (D-28). Sin transacción, un fallo entre medias dejaría un apoderado
         * suelto sin colegio, o —peor— una I.E. apuntando a un encargado que la
         * siguiente sentencia no llegó a crear.
         */
        /*
         * El papel en el concurso no se guarda en la I.E. sino en la
         * organización (D-37): «esta organización ES este colegio». Se resuelve
         * aquí, fuera de la transacción, porque leer el concurso vigente no
         * modifica nada.
         */
        $concurso        = Concurso::vigente();
        $organizacionId  = $concurso === null ? null : (int) $concurso['organizacion_id'];
        $seraAnfitriona  = $validador->limpio('papel') === 'anfitriona';
        $anfitrionaAntes = $this->institucionAnfitriona();

        $nuevoId = Database::transaccion(
            static function () use (
                $encargado,
                $datos,
                $idNumerico,
                $organizacionId,
                $seraAnfitriona,
                $anfitrionaAntes
            ): int {
                /*
                 * Si el documento ya existe, es la misma persona: se reutiliza
                 * su ficha y se actualizan sus datos. Es el caso del docente que
                 * encabeza la delegación año tras año, y también el del que ya
                 * estaba registrado por haber inscrito a su propio hijo como
                 * estudiante libre.
                 */
                $existente = Apoderado::porDni($encargado['dni']);

                if ($existente === null) {
                    $encargadoId = Apoderado::crear($encargado);
                } else {
                    $encargadoId = (int) $existente['id'];
                    Apoderado::actualizar($encargadoId, $encargado);
                }

                $datos['docente_delegado_id'] = $encargadoId;

                $ieId = $idNumerico === null
                    ? InstitucionEducativa::crear($datos)
                    : $idNumerico;

                if ($idNumerico !== null) {
                    InstitucionEducativa::actualizar($idNumerico, $datos);
                }

                /*
                 * La marca de anfitriona va DENTRO de la transacción: si el
                 * colegio se guarda y la marca no, el sistema cobraría a sus
                 * estudiantes como pública sin que nadie se entere.
                 *
                 * Se desmarca solo si el anfitrión que había era ESTE colegio.
                 * Sin esa condición, editar un colegio externo cualquiera —que
                 * llega con papel «externa»— le quitaría la marca al anfitrión
                 * de verdad.
                 */
                if ($organizacionId !== null) {
                    if ($seraAnfitriona) {
                        Organizacion::marcarAnfitriona($organizacionId, $ieId);
                    } elseif ($anfitrionaAntes === $ieId) {
                        Organizacion::marcarAnfitriona($organizacionId, null);
                    }
                }

                return $ieId;
            }
        );

        $aviso = $idNumerico === null
            ? 'Institución educativa registrada.'
            : 'Datos actualizados.';

        /*
         * Se avisa del traslado de la marca porque es un efecto sobre OTRA ficha
         * que quien guarda no está mirando: solo puede haber un anfitrión, así
         * que marcar este colegio desmarcó al anterior.
         */
        if ($seraAnfitriona && $anfitrionaAntes !== null && $anfitrionaAntes !== $nuevoId) {
            $previa  = InstitucionEducativa::porId($anfitrionaAntes);
            $aviso  .= ' Ahora es la institución anfitriona; dejó de serlo «'
                     . ($previa['nombre'] ?? 'la anterior') . '».';
        } elseif ($seraAnfitriona) {
            $aviso .= ' Queda marcada como institución anfitriona: sus estudiantes'
                    . ' pagan la tarifa COCIAP y compiten en su propia bolsa.';
        }

        Sesion::flash('exito', $aviso);

        $this->redirigir('/instituciones/' . $nuevoId . '/editar');
    }

    /**
     * Elimina una I.E. solo si no tiene participantes asociados.
     * Es acción de administrador: borrar del catálogo global afecta a todas
     * las organizaciones que lo comparten.
     */
    public function eliminar(string $id): void
    {
        Auth::exigirAdministrador();
        $this->exigirCsrf();

        $idNumerico = (int) $id;
        $asociados  = InstitucionEducativa::participantesAsociados($idNumerico);

        if ($asociados > 0) {
            Sesion::flash(
                'error',
                "No se puede eliminar: hay {$asociados} participante(s) inscritos con esta institución."
            );
            $this->redirigir('/instituciones');
        }

        InstitucionEducativa::eliminar($idNumerico);
        Sesion::flash('exito', 'Institución educativa eliminada.');

        $this->redirigir('/instituciones');
    }

    /**
     * Buscador incremental, en JSON. Lo consume el formulario de inscripción
     * de la Fase 3 y el aviso de duplicados de esta misma fase.
     */
    public function buscarJson(): void
    {
        Auth::exigirSesion();

        $termino = trim((string) ($_GET['q'] ?? ''));

        if (mb_strlen($termino) < 2) {
            $this->json(['resultados' => []]);
        }

        $this->json([
            'resultados' => InstitucionEducativa::posiblesDuplicados($termino),
        ]);
    }

    /**
     * Reglas de validación.
     *
     * Decisión D-09 (revisada por el propietario el 2026-08-16): los datos del
     * **docente delegado son obligatorios** porque es quien realmente gestiona
     * la inscripción y siempre está presente; los del **director son
     * opcionales**, porque suelen conseguirse después y no deben bloquear el
     * registro de la delegación.
     *
     * Los campos opcionales igual se validan en formato si vienen llenos: un
     * correo a medio escribir es peor que un correo vacío.
     */
    private function validar(): Validador
    {
        $v = new Validador($_POST);

        $v->requerido('nombre', 'El nombre de la I.E.')->maximo('nombre', 200, 'El nombre');
        $v->requerido('distrito', 'El distrito')->maximo('distrito', 100, 'El distrito');
        $v->requerido('provincia', 'La provincia')->maximo('provincia', 100, 'La provincia');
        $v->requerido('departamento', 'El departamento')->maximo('departamento', 100, 'El departamento');
        $v->requerido('tipo', 'La gestión de la I.E.')
          ->enLista('tipo', ['publica', 'privada'], 'La gestión de la I.E.');
        $v->requerido('direccion', 'La dirección')->maximo('direccion', 250, 'La dirección');

        /*
         * Papel en el concurso (D-37). Va explícito y no como una casilla que se
         * puede pasar por alto: si el colegio anfitrión se registra sin marcar,
         * sus estudiantes se cobran como cualquier pública y compiten en la
         * bolsa equivocada, sin ningún aviso. Un campo que hay que responder no
         * se olvida; una casilla desmarcada, sí.
         */
        $v->requerido('papel', 'El papel en el concurso')
          ->enLista('papel', ['externa', 'anfitriona'], 'El papel en el concurso');

        /*
         * Docente delegado — obligatorio, **incluido el DNI desde D-28**. Antes
         * era el único campo opcional del bloque. Dejó de poder serlo cuando el
         * docente delegado pasó a ser el apoderado de su delegación: el DNI es
         * lo único que permite reconocer a la persona y no duplicarla, y la
         * columna `apoderados.dni` es NOT NULL UNIQUE.
         */
        $v->requerido('dd_ap_paterno', 'El apellido paterno del docente delegado');
        $v->requerido('dd_ap_materno', 'El apellido materno del docente delegado');
        $v->requerido('dd_nombres', 'Los nombres del docente delegado');
        $v->requerido('dd_celular', 'El celular del docente delegado')
          ->celular('dd_celular', 'El celular del docente delegado');
        // Correo opcional, igual que para el apoderado de un estudiante libre:
        // es la misma persona y el mismo campo, y no hay razón para exigírselo
        // a uno y no al otro. Si viene, se valida el formato.
        $v->correo('dd_correo', 'El correo del docente delegado');
        $v->requerido('dd_dni', 'El documento del docente delegado')
          ->dni('dd_dni', 'El documento del docente delegado');

        // Director — opcional, pero con formato válido si se llena.
        $v->maximo('di_ap_paterno', 100, 'El apellido paterno del director');
        $v->maximo('di_ap_materno', 100, 'El apellido materno del director');
        $v->maximo('di_nombres', 150, 'Los nombres del director');
        $v->celular('di_celular', 'El celular del director');
        $v->correo('di_correo', 'El correo del director');
        $v->dni('di_dni', 'El documento del director');

        return $v;
    }
}
