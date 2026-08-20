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
        return Url::a($ruta);
    }

    /**
     * Renderiza un trozo de vista suelto, sin layout y sin datos propios.
     *
     * Lo usa el layout para imprimir el sprite de íconos (D-48) una sola vez
     * por página. Se separa de `renderizar()` porque un parcial no es una
     * pantalla: no tiene título, no recibe el contenido y nunca se envuelve.
     *
     * @param array<string, mixed> $datos
     */
    public static function parcial(string $vista, array $datos = []): string
    {
        return self::capturar(self::archivo('parciales/' . $vista), $datos);
    }

    /**
     * URL de un archivo compilado, con la marca de su última modificación.
     *
     * Existe por un fallo real en producción (D-49). La hoja se enlazaba como
     * `build/css/app.css` a secas: una dirección que **nunca cambia**. Un
     * navegador que ya tenía la versión anterior se la quedaba, y el despliegue
     * no le llegaba por mucho que el archivo estuviera bien en el servidor.
     *
     * Ahí no fue un detalle estético: sin la regla `.icono`, cada `<svg>` de la
     * columna de acciones se dibujaba a su tamaño por defecto de 300×150 px.
     *
     * La marca es `filemtime`, no un hash del contenido: cuesta una llamada al
     * sistema en vez de leer y digerir 18 KB en cada página, y el resultado es
     * el mismo —cambia cuando el archivo cambia—. Al desplegar por git, la
     * fecha del archivo es la de la copia, así que sirve igual.
     *
     * Si el archivo no está donde se espera, se devuelve la URL sin marca en
     * lugar de fallar: una página sin estilos se arregla; una página que no
     * carga, no.
     */
    public static function asset(string $ruta): string
    {
        $absoluta = Config::ruta('public/' . ltrim($ruta, '/'));
        $marca    = is_file($absoluta) ? filemtime($absoluta) : false;

        return self::url($ruta) . ($marca === false ? '' : '?v=' . $marca);
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
