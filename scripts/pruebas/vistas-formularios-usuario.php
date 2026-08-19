<?php
declare(strict_types=1);
require __DIR__ . '/_comun.php';
use App\Models\Usuario; use Core\View;
iniciarSesionComo('administrador');
$_SESSION['ultimo_uso'] = time();

$editar = View::renderizar('usuarios.formulario', [
    'titulo' => 'Editar', 'usuario' => Usuario::porId(idAdministrador()), 'valores' => Usuario::porId(idAdministrador()),
    'errores' => [], 'yo' => idAdministrador(),
], 'principal');

preg_match_all('/<form[^>]*action="([^"]*)"/', $editar, $m);
$deUsuarios = array_values(array_filter($m[1], static fn (string $a): bool => str_contains($a, '/usuarios')));
echo (count($deUsuarios) === 2 ? "OK    " : "FALLA ")
    . "la edicion trae DOS formularios de usuarios: " . implode(' | ', $deUsuarios) . "\n";

// Anidamiento: es el fallo que ya apago la caja de cobro una vez (D-29).
preg_match_all('#</?form\b#', $editar, $t);
$prof = 0; $max = 0;
foreach ($t[0] as $tok) { $prof += str_starts_with($tok, '</') ? -1 : 1; $max = max($max, $prof); }
echo ($max === 1 && $prof === 0 ? "OK    " : "FALLA ")
    . "ningun formulario anidado (profundidad max {$max}, cierre {$prof})\n";
