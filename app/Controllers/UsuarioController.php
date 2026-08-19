<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Usuario;
use Core\Auth;
use Core\Controller;
use Core\Sesion;
use Core\Validador;

/**
 * Gestión de usuarios. Exclusiva del administrador (sección 7 del plan).
 *
 * Hasta el 2026-08-19 no existía: el único camino para crear una secretaria era
 * `scripts/crear_usuario.php` por consola, y **no había ninguno** para cambiar
 * una contraseña. Una credencial filtrada no se podía rotar sin entrar por SSH.
 *
 * Los usuarios no se borran, se desactivan (D-39): `inscripciones.usuario_id`,
 * `confirmado_por` y `anulado_por` apuntan aquí, y esas firmas tienen que seguir
 * resolviendo aunque la persona ya no trabaje en el concurso.
 */
final class UsuarioController extends Controller
{
    public function index(): void
    {
        Auth::exigirAdministrador();

        $this->ver('usuarios.index', [
            'titulo'   => 'Usuarios',
            'usuarios' => Usuario::todos(),
            'yo'       => (int) Auth::id(),
        ]);
    }

    public function formularioNuevo(): void
    {
        Auth::exigirAdministrador();

        $this->ver('usuarios.formulario', [
            'titulo'  => 'Nuevo usuario',
            'usuario' => null,
            'valores' => ['rol' => 'secretaria'],
            'errores' => [],
        ]);
    }

    public function formularioEditar(string $id): void
    {
        Auth::exigirAdministrador();

        $usuario = $this->usuarioOFallar((int) $id);

        $this->ver('usuarios.formulario', [
            'titulo'  => 'Editar usuario',
            'usuario' => $usuario,
            'valores' => $usuario,
            'errores' => [],
            'yo'      => (int) Auth::id(),
        ]);
    }

    /**
     * Alta y edición de los datos: nombre, correo y rol. La contraseña NO pasa
     * por aquí — tiene su propio formulario, para que guardar un cambio de
     * nombre no pueda tocarla por descuido.
     */
    public function guardar(?string $id = null): void
    {
        Auth::exigirAdministrador();
        $this->exigirCsrf();

        $idNumerico = $id === null ? null : (int) $id;
        $usuario    = $idNumerico === null ? null : $this->usuarioOFallar($idNumerico);

        $v = new Validador($_POST);
        $v->requerido('nombres', 'El nombre')->maximo('nombres', 150, 'El nombre');
        $v->requerido('correo', 'El correo')->correo('correo', 'El correo')
          ->maximo('correo', 150, 'El correo');
        $v->requerido('rol', 'El rol')->enLista('rol', ['secretaria', 'administrador'], 'El rol');

        // El alta pide contraseña; la edición no la toca.
        if ($idNumerico === null) {
            $v->requerido('password', 'La contraseña');
        }

        $correo = $v->limpio('correo');

        if ($correo !== '' && Usuario::correoExiste($correo, $idNumerico)) {
            $v->fallar('correo', 'Ya hay un usuario con ese correo.');
        }

        $password = (string) ($_POST['password'] ?? '');

        if ($idNumerico === null && $password !== '') {
            foreach ($this->erroresDePassword($password, (string) ($_POST['password2'] ?? '')) as $mensaje) {
                $v->fallar('password', $mensaje);
            }
        }

        /*
         * Nadie puede quitarse a sí mismo el rol de administrador si es el
         * último que queda activo: el sistema se quedaría sin quien gestione
         * concurso, tarifas y usuarios, y no habría forma de arreglarlo desde
         * la propia aplicación —haría falta SQL.
         */
        if (
            $usuario !== null
            && $usuario['rol'] === 'administrador'
            && $v->limpio('rol') !== 'administrador'
            && Usuario::administradoresActivos() <= 1
        ) {
            $v->fallar('rol', 'Es el único administrador activo. Nombra otro antes de cambiarle el rol.');
        }

        if ($v->tieneErrores()) {
            $this->ver('usuarios.formulario', [
                'titulo'  => $idNumerico === null ? 'Nuevo usuario' : 'Editar usuario',
                'usuario' => $usuario,
                'valores' => $_POST,
                'errores' => $v->errores(),
                'yo'      => (int) Auth::id(),
            ]);

            return;
        }

        if ($idNumerico === null) {
            $nuevoId = Usuario::crear($v->limpio('nombres'), $correo, $password, $v->limpio('rol'));
            Sesion::flash('exito', 'Usuario creado. Entrégale la contraseña en persona, no por escrito.');
            $this->redirigir('/usuarios/' . $nuevoId . '/editar');
        }

        Usuario::actualizar($idNumerico, $v->limpio('nombres'), $correo, $v->limpio('rol'));
        Sesion::flash('exito', 'Datos actualizados.');
        $this->redirigir('/usuarios/' . $idNumerico . '/editar');
    }

