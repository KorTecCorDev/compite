<?php

declare(strict_types=1);

/**
 * Plantilla de configuración por entorno.
 *
 * Copia este archivo como `config.local.php` y ajusta los valores.
 * En Hostinger, 'depurar' DEBE quedar en false: con true, cualquier error
 * mostraría rutas del servidor y fragmentos de consulta al visitante.
 *
 * 'url_base' es el valor más delicado de este archivo: **es lo que arma el
 * enlace que codifica el QR del carné** (D-21). Si queda mal, todos los carnés
 * impresos apuntan a ningún sitio, y eso no se arregla reimprimiendo — hay que
 * volver a repartirlos. Compruébalo abriendo un QR con el móvil antes de
 * imprimir en serie.
 *
 * `php scripts/verificar_despliegue.php` revisa este archivo, el esquema de la
 * base y los assets, y dice qué falta.
 */

return [
    'app' => [
        'entorno'  => 'produccion',
        'depurar'  => false,
        'url_base' => 'https://TU-DOMINIO.pe',
    ],

    'db' => [
        'host'     => 'localhost',
        'puerto'   => 3306,
        'nombre'   => 'NOMBRE_BD_EN_CPANEL',
        'usuario'  => 'USUARIO_BD',
        'password' => 'CONTRASENA_BD',
    ],
];
