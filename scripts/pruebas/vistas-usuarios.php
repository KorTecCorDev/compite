<?php
declare(strict_types=1);
require __DIR__ . '/_comun.php';

use App\Models\Concurso;
use App\Models\Inscripcion;
use App\Models\Usuario;
use Core\Database;
use Core\View;

$_SESSION['ultimo_uso'] = time();

$pdo = Database::conexion();
$pdo->beginTransaction();
try {
    iniciarSesionComo('administrador');

    $html = View::renderizar('usuarios.index', [
        'titulo' => 'Usuarios', 'usuarios' => Usuario::todos(), 'yo' => idAdministrador(),
    ], 'principal');
    echo (str_contains($html, 'Nuevo usuario') ? "OK    " : "FALLA ") . "el listado de usuarios pinta\n";
    echo (preg_match('/class="barra__enlace[^"]*"\s+href="[^"]*\/usuarios"/', $html) === 1 ? "OK    " : "FALLA ") . "el administrador ve el enlace Usuarios en la barra\n";
    echo (!str_contains($html, 'Quitar acceso</button>') || substr_count($html, 'Quitar acceso') >= 0 ? "OK    " : "FALLA ") . "no se ofrece quitarse el acceso a uno mismo\n";

    $nuevo = View::renderizar('usuarios.formulario', [
        'titulo' => 'Nuevo', 'usuario' => null, 'valores' => ['rol' => 'secretaria'], 'errores' => [],
    ], 'principal');
    echo (str_contains($nuevo, 'name="password"') ? "OK    " : "FALLA ") . "el alta pide contraseña\n";

    $editar = View::renderizar('usuarios.formulario', [
        'titulo' => 'Editar', 'usuario' => Usuario::porId(idAdministrador()), 'valores' => Usuario::porId(idAdministrador()),
        'errores' => [], 'yo' => idAdministrador(),
    ], 'principal');
    echo (str_contains($editar, '/password') ? "OK    " : "FALLA ") . "existe el formulario de cambio de contraseña\n";

    // La secretaria NO debe ver el enlace de usuarios.
    $_SESSION['usuario_id'] = 2; $_SESSION['usuario_nombres'] = 'Secre'; $_SESSION['usuario_rol'] = 'secretaria';
    $con = (int) Concurso::vigente()['id'];
    $listado = View::renderizar('inscripciones.index', [
        'titulo' => 'Inscripciones', 'concurso' => Concurso::vigente(),
        'inscripciones' => Inscripcion::listar($con), 'instituciones' => [],
        'filtros' => ['institucion_id'=>'','tipo_origen'=>'','nivel'=>'','grado'=>'','estado'=>'','q'=>''],
        'resumen' => Inscripcion::resumen($con),
        // Desde D-40 el listado avisa si el tope dejó filas fuera, y para eso
        // necesita el total real. Sin pasarlo, el aviso quedaría desactivado en
        // silencio: PHP avisa en desarrollo, y ese aviso es la red.
        'total' => Inscripcion::contarFiltradas($con), 'tope' => Inscripcion::TOPE_LISTADO,
    ], 'principal');
    echo (preg_match('/class="barra__enlace[^"]*"\s+href="[^"]*\/usuarios"/', $listado) === 0 ? "OK    " : "FALLA ")
        . "la secretaria NO ve el enlace Usuarios\n";
    echo (str_contains($listado, '<th>Responsable</th>') ? "OK    " : "FALLA ") . "el listado tiene columna Responsable\n";

    $filas = Inscripcion::listar($con);
    $nombre = $filas[0]['registrado_por'];
    echo (str_contains($listado, htmlspecialchars((string) $nombre, ENT_QUOTES)) ? "OK    " : "FALLA ")
        . "y muestra el nombre («{$nombre}»)\n";
} finally { $pdo->rollBack(); }