    /**
     * Asigna una contraseña nueva. El administrador no necesita la anterior:
     * el caso de uso es justamente que la secretaria la olvidó o se filtró.
     */
    public function cambiarPassword(string $id): void
    {
        Auth::exigirAdministrador();
        $this->exigirCsrf();

        $idNumerico = (int) $id;
        $usuario    = $this->usuarioOFallar($idNumerico);

        $password  = (string) ($_POST['password'] ?? '');
        $password2 = (string) ($_POST['password2'] ?? '');
        $errores   = $this->erroresDePassword($password, $password2);

        if ($errores !== []) {
            $this->ver('usuarios.formulario', [
                'titulo'  => 'Editar usuario',
                'usuario' => $usuario,
                'valores' => $usuario,
                'errores' => ['password' => $errores[0]],
                'yo'      => (int) Auth::id(),
            ]);

            return;
        }

        Usuario::actualizarPassword($idNumerico, $password);

        Sesion::flash(
            'exito',
            'Contraseña cambiada para ' . $usuario['nombres']
            . '. Entrégasela en persona; nadie más puede recuperarla desde aquí.'
        );
        $this->redirigir('/usuarios/' . $idNumerico . '/editar');
    }

    /**
     * Activa o desactiva. No se borra nunca: las firmas de las inscripciones
     * apuntan a estas filas.
     */
    public function cambiarEstado(string $id): void
    {
        Auth::exigirAdministrador();
        $this->exigirCsrf();

        $idNumerico = (int) $id;
        $usuario    = $this->usuarioOFallar($idNumerico);
        $activar    = ($_POST['activo'] ?? '') === '1';

        if (!$activar && $idNumerico === (int) Auth::id()) {
            Sesion::flash('error', 'No puedes desactivarte a ti mismo: te quedarías fuera del sistema.');
            $this->redirigir('/usuarios');
        }

        if (
            !$activar
            && $usuario['rol'] === 'administrador'
            && Usuario::administradoresActivos() <= 1
        ) {
            Sesion::flash('error', 'Es el único administrador activo. Nombra otro antes de desactivarlo.');
            $this->redirigir('/usuarios');
        }

        Usuario::cambiarEstado($idNumerico, $activar);

        Sesion::flash('exito', $activar
            ? $usuario['nombres'] . ' vuelve a tener acceso.'
            : $usuario['nombres'] . ' ya no puede entrar. Sus registros y firmas se conservan.');

        $this->redirigir('/usuarios');
    }

    /**
     * Reglas de la contraseña, en un solo sitio para que el alta y el cambio
     * no puedan exigir cosas distintas.
     *
     * El mínimo de 8 es el mismo que ya aplicaba `scripts/crear_usuario.php`.
     *
     * @return array<int, string>
     */
    private function erroresDePassword(string $password, string $confirmacion): array
    {
        $errores = [];

        if (mb_strlen($password) < 8) {
            $errores[] = 'La contraseña debe tener al menos 8 caracteres.';
        }

        // hash_equals y no ===: comparar en tiempo constante no cuesta nada aquí
        // y evita razonar sobre si esta comparación filtra algo.
        if (!hash_equals($password, $confirmacion)) {
            $errores[] = 'Las dos contraseñas no coinciden.';
        }

        return $errores;
    }

    /**
     * @return array<string, mixed>
     */
    private function usuarioOFallar(int $id): array
    {
        $usuario = Usuario::porId($id);

        if ($usuario === null) {
            Sesion::flash('error', 'Ese usuario no existe.');
            $this->redirigir('/usuarios');
        }

        return $usuario;
    }
}
