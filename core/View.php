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
     *
     * Usa la base de navegación, no la canónica, para que en local el sistema
     * responda por cualquier host con el que se lo alcance.
     *
     * El caso que lo motiva es probar desde un celular en la red local
     * (http://192.168.x.x/compite): con la base fija, todos los enlaces
     * apuntarían a `localhost` y en el teléfono no abriría ninguno.
     *
     * Para BrowserSync no hace falta —su proxy ya reescribe los enlaces del
     * HTML por su cuenta—, pero tampoco estorba: al proxear envía
     * `Host: localhost`, así que aquí se obtiene la misma base de siempre.
     */
    public static function url(string $ruta = '/'): string
    {
        return self::baseNavegacion() . '/' . ltrim($ruta, '/');
    }

    /**
     * Base para enlaces y assets de la petición en curso.
     *
     * IMPORTANTE: esta base es efímera y NO debe guardarse en la base de datos
     * ni imprimirse en nada. Para eso está `app.url_base`, que es la URL
     * canónica y la que usa App\Servicios\GeneradorCarne para el QR del carné:
     * un QR impreso apuntando a localhost:3000 sería irrecuperable.
     *
     * Solo se deriva del request en entorno local. En producción se devuelve
     * `app.url_base` tal cual, porque la cabecera Host la controla el cliente
     * y no debe decidir a dónde apuntan nuestros enlaces.
     */
    private static function baseNavegacion(): string
    {
        $canonica = rtrim((string) Config::obtener('app.url_base', ''), '/');

        if (Config::obtener('app.entorno', 'produccion') !== 'local') {
            return $canonica;
        }

        $host = $_SERVER['HTTP_HOST'] ?? '';

        if ($host === '') {
            return $canonica;
        }

        $esquema = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

        // Se conserva el subdirectorio de instalación (/compite) de la URL
        // canónica; lo único que cambia es el esquema y el host:puerto.
        $prefijo = rtrim(parse_url($canonica, PHP_URL_PATH) ?? '', '/');

        return $esquema . '://' . $host . $prefijo;
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
