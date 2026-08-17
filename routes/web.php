<?php

declare(strict_types=1);

/**
 * Definición de rutas.
 *
 * Se agrupan por fase del plan para que quede visible qué está construido
 * y qué falta.
 *
 * @var \Core\Router $router
 */

use App\Controllers\AnulacionController;
use App\Controllers\ApoderadoController;
use App\Controllers\AuthController;
use App\Controllers\CarneController;
use App\Controllers\InscripcionController;
use App\Controllers\InstitucionController;
use App\Controllers\PagoController;
use App\Controllers\PanelController;
use Core\View;

// ---------------------------------------------------------------------
// Fase 1 — Acceso al sistema
// ---------------------------------------------------------------------
$router->get('/', [PanelController::class, 'index']);
$router->get('/login', [AuthController::class, 'mostrarLogin']);
$router->post('/login', [AuthController::class, 'procesarLogin']);
$router->post('/salir', [AuthController::class, 'salir']);
$router->get('/panel', [PanelController::class, 'index']);

// ---------------------------------------------------------------------
// Fase 2 — Instituciones Educativas y Apoderados
// ---------------------------------------------------------------------
$router->get('/instituciones', [InstitucionController::class, 'index']);
$router->get('/instituciones/nueva', [InstitucionController::class, 'formularioNueva']);
$router->post('/instituciones', [InstitucionController::class, 'guardar']);
$router->get('/instituciones/{id}/editar', [InstitucionController::class, 'formularioEditar']);
$router->post('/instituciones/{id}', [InstitucionController::class, 'guardar']);
$router->post('/instituciones/{id}/eliminar', [InstitucionController::class, 'eliminar']);

$router->get('/apoderados', [ApoderadoController::class, 'index']);
$router->get('/apoderados/nuevo', [ApoderadoController::class, 'formularioNuevo']);
$router->post('/apoderados', [ApoderadoController::class, 'guardar']);
$router->get('/apoderados/{id}/editar', [ApoderadoController::class, 'formularioEditar']);
$router->post('/apoderados/{id}', [ApoderadoController::class, 'guardar']);
$router->post('/apoderados/{id}/eliminar', [ApoderadoController::class, 'eliminar']);

// Buscadores en JSON, consumidos por los formularios.
$router->get('/api/instituciones/buscar', [InstitucionController::class, 'buscarJson']);
$router->get('/api/apoderados/buscar', [ApoderadoController::class, 'buscarPorDniJson']);

// ---------------------------------------------------------------------
// Fase 3 — Inscripción
// ---------------------------------------------------------------------
$router->get('/inscripciones', [InscripcionController::class, 'index']);
$router->get('/inscripciones/delegacion', [InscripcionController::class, 'formularioDelegacion']);
$router->post('/inscripciones/delegacion', [InscripcionController::class, 'guardarDelegacion']);
$router->get('/inscripciones/libre', [InscripcionController::class, 'formularioLibre']);
$router->post('/inscripciones/libre', [InscripcionController::class, 'guardarLibre']);

$router->get('/api/participantes/verificar', [InscripcionController::class, 'verificarDocumento']);

// ---------------------------------------------------------------------
// Fase 4 — Pagos, anulación y carné
// ---------------------------------------------------------------------
$router->post('/pagos/confirmar', [PagoController::class, 'confirmar']);
$router->post('/inscripciones/{id}/carne/regenerar', [PagoController::class, 'regenerarCarne']);

$router->get('/inscripciones/{id}/corregir', [AnulacionController::class, 'formularioCorregir']);
$router->post('/inscripciones/{id}/corregir', [AnulacionController::class, 'corregir']);
$router->post('/inscripciones/{id}/anular', [AnulacionController::class, 'anular']);

$router->get('/inscripciones/{id}/carne.pdf', [CarneController::class, 'descargar']);

// Vista digital del carné: PÚBLICA, sin sesión. Es lo que abre el QR.
$router->get('/carne/{codigo}', [CarneController::class, 'publico']);

// ---------------------------------------------------------------------
// Fase 5 — Reportes                        (pendiente)
// ---------------------------------------------------------------------

$router->noEncontrado(static function (): void {
    echo View::renderizar('errores.404', ['titulo' => 'No encontrado'], 'limpio');
});
