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
    if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
        $comando = 'powershell -NoProfile -Command '
            . '"$p = Read-Host -AsSecureString; '
            . '[Runtime.InteropServices.Marshal]::PtrToStringAuto('
            . '[Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))"';

        $salida = shell_exec($comando);

        if (is_string($salida)) {
            return rtrim($salida, "\r\n");
        }

        echo "\n[aviso] No se pudo ocultar la entrada; la contraseña será visible.\n";
    } else {
        shell_exec('stty -echo');
        $entrada = fgets(STDIN);
        shell_exec('stty echo');

        if (is_string($entrada)) {
            return rtrim($entrada, "\r\n");
        }
    }

    $entrada = fgets(STDIN);

    return is_string($entrada) ? rtrim($entrada, "\r\n") : '';
}
