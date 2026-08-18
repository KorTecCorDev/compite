<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Apoderado;
use App\Models\InstitucionEducativa;
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
        ]);
    }

    public function formularioNueva(): void
    {
        Auth::exigirSesion();

        $this->ver('instituciones.formulario', [
            'titulo'      => 'Nueva Institución Educativa',
            'institucion' => null,
            'valores'     => [],
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
            'valores'     => $institucion,
            'errores'     => [],
        ]);
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
        $nuevoId = Database::transaccion(
            static function () use ($encargado, $datos, $idNumerico): int {
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

                if ($idNumerico === null) {
                    return InstitucionEducativa::crear($datos);
                }

                InstitucionEducativa::actualizar($idNumerico, $datos);

                return $idNumerico;
            }
        );

        Sesion::flash('exito', $idNumerico === null
            ? 'Institución educativa registrada.'
            : 'Datos actualizados.');

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
        $v->requerido('tipo', 'El tipo de institución')
          ->enLista('tipo', ['publica', 'privada'], 'El tipo de institución');
        $v->requerido('direccion', 'La dirección')->maximo('direccion', 250, 'La dirección');

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
