<?php

declare(strict_types=1);

/**
 * Crea un usuario del sistema desde la línea de comandos.
 *
 * Las contraseñas nunca se escriben en un .sql: tienen que pasar por
 * password_hash() de PHP. Por eso el primer administrador se crea aquí y
 * no en el seed.
 *
 * Uso — en Hostinger, por SSH, que es donde se crea el PRIMER administrador:
 *   php scripts/crear_usuario.php "Nombre Apellido" correo@dominio.pe administrador
 *
 * En local (Windows), con el PHP de XAMPP y no el del PATH:
 *   C:\xampp\php\php.exe scripts/crear_usuario.php "Nombre Apellido" correo@dominio.pe administrador
 *
 * A partir del primer administrador, el resto de usuarios y todos los cambios de
 * contraseña se hacen desde `/usuarios`, sin volver a la consola.
 *
 * La contraseña se pide por teclado, para que no quede en el historial de
 * la consola.
 */

if (PHP_SAPI !== 'cli') {
    exit("Este script solo se ejecuta desde la consola.\n");
}

require __DIR__ . '/../core/autoload.php';

use App\Models\Usuario;
use Core\Database;

$nombres = $argv[1] ?? null;
$correo  = $argv[2] ?? null;
$rol     = $argv[3] ?? null;

if ($nombres === null || $correo === null || $rol === null) {
    exit(
        "Uso: php scripts/crear_usuario.php \"Nombres Apellidos\" correo@dominio.pe [secretaria|administrador]\n"
    );
}

if (!in_array($rol, ['secretaria', 'administrador'], true)) {
    exit("El rol debe ser 'secretaria' o 'administrador'.\n");
}

if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    exit("El correo no tiene un formato válido.\n");
}

try {
    Database::conexion();
} catch (Throwable $e) {
    exit("No se pudo conectar a la base de datos: " . $e->getMessage() . "\n");
}

if (Usuario::correoExiste($correo)) {
    exit("Ya existe un usuario con el correo {$correo}.\n");
}

echo "Contraseña para {$correo}: ";
$password = leerPasswordOculta();
echo PHP_EOL;

if (mb_strlen($password) < 8) {
    exit("La contraseña debe tener al menos 8 caracteres.\n");
}

echo "Repite la contraseña: ";
$confirmacion = leerPasswordOculta();
echo PHP_EOL;

if (!hash_equals($password, $confirmacion)) {
    exit("Las contraseñas no coinciden.\n");
}

$id = Usuario::crear($nombres, $correo, $password, $rol);

echo "Usuario creado (id {$id}): {$nombres} <{$correo}> como {$rol}." . PHP_EOL;

/**
 * Lee una contraseña sin mostrarla en pantalla.
 *
 * En Windows no existe `stty -echo`, así que se recurre a PowerShell para
 * capturar la entrada oculta. Si algo falla, se degrada a lectura visible
 * avisando al usuario, en vez de quedarse colgado.
 */
function leerPasswordOculta(): string
{
    /*
     * `shell_exec` puede no existir, y en hosting compartido normalmente NO
     * existe: Hostinger y compañía lo deshabilitan por `disable_functions`.
     *
     * Antes se llamaba sin comprobarlo, y el resultado era el peor posible: el
     * script imprimía «Contraseña para …:» y **terminaba en silencio**, sin
     * leer nada y sin decir por qué. La contraseña que el operador escribía a
     * continuación se la quedaba el shell, que intentaba ejecutarla como un
     * comando. Pasó en el despliegue del 19 de agosto.
     *
     * Ahora se comprueba antes de usarlo y, si no se puede ocultar la entrada,
     * se avisa y se lee igualmente. Una contraseña visible en la pantalla de
     * tu propia sesión SSH es un problema mucho menor que no poder crear el
     * primer administrador.
     */
    $puedeEjecutar = function_exists('shell_exec')
        && !in_array('shell_exec', array_map(
            static fn (string $f): string => trim(strtolower($f)),
            explode(',', (string) ini_get('disable_functions'))
        ), true);

    if ($puedeEjecutar && stripos(PHP_OS_FAMILY, 'Windows') !== false) {
        $comando = 'powershell -NoProfile -Command '
            . '"$p = Read-Host -AsSecureString; '
            . '[Runtime.InteropServices.Marshal]::PtrToStringAuto('
            . '[Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))"';

        $salida = @shell_exec($comando);

        if (is_string($salida) && trim($salida) !== '') {
            return rtrim($salida, "\r\n");
        }
    } elseif ($puedeEjecutar) {
        @shell_exec('stty -echo 2>/dev/null');
        $entrada = fgets(STDIN);
        @shell_exec('stty echo 2>/dev/null');

        if (is_string($entrada)) {
            return rtrim($entrada, "\r\n");
        }
    }

    if (!$puedeEjecutar) {
        echo PHP_EOL
           . '[aviso] Este servidor no permite ocultar la escritura (shell_exec está' . PHP_EOL
           . '        deshabilitado). La contraseña se verá mientras la escribes.' . PHP_EOL
           . '        Escríbela y pulsa Enter: ';
    }

    $entrada = fgets(STDIN);

    /*
     * `false` aquí significa que no hay entrada que leer —sin terminal, o la
     * entrada se agotó—. Se dice en voz alta en vez de devolver una cadena
     * vacía que luego fallaría por «contraseña demasiado corta», que es un
     * mensaje que manda a buscar el problema donde no está.
     */
    if ($entrada === false) {
        exit(PHP_EOL . 'No se pudo leer la contraseña: no hay entrada por teclado.' . PHP_EOL
            . 'Ejecuta este script tú mismo en una consola, sin pegarlo junto a otros' . PHP_EOL
            . 'comandos: la línea siguiente se consumiría como si fuera la contraseña.' . PHP_EOL);
    }

    return rtrim($entrada, "\r\n");
}
