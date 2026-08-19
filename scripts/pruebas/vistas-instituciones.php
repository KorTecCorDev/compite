<?php
declare(strict_types=1);
require __DIR__ . '/_comun.php';

use App\Models\Concurso;
use App\Models\InstitucionEducativa;
use App\Models\Organizacion;
use Core\Database;
use Core\View;

$_SESSION['usuario_id'] = 1; $_SESSION['usuario_nombres'] = 'Prueba'; $_SESSION['usuario_rol'] = 'administrador';
$_SESSION['ultimo_uso'] = time();

$pdo = Database::conexion();
$pdo->beginTransaction();
try {
    $concurso = Concurso::vigente();
    $org = (int) $concurso['organizacion_id'];
    $ies = InstitucionEducativa::listar('', null, 10);
    Organizacion::marcarAnfitriona($org, (int) $ies[0]['id']);

    $html = View::renderizar('instituciones.index', [
        'titulo' => 'Instituciones', 'instituciones' => $ies,
        'busqueda' => '', 'tipo' => '', 'total' => count($ies),
        'anfitriona' => (int) $ies[0]['id'],
    ], 'principal');

    $anfitriones = substr_count($html, 'ANFITRIÓN');
    echo ($anfitriones === 1 ? "OK    " : "FALLA ") . "el listado marca exactamente 1 anfitrión (encontrados: {$anfitriones})\n";
    echo (str_contains($html, '>Gestión<') ? "OK    " : "FALLA ") . "la columna se llama Gestión\n";
    echo (!str_contains($html, 'Todos los tipos') ? "OK    " : "FALLA ") . "el filtro ya no dice «Todos los tipos»\n";

    $ficha = InstitucionEducativa::porId((int) $ies[0]['id']);
    $form = View::renderizar('instituciones.formulario', [
        'titulo' => 'Editar', 'institucion' => $ficha,
        'valores' => $ficha + ['papel' => 'anfitriona'], 'errores' => [],
    ], 'principal');

    echo (str_contains($form, 'Gestión *') ? "OK    " : "FALLA ") . "el campo se llama Gestión\n";
    echo (!str_contains($form, 'Define la tarifa') ? "OK    " : "FALLA ") . "desapareció «Define la tarifa: pública S/10…»\n";
    echo (str_contains($form, 'name="papel"') ? "OK    " : "FALLA ") . "existe el campo Papel en el concurso\n";
    echo (str_contains($form, 'value="anfitriona" selected') ? "OK    " : "FALLA ") . "viene preseleccionado como anfitriona\n";
    echo (str_contains($form, 'COCIAP') ? "OK    " : "FALLA ") . "la ayuda explica la modalidad COCIAP\n";

    $form2 = View::renderizar('instituciones.formulario', [
        'titulo' => 'Nueva', 'institucion' => null,
        'valores' => ['papel' => 'externa'], 'errores' => [],
    ], 'principal');
    echo (str_contains($form2, 'value="externa" selected') ? "OK    " : "FALLA ") . "una I.E. nueva nace como delegación externa\n";
} finally { $pdo->rollBack(); }
