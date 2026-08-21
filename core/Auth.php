<?php

declare(strict_types=1);

namespace Core;

use App\Models\Usuario;

/**
 * Autenticación y autorización por rol.
 *
 * Roles del plan (sección 7): 'secretaria' y 'administrador'.
 * El administrador puede todo lo que puede la secretaria, más la gestión
 * de Concurso, Categorías, Tarifas, Usuarios y Organización.
 */
final class Auth
{
    private function __construct()
    {
    }

    /**
     * Verifica credenciales y abre sesión. Devuelve false si no son válidas.
     */
    public static function intentar(string $correo, string $password): bool
    {
        $usuario = Usuario::porCorreo($correo);

        /*
         * Se ejecuta password_verify incluso cuando el usuario no existe,
         * contra un hash ficticio. Así el tiempo de respuesta es parecido
         * en ambos casos y no se puede deducir qué correos están registrados
         * midiendo cuánto tarda el login.
         */
        $hash = $usuario['password_hash']
            ?? '$2y$12$0000000000000000000000000000000000000000000000000000u';

        $coincide = password_verify($password, $hash);

        if ($usuario === null || !$coincide) {
            return false;
        }

        if (!$usuario['activo']) {
            return false;
        }

        // Rehash si el costo del algoritmo cambió desde que se creó la cuenta.
        if (password_needs_rehash($usuario['password_hash'], PASSWORD_DEFAULT)) {
            Usuario::actualizarPassword((int) $usuario['id'], $password);
        }

        // Evita fijación de sesión: el id de sesión cambia al autenticarse.
        session_regenerate_id(true);

        Sesion::poner('usuario_id', (int) $usuario['id']);
        Sesion::poner('usuario_nombres', $usuario['nombres']);
        Sesion::poner('usuario_rol', $usuario['rol']);

        return true;
    }

    public static function salir(): void
    {
        Sesion::destruir();
    }

    public static function autenticado(): bool
    {
        return Sesion::obtener('usuario_id') !== null;
    }

    public static function id(): ?int
    {
        $id = Sesion::obtener('usuario_id');

        return $id === null ? null : (int) $id;
    }

    public static function nombres(): string
    {
        return (string) Sesion::obtener('usuario_nombres', '');
    }

    public static function rol(): ?string
    {
        $rol = Sesion::obtener('usuario_rol');

        return $rol === null ? null : (string) $rol;
    }

    public static function esAdministrador(): bool
    {
        return self::rol() === 'administrador';
    }

    /**
     * ¿Puede el usuario de la sesión ACTUAR sobre un registro ajeno? (D-52)
     *
     * La regla es de escritura, no de lectura: todo el mundo sigue viendo el
     * concurso entero —la mesa de la puerta necesita encontrar a cualquier
     * estudiante, la hoja A4 de una delegación necesita a los treinta y el
     * aviso de documento repetido necesita mirar la base completa—, pero
     * corregir o reinscribir queda en manos de quien registró la fila.
     *
     * El administrador queda por encima: es quien tiene que poder desatascar
     * cualquier registro cuando la secretaria que lo hizo no está delante.
     * A la inversa NO: lo que registró el administrador es suyo, y una
     * secretaria no lo toca (decisión del propietario, 2026-08-21).
     *
     * `null` responde `false` para todo el que no sea administrador: un
     * registro sin dueño conocido no es de nadie en particular.
     */
    public static function puedeOperar(?int $duenoId): bool
    {
        if (self::esAdministrador()) {
            return true;
        }

        return $duenoId !== null && $duenoId === self::id();
    }

    /**
     * Exige sesión activa. Si no la hay, manda al login y corta la ejecución.
     */
    public static function exigirSesion(): void
    {
        if (self::autenticado()) {
            return;
        }

        Sesion::flash('aviso', 'Necesitas iniciar sesión.');
        self::redirigir('/login');
    }

    /**
     * Exige rol administrador. Usarlo en las acciones de la sección 7 que
     * la secretaria NO puede realizar.
     */
    public static function exigirAdministrador(): void
    {
        self::exigirSesion();

        if (self::esAdministrador()) {
            return;
        }

        http_response_code(403);
        Sesion::flash('error', 'Esa sección es exclusiva del administrador.');
        self::redirigir('/panel');
    }

    private static function redirigir(string $ruta): never
    {
        // Relativa a la raíz (D-43): la redirección te deja en el dominio por el
        // que entraste, sea cual sea, y no en uno escrito a mano en la
        // configuración. Una cabecera `Location` relativa es válida y universal.
        header('Location: ' . Url::a($ruta));
        exit;
    }
}
