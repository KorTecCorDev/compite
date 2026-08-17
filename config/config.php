<?php

declare(strict_types=1);

/**
 * Configuración base del sistema COCIAP 2026.
 *
 * Este archivo contiene valores por defecto y NO debe llevar credenciales
 * reales. Para sobrescribir cualquier clave en tu máquina o en Hostinger,
 * crea `config/config.local.php` devolviendo un array con solo las claves
 * que cambian. Ese archivo nunca se sube al repositorio ni al servidor
 * de otro entorno.
 */

return [

    // Nombre visible del sistema, usado en títulos y en el carné.
    'app' => [
        'nombre'    => 'COCIAP 2026 — Inscripciones',
        'entorno'   => 'local',            // 'local' | 'produccion'
        'depurar'   => true,               // en producción DEBE ser false
        'zona'      => 'America/Lima',
        // URL base pública. Se usa para armar el enlace que codifica el QR.
        // En Hostinger será algo como 'https://tudominio.pe'
        'url_base'  => 'http://localhost/compite',
    ],

    'db' => [
        'host'     => '127.0.0.1',
        'puerto'   => 3306,
        'nombre'   => 'compite',
        'usuario'  => 'root',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],

    'sesion' => [
        'nombre'          => 'COCIAP_SESion',
        // Minutos de inactividad antes de cerrar sesión.
        'inactividad_min' => 120,
    ],

    // Rutas de almacenamiento, relativas a la raíz del proyecto.
    'rutas' => [
        'carnes' => 'storage/carnes',
        'logs'   => 'storage/logs',
    ],
];
