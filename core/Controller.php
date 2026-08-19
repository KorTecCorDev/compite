<?php

declare(strict_types=1);

namespace Core;

/**
 * Controlador base: utilidades comunes a todos los controladores.
 */
abstract class Controller
{
    /**
     * Renderiza una vista dentro del layout.
     *
     * @param array<string, mixed> $datos
     */
    protected function ver(string $vista, array $datos = [], string $layout = 'principal'): void
    {
        echo View::renderizar($vista, $datos, $layout);
    }

    protected function redirigir(string $ruta): never
    {
        // Relativa a la raíz (D-43). El porqué, en Core\Url.
        header('Location: ' . Url::a($ruta));
        exit;
    }

    /**
     * Devuelve JSON. Se usa en las búsquedas incrementales (I.E., apoderados).
     *
     * @param array<string, mixed>|array<int, mixed> $datos
     */
    protected function json(array $datos, int $codigo = 200): never
    {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    /**
     * Lee un campo del POST como texto limpio.
     */
    protected function entrada(string $campo, string $porDefecto = ''): string
    {
        $valor = $_POST[$campo] ?? $porDefecto;

        return is_string($valor) ? trim($valor) : $porDefecto;
    }

    /**
     * Corta la petición si el token CSRF no acompaña al formulario.
     * Debe llamarse al inicio de TODA acción POST.
     */
    protected function exigirCsrf(): void
    {
        $token = $_POST['_csrf'] ?? null;

        if (Sesion::csrfValido(is_string($token) ? $token : null)) {
            return;
        }

        http_response_code(419);
        Sesion::flash('error', 'La sesión del formulario expiró. Vuelve a intentarlo.');
        $this->redirigir('/panel');
    }
}
