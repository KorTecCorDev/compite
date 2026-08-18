<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Concurso;
use App\Models\Inscripcion;
use Core\Auth;
use Core\Controller;
use Core\View;

/**
 * Control de ingreso del día del concurso.
 *
 * Existe porque el carné impreso no puede ser la única llave. Con estudiantes
 * de primaria y secundaria, que alguno pierda el papel entre su casa y la
 * puerta no es un riesgo: es una certeza estadística. El carné acelera la
 * fila; esta pantalla es la que resuelve los casos que el papel no cubre.
 *
 * Está pensada para usarse de pie, con una sola mano y prisa: un campo, una
 * lista, y el estado de cada inscripción visible sin tener que leer nada más.
 */
final class ControlController extends Controller
{
    /**
     * Cuántos resultados tiene sentido mostrar en una mesa de ingreso.
     *
     * Si la búsqueda devuelve más que esto, el problema no se arregla haciendo
     * scroll: se arregla escribiendo un apellido más completo. Se avisa en vez
     * de pintar doscientas filas que nadie va a leer.
     */
    private const MAXIMO = 25;

    public function index(): void
    {
        Auth::exigirSesion();

        $concurso   = Concurso::vigente();
        $concursoId = (int) ($concurso['id'] ?? 0);

        $termino = trim((string) ($_GET['q'] ?? ''));

        /*
         * Con menos de dos caracteres no se busca. Una sola letra devuelve
         * media base y no ayuda a nadie; además evita que el sistema trabaje
         * de más cada vez que alguien roza el teclado.
         */
        $resultados = mb_strlen($termino) >= 2
            ? Inscripcion::listar($concursoId, ['q' => $termino])
            : [];

        $total = count($resultados);

        echo View::renderizar('control.index', [
            'titulo'     => 'Control de ingreso',
            'concurso'   => $concurso,
            'termino'    => $termino,
            'resultados' => array_slice($resultados, 0, self::MAXIMO),
            'total'      => $total,
            'recortado'  => $total > self::MAXIMO,
        ]);
    }
}
