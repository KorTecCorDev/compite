<?php

declare(strict_types=1);

namespace Core;

/**
 * Manejo de sesión y token CSRF.
 *
 * Se usan las sesiones nativas de PHP, tal como decide el plan (sección 2),
 * pero con las cookies endurecidas: HttpOnly para que JavaScript no pueda
 * leerlas, SameSite=Lax para que un sitio externo no pueda disparar POSTs
 * autenticados, y Secure automático cuando el sitio corre en HTTPS.
 */
final class Sesion
{
    private function __construct()
    {
    }

    public static function iniciar(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443);

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $https,
        ]);

        session_name((string) Config::obtener('sesion.nombre', 'COCIAP_SES'));
        session_start();

        self::vencerPorInactividad();
    }

    public static function poner(string $clave, mixed $valor): void
    {
        $_SESSION[$clave] = $valor;
    }

    public static function obtener(string $clave, mixed $porDefecto = null): mixed
    {
        return $_SESSION[$clave] ?? $porDefecto;
    }

    public static function quitar(string $clave): void
    {
        unset($_SESSION[$clave]);
    }

    public static function destruir(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }

        session_destroy();
    }

    /**
     * Mensaje de un solo uso (se muestra una vez y desaparece).
     */
    public static function flash(string $tipo, string $mensaje): void
    {
        $_SESSION['_flash'][$tipo][] = $mensaje;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function tomarFlash(): array
    {
        $mensajes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        return $mensajes;
    }

    // ------------------------------------------------------------------
    // CSRF
    // ------------------------------------------------------------------

    /**
     * Token que acompaña a cada formulario POST. Sin él, un sitio externo
     * podría hacer que el navegador de la secretaria envíe una anulación
     * o una confirmación de pago sin que ella lo sepa.
     */
    public static function tokenCsrf(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf'];
    }

    public static function csrfValido(?string $token): bool
    {
        $esperado = $_SESSION['_csrf'] ?? '';

        return $token !== null
            && $esperado !== ''
            && hash_equals($esperado, $token);
    }

    /**
     * Cierra la sesión tras N minutos sin actividad.
     */
    private static function vencerPorInactividad(): void
    {
        $minutos = (int) Config::obtener('sesion.inactividad_min', 120);

        if ($minutos <= 0) {
            return;
        }

        $ultimo = $_SESSION['_ultima_actividad'] ?? null;

        if ($ultimo !== null && (time() - (int) $ultimo) > ($minutos * 60)) {
            self::destruir();
            self::iniciar();
            self::flash('aviso', 'Tu sesión se cerró por inactividad. Ingresa de nuevo.');
        }

        $_SESSION['_ultima_actividad'] = time();
    }
}
