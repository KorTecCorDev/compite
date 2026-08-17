<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\InstitucionEducativa;
use Core\Auth;
use Core\Controller;
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

            'dd_ap_paterno' => $validador->limpioONulo('dd_ap_paterno'),
            'dd_ap_materno' => $validador->limpioONulo('dd_ap_materno'),
            'dd_nombres'    => $validador->limpioONulo('dd_nombres'),
            'dd_celular'    => $validador->limpioONulo('dd_celular'),
            'dd_correo'     => $validador->limpioONulo('dd_correo'),
            'dd_dni'        => $validador->limpioONulo('dd_dni'),

            'di_ap_paterno' => $validador->limpioONulo('di_ap_paterno'),
            'di_ap_materno' => $validador->limpioONulo('di_ap_materno'),
            'di_nombres'    => $validador->limpioONulo('di_nombres'),
            'di_celular'    => $validador->limpioONulo('di_celular'),
            'di_correo'     => $validador->limpioONulo('di_correo'),
            'di_dni'        => $validador->limpioONulo('di_dni'),
        ];

        if ($idNumerico === null) {
            $nuevoId = InstitucionEducativa::crear($datos);
            Sesion::flash('exito', 'Institución educativa registrada.');
        } else {
            InstitucionEducativa::actualizar($idNumerico, $datos);
            $nuevoId = $idNumerico;
            Sesion::flash('exito', 'Datos actualizados.');
        }

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

        // Docente delegado — obligatorio (salvo el DNI).
        $v->requerido('dd_ap_paterno', 'El apellido paterno del docente delegado');
        $v->requerido('dd_ap_materno', 'El apellido materno del docente delegado');
        $v->requerido('dd_nombres', 'Los nombres del docente delegado');
        $v->requerido('dd_celular', 'El celular del docente delegado')
          ->celular('dd_celular', 'El celular del docente delegado');
        $v->requerido('dd_correo', 'El correo del docente delegado')
          ->correo('dd_correo', 'El correo del docente delegado');
        $v->dni('dd_dni', 'El documento del docente delegado');

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
