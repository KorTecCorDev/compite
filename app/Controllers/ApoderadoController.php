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
        $vinculados = Apoderado::estudiantesVinculados($idNumerico);

        if ($vinculados > 0) {
            Sesion::flash(
                'error',
                "No se puede eliminar: tiene {$vinculados} estudiante(s) libre(s) vinculado(s)."
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

        $dni = preg_replace('/\s/', '', (string) ($_GET['dni'] ?? '')) ?? '';

        if (preg_match('/^\d{8}$/', $dni) !== 1) {
            $this->json(['encontrado' => false, 'motivo' => 'DNI inválido'], 422);
        }

        $apoderado = Apoderado::porDni($dni);

        $this->json([
            'encontrado' => $apoderado !== null,
            'apoderado'  => $apoderado,
        ]);
    }
}
