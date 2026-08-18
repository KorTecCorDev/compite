<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Apoderado;
use Core\Auth;
use Core\Controller;
use Core\Sesion;
use Core\Validador;

final class ApoderadoController extends Controller
{
    public function index(): void
    {
        Auth::exigirSesion();

        $busqueda = trim((string) ($_GET['q'] ?? ''));

        $this->ver('apoderados.index', [
            'titulo'     => 'Apoderados',
            'apoderados' => Apoderado::listar($busqueda),
            'busqueda'   => $busqueda,
            'total'      => Apoderado::total(),
        ]);
    }

    public function formularioNuevo(): void
    {
        Auth::exigirSesion();

        $this->ver('apoderados.formulario', [
            'titulo'    => 'Nuevo apoderado',
            'apoderado' => null,
            'valores'   => [],
            'errores'   => [],
        ]);
    }

    public function formularioEditar(string $id): void
    {
        Auth::exigirSesion();

        $apoderado = Apoderado::porId((int) $id);

        if ($apoderado === null) {
            Sesion::flash('error', 'Ese apoderado no existe.');
            $this->redirigir('/apoderados');
        }

        $this->ver('apoderados.formulario', [
            'titulo'    => 'Editar apoderado',
            'apoderado' => $apoderado,
            'valores'   => $apoderado,
            'errores'   => [],
        ]);
    }

    public function guardar(?string $id = null): void
    {
        Auth::exigirSesion();
        $this->exigirCsrf();

        $idNumerico = $id === null ? null : (int) $id;

        $v = new Validador($_POST);
        $v->requerido('dni', 'El DNI')->dni('dni', 'El DNI');
        $v->requerido('ap_paterno', 'El apellido paterno')->maximo('ap_paterno', 100, 'El apellido paterno');
        $v->requerido('ap_materno', 'El apellido materno')->maximo('ap_materno', 100, 'El apellido materno');
        $v->requerido('nombres', 'Los nombres')->maximo('nombres', 150, 'Los nombres');
        $v->requerido('celular', 'El celular')->celular('celular', 'El celular');

        /*
         * Correo opcional aquí y obligatorio en la ficha de la I.E.: es la misma
         * persona y la misma columna, pero al docente delegado se le escribe
         * para coordinar a su delegación y al apoderado de un estudiante libre
         * no. Si viene, se valida el formato: un correo a medio escribir es peor
         * que uno vacío, porque parece un canal de contacto y no lo es.
         */
        $v->correo('correo', 'El correo');

        /*
         * El DNI es UNIQUE en apoderados: identifica a la persona, y esa
         * unicidad es justamente lo que permite reutilizar un apoderado entre
         * hermanos. Se comprueba antes de insertar para dar un mensaje claro
         * en vez de dejar que estalle la restricción de la base.
         */
        if (!$v->tieneErrores() && Apoderado::dniExiste($v->limpio('dni'), $idNumerico)) {
            $existente = Apoderado::porDni($v->limpio('dni'));
            $v->fallar(
                'dni',
                'Ya existe un apoderado con ese DNI: '
                . trim(($existente['ap_paterno'] ?? '') . ' ' . ($existente['ap_materno'] ?? '') . ', ' . ($existente['nombres'] ?? ''))
                . '. Reutilízalo en lugar de crear uno nuevo.'
            );
        }

        if ($v->tieneErrores()) {
            $this->ver('apoderados.formulario', [
                'titulo'    => $idNumerico === null ? 'Nuevo apoderado' : 'Editar apoderado',
                'apoderado' => $idNumerico === null ? null : Apoderado::porId($idNumerico),
                'valores'   => $_POST,
                'errores'   => $v->errores(),
            ]);

            return;
        }

        $datos = [
            'dni'        => $v->limpio('dni'),
            'ap_paterno' => $v->limpio('ap_paterno'),
            'ap_materno' => $v->limpio('ap_materno'),
            'nombres'    => $v->limpio('nombres'),
            'celular'    => $v->limpio('celular'),

            /*
             * Va siempre, incluso vacío, para que desde aquí SÍ se pueda borrar
             * un correo equivocado. Esta pantalla existe para editar a esta
             * persona; el formulario de inscripción libre, que hace otra cosa,
             * solo lo manda cuando trae valor (ver InscripcionController).
             */
            'correo'     => $v->limpioONulo('correo'),
        ];

        if ($idNumerico === null) {
            $nuevoId = Apoderado::crear($datos);
            Sesion::flash('exito', 'Apoderado registrado.');
        } else {
            Apoderado::actualizar($idNumerico, $datos);
            $nuevoId = $idNumerico;
            Sesion::flash('exito', 'Datos actualizados.');
        }

        $this->redirigir('/apoderados/' . $nuevoId . '/editar');
    }

    public function eliminar(string $id): void
    {
        Auth::exigirAdministrador();
        $this->exigirCsrf();

        $idNumerico = (int) $id;

        /*
         * Dos motivos distintos para no poder borrarlo, y conviene decir cuál
         * es. El segundo apareció con D-28: un apoderado puede ser el docente
         * delegado de un colegio, y la clave foránea lo impediría igual, pero
         * la secretaria vería un fallo de integridad de MySQL en vez de una
         * explicación.
         */
        $delegaciones = Apoderado::delegacionesQueEncabeza($idNumerico);

        if ($delegaciones !== []) {
            $nombres = implode(', ', array_column($delegaciones, 'nombre'));
            Sesion::flash(
                'error',
                "No se puede eliminar: es el docente delegado de {$nombres}. "
                . 'Asigna otro encargado a esa delegación primero.'
            );
            $this->redirigir('/apoderados');
        }

        $vinculados = Apoderado::estudiantesVinculados($idNumerico);

        if ($vinculados > 0) {
            Sesion::flash(
                'error',
                "No se puede eliminar: tiene {$vinculados} participante(s) vinculado(s)."
            );
            $this->redirigir('/apoderados');
        }

        Apoderado::eliminar($idNumerico);
        Sesion::flash('exito', 'Apoderado eliminado.');

        $this->redirigir('/apoderados');
    }

    /**
     * Búsqueda por DNI en JSON. La usa el formulario de inscripción libre
     * (Fase 3) para reutilizar un apoderado ya registrado — el caso de los
     * hermanos — en vez de duplicarlo.
     */
    public function buscarPorDniJson(): void
    {
        Auth::exigirSesion();

        $documento = mb_strtoupper(preg_replace('/[\s\-]/', '', (string) ($_GET['dni'] ?? '')) ?? '');

        /*
         * Mismo criterio que Validador::dni(): DNI de 8 dígitos O carné de
         * extranjería de 9 a 12 caracteres. Antes esta ruta exigía `^\d{8}$`,
         * más estricto que la regla con la que se registran los apoderados: un
         * apoderado dado de alta con carné de extranjería quedaba imposible de
         * encontrar desde el formulario de inscripción, y se duplicaba cada vez
         * que volvía a inscribir a otro hijo.
         */
        $esDni = preg_match('/^\d{8}$/', $documento) === 1;
        $esCe  = preg_match('/^[A-Z0-9]{9,12}$/', $documento) === 1;

        if (!$esDni && !$esCe) {
            $this->json(['encontrado' => false, 'motivo' => 'Documento inválido'], 422);
        }

        $apoderado = Apoderado::porDni($documento);

        $this->json([
            'encontrado' => $apoderado !== null,
            'apoderado'  => $apoderado,
        ]);
    }
}
