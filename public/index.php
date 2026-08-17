<?php

declare(strict_types=1);

/**
 * Front controller.
 *
 * Toda petición entra por aquí. El resto del proyecto (app/, core/, config/,
 * storage/, database/) queda fuera de este directorio, de modo que el servidor
 * web nunca pueda servir directamente un archivo de configuración con
 * credenciales aunque alguien adivine su ruta.
 */

use Core\Auth;
use Core\Config;
use Core\Router;
use Core\Sesion;
use Core\View;

$raiz = dirname(__DIR__);

/*
 * Composer es el autoloader oficial. El respaldo permite arrancar el sistema
 * mientras `vendor/` no está instalado; las librerías externas seguirán
 * necesitando Composer.
 */
if (is_file($raiz . '/vendor/autoload.php')) {
    require $raiz . '/vendor/autoload.php';
} else {
    require $raiz . '/core/autoload.php';
}

date_default_timezone_set((string) Config::obtener('app.zona', 'America/Lima'));

$depurar = (bool) Config::obtener('app.depurar', false);

ini_set('display_errors', $depurar ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', Config::ruta('storage/logs/php-error.log'));
error_reporting(E_ALL);

// Cabeceras de seguridad básicas.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

Sesion::iniciar();

$router = new Router();

require Config::ruta('routes/web.php');

try {
    $router->despachar(
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        rutaSolicitada()
    );
} catch (Throwable $e) {
    error_log((string) $e);

    http_response_code(500);

    if ($depurar) {
        echo '<pre style="padding:1rem;font:14px/1.5 monospace;white-space:pre-wrap">';
        echo View::e($e->getMessage()), "\n\n", View::e($e->getTraceAsString());
        echo '</pre>';
        exit;
    }

    echo View::renderizar('errores.500', ['titulo' => 'Error'], 'limpio');
}

/**
 * Ruta interna solicitada, ya sin el subdirectorio de instalación.
 *
 * En XAMPP el proyecto vive en http://localhost/compite, así que hay que
 * descontar ese prefijo; en Hostinger, con el dominio apuntando a public/,
 * el prefijo es vacío y esta función devuelve la ruta tal cual.
 */
function rutaSolicitada(): string
{
    $uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    $base = parse_url((string) Config::obtener('app.url_base', ''), PHP_URL_PATH) ?? '';
    $base = rtrim($base, '/');

    if ($base !== '' && str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base));
    }

    // Si el dominio no apunta a public/, la URL puede incluirlo.
    if (str_starts_with($uri, '/public')) {
        $uri = substr($uri, strlen('/public'));
    }

    return $uri === '' ? '/' : $uri;
}
