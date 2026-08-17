<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

/**
 * Router mínimo.
 *
 * Soporta segmentos dinámicos con llaves: '/carne/{codigo}'.
 * El valor capturado se pasa como argumento al método del controlador.
 *
 * Se mantiene deliberadamente simple para que la migración posterior a
 * Laravel (prevista en el plan) sea una traducción casi literal.
 */
final class Router
{
    /** @var array<string, array<int, array{patron: string, destino: mixed, parametros: array<int, string>}>> */
    private array $rutas = [
        'GET'  => [],
        'POST' => [],
    ];

    /** @var callable|null */
    private $manejadorNoEncontrado = null;

    public function get(string $ruta, mixed $destino): void
    {
        $this->registrar('GET', $ruta, $destino);
    }

    public function post(string $ruta, mixed $destino): void
    {
        $this->registrar('POST', $ruta, $destino);
    }

    public function noEncontrado(callable $manejador): void
    {
        $this->manejadorNoEncontrado = $manejador;
    }

    private function registrar(string $metodo, string $ruta, mixed $destino): void
    {
        $ruta = '/' . trim($ruta, '/');
        $parametros = [];

        // '/carne/{codigo}' se convierte en '#^/carne/([^/]+)$#'
        $patron = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            static function (array $coincidencia) use (&$parametros): string {
                $parametros[] = $coincidencia[1];
                return '([^/]+)';
            },
            $ruta
        );

        $this->rutas[$metodo][] = [
            'patron'     => '#^' . $patron . '$#',
            'destino'    => $destino,
            'parametros' => $parametros,
        ];
    }

    public function despachar(string $metodo, string $uri): void
    {
        $metodo = strtoupper($metodo);
        $ruta   = '/' . trim(parse_url($uri, PHP_URL_PATH) ?? '/', '/');

        foreach ($this->rutas[$metodo] ?? [] as $registro) {
            if (preg_match($registro['patron'], $ruta, $coincidencias) !== 1) {
                continue;
            }

            array_shift($coincidencias); // descarta la coincidencia completa

            $this->invocar($registro['destino'], $coincidencias);
            return;
        }

        $this->responderNoEncontrado();
    }

    /**
     * @param array<int, string> $argumentos
     */
    private function invocar(mixed $destino, array $argumentos): void
    {
        if (is_callable($destino)) {
            $destino(...$argumentos);
            return;
        }

        if (!is_array($destino) || count($destino) !== 2) {
            throw new RuntimeException('Destino de ruta inválido.');
        }

        [$clase, $metodo] = $destino;

        if (!class_exists($clase)) {
            throw new RuntimeException("No existe el controlador {$clase}");
        }

        $controlador = new $clase();

        if (!method_exists($controlador, $metodo)) {
            throw new RuntimeException("No existe el método {$clase}::{$metodo}");
        }

        $controlador->{$metodo}(...$argumentos);
    }

    private function responderNoEncontrado(): void
    {
        http_response_code(404);

        if ($this->manejadorNoEncontrado !== null) {
            ($this->manejadorNoEncontrado)();
            return;
        }

        echo 'Página no encontrada.';
    }
}
