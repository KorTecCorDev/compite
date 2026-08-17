<?php

declare(strict_types=1);

namespace Core;

/**
 * Validación de formularios en el servidor.
 *
 * El plan (Fase 6) es explícito: nunca confiar solo en el frontend. Un
 * `required` en el HTML lo desactiva cualquiera con las herramientas del
 * navegador; esta clase es la que realmente decide si un dato entra.
 *
 * Acumula todos los errores en lugar de cortar en el primero, para que la
 * secretaria vea de una sola vez todo lo que le falta corregir y no tenga
 * que enviar el formulario diez veces.
 */
final class Validador
{
    /** @var array<string, string> campo => primer mensaje de error */
    private array $errores = [];

    /** @var array<string, string> campo => valor ya limpiado */
    private array $limpios = [];

    /**
     * @param array<string, mixed> $datos normalmente $_POST
     */
    public function __construct(private array $datos)
    {
    }

    /**
     * Valor recortado de un campo, tal como llegó.
     */
    public function valor(string $campo): string
    {
        $bruto = $this->datos[$campo] ?? '';

        return is_string($bruto) ? trim($bruto) : '';
    }

    public function requerido(string $campo, string $etiqueta): self
    {
        if ($this->valor($campo) === '') {
            return $this->fallar($campo, "{$etiqueta} es obligatorio.");
        }

        return $this->aceptar($campo);
    }

    public function opcional(string $campo): self
    {
        return $this->aceptar($campo);
    }

    public function maximo(string $campo, int $largo, string $etiqueta): self
    {
        $valor = $this->valor($campo);

        if ($valor !== '' && mb_strlen($valor) > $largo) {
            return $this->fallar($campo, "{$etiqueta} no puede pasar de {$largo} caracteres.");
        }

        return $this;
    }

    /**
     * Correo electrónico. Vacío se acepta si el campo no era obligatorio.
     */
    public function correo(string $campo, string $etiqueta): self
    {
        $valor = $this->valor($campo);

        if ($valor !== '' && !filter_var($valor, FILTER_VALIDATE_EMAIL)) {
            return $this->fallar($campo, "{$etiqueta} no tiene un formato válido.");
        }

        return $this;
    }

    /**
     * Celular peruano: 9 dígitos. Se aceptan espacios y guiones al escribir,
     * pero se guarda solo el número.
     */
    public function celular(string $campo, string $etiqueta): self
    {
        $valor = preg_replace('/[\s\-()]/', '', $this->valor($campo)) ?? '';

        if ($valor === '') {
            return $this;
        }

        if (preg_match('/^9\d{8}$/', $valor) !== 1) {
            return $this->fallar($campo, "{$etiqueta} debe ser un celular de 9 dígitos que empiece en 9.");
        }

        $this->limpios[$campo] = $valor;

        return $this;
    }

    /**
     * Documento de identidad.
     *
     * Acepta dos formatos (decisión D-10 del plan):
     *   - DNI peruano: exactamente 8 dígitos. Es el caso normal.
     *   - Carné de extranjería: 9 a 12 caracteres alfanuméricos, para el
     *     participante extranjero ocasional.
     *
     * Se valida el formato en lugar de aceptar cualquier cosa, porque un DNI
     * mal digitado que nadie detecta termina en un carné con el documento
     * equivocado y en un descargo que no cuadra.
     */
    public function dni(string $campo, string $etiqueta): self
    {
        $valor = preg_replace('/[\s\-]/', '', $this->valor($campo)) ?? '';

        if ($valor === '') {
            return $this;
        }

        $esDni = preg_match('/^\d{8}$/', $valor) === 1;
        $esCe  = preg_match('/^[A-Za-z0-9]{9,12}$/', $valor) === 1;

        if (!$esDni && !$esCe) {
            return $this->fallar(
                $campo,
                "{$etiqueta} debe ser un DNI de 8 dígitos o un carné de extranjería de 9 a 12 caracteres."
            );
        }

        $this->limpios[$campo] = mb_strtoupper($valor);

        return $this;
    }

    /**
     * El valor debe estar dentro de una lista cerrada. Protege los ENUM de la
     * base: si llega algo fuera de la lista, con modo estricto MySQL lo
     * rechazaría con un error feo; aquí se convierte en un mensaje claro.
     *
     * @param array<int, string> $permitidos
     */
    public function enLista(string $campo, array $permitidos, string $etiqueta): self
    {
        $valor = $this->valor($campo);

        if ($valor !== '' && !in_array($valor, $permitidos, true)) {
            return $this->fallar($campo, "{$etiqueta} tiene un valor no permitido.");
        }

        return $this;
    }

    public function fallar(string $campo, string $mensaje): self
    {
        // Se conserva el primer error de cada campo: es el más específico.
        $this->errores[$campo] ??= $mensaje;

        return $this;
    }

    public function tieneErrores(): bool
    {
        return $this->errores !== [];
    }

    /**
     * @return array<string, string>
     */
    public function errores(): array
    {
        return $this->errores;
    }

    /**
     * Mensajes en una sola lista, para mostrarlos juntos.
     *
     * @return array<int, string>
     */
    public function mensajes(): array
    {
        return array_values($this->errores);
    }

    /**
     * Valor listo para guardar: usa la versión limpia si la hubo.
     */
    public function limpio(string $campo): string
    {
        return $this->limpios[$campo] ?? $this->valor($campo);
    }

    /**
     * Igual que limpio(), pero devuelve null cuando está vacío. Para columnas
     * que aceptan NULL, donde guardar '' en vez de NULL enturbia los reportes.
     */
    public function limpioONulo(string $campo): ?string
    {
        $valor = $this->limpio($campo);

        return $valor === '' ? null : $valor;
    }

    private function aceptar(string $campo): self
    {
        $this->limpios[$campo] ??= $this->valor($campo);

        return $this;
    }
}
