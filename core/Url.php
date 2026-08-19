<?php

declare(strict_types=1);

namespace Core;

/**
 * Todas las URL del sistema salen de aquí.
 *
 * Antes salían de `app.url_base`, un solo valor que hacía **dos trabajos**: el
 * prefijo de instalación —`/compite` en local, vacío en producción— y el host.
 * Al estar fundidos, cambiar de dominio obligaba a reescribir el valor del que
 * también dependía el prefijo, y se rompía todo a la vez: los `<link>` del CSS,
 * cada enlace y cada redirección apuntaban al dominio anterior.
 *
 * Y se rompía **en silencio**. Si el dominio viejo seguía vivo, la hoja de
 * estilos cargaba desde allí y la pantalla se veía perfecta; solo al pulsar un
 * enlace salías del sitio nuevo hacia el viejo, contra otra base de datos.
 *
 * Aquí quedan separados, y cada tipo de URL usa lo mínimo que necesita:
 *
 *   · `a()`         — enlaces, assets y redirecciones. **Relativos a la raíz**,
 *                     sin esquema ni host. Funcionan bajo cualquier dominio sin
 *                     tocar configuración, que es justamente lo que se buscaba.
 *   · `absoluta()`  — solo el QR del carné, que se imprime en papel y tiene que
 *                     llevar el dominio dentro para que una cámara lo abra.
 *
 * Efecto secundario que importa: al no usar el host en la navegación, la
 * cabecera `Host` —que la controla quien hace la petición— deja de poder decidir
 * a dónde apuntan nuestros enlaces y nuestras redirecciones. Esa era la razón
 * documentada para no derivar nada en producción, y desaparece al no derivar
 * ningún host.
 */
final class Url
{
    private static ?string $base = null;

    private function __construct()
    {
    }

    /**
     * Prefijo de instalación: `/compite` en local, cadena vacía en producción.
     *
     * Se deduce del propio front controller y no de la configuración, porque es
     * el servidor quien sabe dónde está montado el sitio:
     *
     *   · Producción, con el Document Root en `public/`:
     *     SCRIPT_NAME = `/index.php` → prefijo ``
     *   · Local, con XAMPP sirviendo `htdocs` y el .htaccess reescribiendo:
     *     SCRIPT_NAME = `/compite/public/index.php` → prefijo `/compite`
     *
     * El `/public` final se recorta porque forma parte de la ruta interna, no
     * de la URL pública: nadie escribe `/compite/public/inscripciones`.
     */
    public static function base(): string
    {
        if (self::$base !== null) {
            return self::$base;
        }

        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');

        /*
         * El `SCRIPT_NAME` de una petición web **siempre empieza por barra**
         * (`/index.php`, `/compite/public/index.php`). Desde la consola es otra
         * cosa —la ruta relativa del script, o «Standard input code»— y de ahí
         * no se deduce ningún prefijo público.
         *
         * Se comprueba la forma y no `PHP_SAPI`, porque así la derivación es la
         * misma función en los dos casos y se puede probar de verdad: una prueba
         * pone el SCRIPT_NAME que quiere comprobar y obtiene lo que obtendría el
         * servidor. Con `PHP_SAPI` esa rama era inalcanzable desde las pruebas.
         */
        if (!str_starts_with($script, '/')) {
            $canonica   = (string) Config::obtener('app.url_base', '');
            self::$base = rtrim((string) (parse_url($canonica, PHP_URL_PATH) ?: ''), '/');

            return self::$base;
        }

        self::$base = self::prefijoDe($script);

        return self::$base;
    }

    /**
     * El prefijo público que corresponde a un front controller dado.
     *
     * Pública y sin estado a propósito: es la regla que decide dónde cree el
     * sistema que está montado, y tiene que poder comprobarse con los valores
     * exactos que manda cada servidor.
     */
    public static function prefijoDe(string $script): string
    {
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');

        // `/public` es parte de la ruta interna, no de la URL pública: nadie
        // escribe `/compite/public/inscripciones`.
        if (str_ends_with($dir, '/public')) {
            $dir = substr($dir, 0, -strlen('/public'));
        }

        return $dir === '/' ? '' : $dir;
    }

    /**
     * URL para un enlace, un asset o una redirección.
     *
     * Relativa a la raíz a propósito. Una cabecera `Location` relativa es válida
     * —RFC 7231— y la entienden todos los navegadores; en un `href` o un `src`
     * es lo normal.
     */
    public static function a(string $ruta = '/'): string
    {
        return self::base() . '/' . ltrim($ruta, '/');
    }

    /**
     * URL absoluta, con esquema y host. **Solo para lo que se imprime.**
     *
     * `app.url_base` manda si está configurado: es la forma de fijar un dominio
     * canónico para los QR aunque se entre por otro. Si está vacío, se deriva
     * del dominio por el que se está generando el carné, que es lo razonable
     * cuando el dominio es provisional y va a cambiar.
     *
     * Ojo con lo que esto NO arregla: un QR ya impreso lleva el dominio de
     * cuando se imprimió. Si el dominio cambia después, ese papel apunta a donde
     * ya no estás, y no hay configuración que lo deshaga. La red de seguridad no
     * es técnica: la puerta busca por código tecleado en `/control`.
     */
    public static function absoluta(string $ruta = '/'): string
    {
        $canonica = rtrim((string) Config::obtener('app.url_base', ''), '/');

        if ($canonica !== '') {
            return $canonica . '/' . ltrim($ruta, '/');
        }

        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');

        if ($host === '') {
            // Sin petición y sin dominio canónico no hay nada de donde sacarlo.
            // Se devuelve la ruta tal cual: quien la imprima sabrá que le falta.
            return self::a($ruta);
        }

        $https   = (string) ($_SERVER['HTTPS'] ?? '');
        $esquema = ($https !== '' && $https !== 'off') ? 'https' : 'http';

        return $esquema . '://' . $host . self::a($ruta);
    }

    /**
     * Solo para las pruebas: olvida el prefijo memorizado.
     */
    public static function olvidar(): void
    {
        self::$base = null;
    }
}
