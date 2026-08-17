<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

/**
 * Acceso centralizado a la configuración.
 *
 * Carga `config/config.php` y, si existe, fusiona encima
 * `config/config.local.php`. Así las credenciales reales de cada entorno
 * viven fuera del archivo versionado.
 */
final class Config
{
    /** @var array<string, mixed>|null */
    private static ?array $datos = null;

    private function __construct()
    {
    }

    /**
     * Devuelve un valor de configuración usando notación de punto.
     *
     * Ejemplo: Config::obtener('db.host')
     */
    public static function obtener(string $clave, mixed $porDefecto = null): mixed
    {
        self::cargar();

        $valor = self::$datos;

        foreach (explode('.', $clave) as $segmento) {
            if (!is_array($valor) || !array_key_exists($segmento, $valor)) {
                return $porDefecto;
            }
            $valor = $valor[$segmento];
        }

        return $valor;
    }

    /**
     * Ruta absoluta a la raíz del proyecto, sin barra final.
     */
    public static function raiz(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Ruta absoluta a partir de una ruta relativa a la raíz del proyecto.
     */
    public static function ruta(string $relativa = ''): string
    {
        $relativa = trim($relativa, '/\\');

        return $relativa === ''
            ? self::raiz()
            : self::raiz() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativa);
    }

    private static function cargar(): void
    {
        if (self::$datos !== null) {
            return;
        }

        $base = self::raiz() . '/config/config.php';

        if (!is_file($base)) {
            throw new RuntimeException('No se encontró config/config.php');
        }

        $datos = require $base;

        if (!is_array($datos)) {
            throw new RuntimeException('config/config.php debe devolver un array.');
        }

        $local = self::raiz() . '/config/config.local.php';

        if (is_file($local)) {
            $sobrescritura = require $local;

            if (is_array($sobrescritura)) {
                $datos = self::fusionar($datos, $sobrescritura);
            }
        }

        self::$datos = $datos;
    }

    /**
     * Fusión recursiva: config.local.php solo necesita declarar lo que cambia.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $encima
     * @return array<string, mixed>
     */
    private static function fusionar(array $base, array $encima): array
    {
        foreach ($encima as $clave => $valor) {
            if (is_array($valor) && isset($base[$clave]) && is_array($base[$clave])) {
                $base[$clave] = self::fusionar($base[$clave], $valor);
                continue;
            }

            $base[$clave] = $valor;
        }

        return $base;
    }
}
