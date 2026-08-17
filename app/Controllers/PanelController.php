<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Concurso;
use App\Models\Inscripcion;
use Core\Auth;
use Core\Controller;

final class PanelController extends Controller
{
    public function index(): void
    {
        Auth::exigirSesion();

        $concurso = Concurso::vigente();

        $resumen = [
            'pendientes'  => 0,
            'confirmadas' => 0,
            'anuladas'    => 0,
            'recaudado'   => 0.0,
            'por_cobrar'  => 0.0,
        ];

        if ($concurso !== null) {
            $resumen = Inscripcion::resumen((int) $concurso['id']);
        }

        $this->ver('panel.index', [
            'titulo'   => 'Panel',
            'concurso' => $concurso,
            'resumen'  => $resumen,
        ]);
    }
}
