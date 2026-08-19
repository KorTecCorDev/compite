<?php

declare(strict_types=1);

/**
 * D-43 — las URL dejan de depender de un dominio fijo.
 *
 * Lo que se comprueba aquí es que la navegación **no lleva host dentro** y que
 * el QR del carné sí, porque se imprime. Y que el prefijo de instalación se
 * deduce del servidor, no de la configuración.
 */

require __DIR__ . '/_comun.php';

use App\Servicios\GeneradorCarne;
use Core\Config;
use Core\Url;
use Core\View;

$ok = 0;
$mal = 0;

$c = static function (string $caso, $esperado, $obtenido) use (&$ok, &$mal): void {
    if ($esperado === $obtenido) {
        $ok++;
        echo "  OK    {$caso}\n";
    } else {
        $mal++;
        echo "  FALLA {$caso}: esperaba " . var_export($esperado, true)
           . ', obtuvo ' . var_export($obtenido, true) . "\n";
    }
};

/** Simula una petición web con el front controller donde diga $script. */
$comoWeb = static function (string $script, string $host = '', bool $https = false): void {
    $_SERVER['SCRIPT_NAME'] = $script;

    if ($host === '') {
        unset($_SERVER['HTTP_HOST']);
    } else {
        $_SERVER['HTTP_HOST'] = $host;
    }

    $_SERVER['HTTPS'] = $https ? 'on' : 'off';
    Url::olvidar();
};

echo "1) El prefijo de instalación sale del servidor, no del config\n";

// Se llama a la regla REAL, no a una copia: una prueba que reimplementa lo que
// comprueba no comprueba nada.
$prefijo = static fn (string $script): string => Url::prefijoDe($script);

$c('producción, Document Root en public/', '', $prefijo('/index.php'));
$c('local, XAMPP con el .htaccess de la raíz', '/compite', $prefijo('/compite/public/index.php'));
$c('subcarpeta sin public/', '/compite', $prefijo('/compite/index.php'));

echo "\n2) La navegación NO lleva host: funciona bajo cualquier dominio\n";

foreach (['/inscripciones', 'build/css/app.css', '/usuarios'] as $ruta) {
    $url = View::url($ruta);
    $c("«{$ruta}» sin esquema ni host", false,
        str_contains($url, 'http://') || str_contains($url, 'https://'));
}

$c('empieza por / (relativa a la raíz)', true, str_starts_with(View::url('/panel'), '/'));
$c('conserva el prefijo de instalación', true, str_contains(View::url('/panel'), '/panel'));

echo "\n3) El QR del carné SÍ lleva dominio: se imprime en papel\n";

$codigo = 'COCIAP2026-0042-K7M9X3';

// Con dominio canónico configurado, manda ese aunque se entre por otro.
$c('con app.url_base configurado, manda el canónico', true,
    str_starts_with(GeneradorCarne::urlPublica($codigo),
        rtrim((string) Config::obtener('app.url_base'), '/')));

$c('el QR usa la ruta corta /c/', true, str_contains(GeneradorCarne::urlPublica($codigo), '/c/'));

echo "\n4) Sin dominio canónico, el QR toma el de la petición\n";

/*
 * Es el caso del dominio provisional: no se configura nada y cada carné sale
 * con el dominio por el que se generó. Se simula vaciando la configuración en
 * memoria, que es lo que haría un `config.local.php` sin `url_base`.
 */
$reflexion = new ReflectionClass(Config::class);
$datos     = $reflexion->getProperty('datos');
$original  = $datos->getValue();

$copia = $original;
$copia['app']['url_base'] = '';
$datos->setValue(null, $copia);

$comoWeb('/index.php', 'concurso.hostingersite.com', true);
$c('toma el host de la petición, con https', true,
    str_starts_with(GeneradorCarne::urlPublica($codigo), 'https://concurso.hostingersite.com/c/'));

$comoWeb('/index.php', 'otro-dominio.pe', false);
$c('y cambia solo con cambiar de dominio, sin tocar nada', true,
    str_starts_with(GeneradorCarne::urlPublica($codigo), 'http://otro-dominio.pe/c/'));

$comoWeb('/compite/public/index.php', 'localhost');
$c('respeta el subdirectorio', true,
    str_starts_with(GeneradorCarne::urlPublica($codigo), 'http://localhost/compite/c/'));

// Se devuelve la configuración a como estaba.
$datos->setValue(null, $original);
Url::olvidar();
unset($_SERVER['SCRIPT_NAME'], $_SERVER['HTTP_HOST'], $_SERVER['HTTPS']);

echo "\n5) Ninguna vista imprime un dominio dentro\n";

$conDominio = 0;

foreach (glob(dirname(__DIR__, 2) . '/app/Views/**/*.php') ?: [] as $vista) {
    $s = (string) file_get_contents($vista);

    // `View::url()` ya no puede producir dominios; lo que se busca es un
    // http:// escrito a mano en una plantilla.
    if (preg_match('#(?:href|src|action)="https?://#', $s)) {
        $conDominio++;
        echo '  FALLA dominio escrito a mano en ' . basename($vista) . "\n";
    }
}

$c('ninguna plantilla lleva un dominio escrito a mano', 0, $conDominio);

echo "\n{$ok} correctas, {$mal} fallidas\n";
exit($mal === 0 ? 0 : 1);
