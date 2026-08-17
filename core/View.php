<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

/**
 * Renderizado de vistas PHP planas.
 *
 * Las vistas viven en `app/Views`. El layout envuelve el contenido y recibe
 * la variable $contenido ya renderizada.
 */
final class View
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $datos
     */
    public static function renderizar(string $vista, array $datos = [], ?string $layout = 'principal'): string
    {
        $contenido = self::capturar(self::archivo($vista), $datos);

        if ($layout === null) {
            return $contenido;
        }

        return self::capturar(
            self::archivo("layouts/{$layout}"),
            $datos + ['contenido' => $contenido]
        );
    }

    /**
     * Escapa texto para HTML. Atajo obligatorio en todas las vistas:
     * los nombres de estudiantes y colegios son datos de usuario y podrían
     * contener caracteres que rompan la página o inyecten scripts.
     */
    public static function e(mixed $valor): string
    {
        return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Construye una URL absoluta a partir de una ruta interna.
     */
    public static function url(string $ruta = '/'): string
    {
        $base = rtrim((string) Config::obtener('app.url_base', ''), '/');

        return $base . '/' . ltrim($ruta, '/');
    }

    private static function archivo(string $vista): string
    {
        $ruta = Config::ruta('app/Views/' . str_replace('.', '/', $vista) . '.php');

        if (!is_file($ruta)) {
            throw new RuntimeException("No existe la vista: {$vista}");
        }

        return $ruta;
    }

    /**
     * @param array<string, mixed> $datos
     */
    private static function capturar(string $archivo, array $datos): string
    {
        extract($datos, EXTR_SKIP);

        ob_start();

        try {
            require $archivo;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
