<?php

declare(strict_types=1);

namespace App\Controllers;

use Core\Auth;
use Core\Controller;
use Core\Sesion;

final class AuthController extends Controller
{
    public function mostrarLogin(): void
    {
        if (Auth::autenticado()) {
            $this->redirigir('/panel');
        }

        $this->ver('auth.login', ['titulo' => 'Ingresar'], 'limpio');
    }

    public function procesarLogin(): void
    {
        $this->exigirCsrf();

        $correo   = $this->entrada('correo');
        $password = $_POST['password'] ?? '';

        if ($correo === '' || !is_string($password) || $password === '') {
            Sesion::flash('error', 'Ingresa tu correo y tu contraseña.');
            $this->redirigir('/login');
        }

        if (!Auth::intentar($correo, $password)) {
            /*
             * Mensaje deliberadamente genérico: no se distingue entre
             * "ese correo no existe" y "la contraseña está mal". Decirlo
             * revelaría qué cuentas existen en el sistema.
             */
            Sesion::flash('error', 'Las credenciales no son correctas.');
            $this->redirigir('/login');
        }

        $this->redirigir('/panel');
    }

    public function salir(): void
    {
        $this->exigirCsrf();

        Auth::salir();
        Sesion::iniciar();
        Sesion::flash('exito', 'Cerraste sesión correctamente.');

        $this->redirigir('/login');
    }
}
