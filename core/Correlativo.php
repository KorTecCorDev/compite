<?php

declare(strict_types=1);

namespace Core;

/**
 * Generador del código correlativo del participante.
 *
 * Formato (decisión D-04 del plan): `COCIAP2026-0042-K7M9X3`
 *
 *   COCIAP2026  prefijo del concurso
 *   0042        número correlativo, con relleno a 4 dígitos
 *   K7M9X3      sufijo aleatorio de 6 caracteres
 *
 * ¿Por qué el sufijo? Porque este código viaja dentro del QR del carné y la
 * vista digital del carné es de acceso abierto, sin control. Con un código
 * puramente secuencial, cualquiera que viera un solo carné podría recorrer
 * todos los demás cambiando el número — y cada carné muestra nombre completo,
 * documento y colegio de un menor de edad. El sufijo mantiene el orden que la
 * secretaría necesita y elimina la enumeración.
 */
final class Correlativo
{
    /**
     * Alfabeto sin caracteres ambiguos.
     *
     * Se excluyen I, L, O, 0, 1 y U: el código se dicta por teléfono y se
     * transcribe a mano, y esas son justamente las que se confunden entre sí.
     */
    private const ALFABETO = 'ABCDEFGHJKMNPQRSTVWXYZ23456789';

    private const LARGO_SUFIJO = 6;

    private function __construct()
    {
    }

    /**
     * Arma el código completo.
     */
    public static function generar(string $prefijoConcurso, int $numero): string
    {
        return sprintf(
            '%s-%04d-%s',
            strtoupper($prefijoConcurso),
            $numero,
            self::sufijo()
        );
    }

    /**
     * Sufijo aleatorio.
     *
     * Usa random_int(), que es criptográficamente seguro. Con rand() o
     * mt_rand() la secuencia sería predecible a partir de unos pocos códigos
     * observados, que es exactamente lo que se quiere evitar.
     */
    public static function sufijo(): string
    {
        $ultimo = strlen(self::ALFABETO) - 1;
        $salida = '';

        for ($i = 0; $i < self::LARGO_SUFIJO; $i++) {
            $salida .= self::ALFABETO[random_int(0, $ultimo)];
        }

        return $salida;
    }

    /**
     * Comprueba que una cadena tenga la forma de un código válido.
     *
     * Se usa en la vista pública del carné para descartar basura antes de
     * consultar la base.
     */
    public static function esValido(string $codigo): bool
    {
        $alfabeto = preg_quote(self::ALFABETO, '/');

        return preg_match(
            '/^[A-Z0-9]{3,20}-\d{4,}-[' . $alfabeto . ']{' . self::LARGO_SUFIJO . '}$/',
            $codigo
        ) === 1;
    }
}
