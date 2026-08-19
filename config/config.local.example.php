<?php

declare(strict_types=1);

/**
 * Plantilla de configuración por entorno.
 *
 * Copia este archivo como `config.local.php` y ajusta los valores. Solo hace
 * falta declarar lo que cambia respecto de `config/config.php`; el resto se
 * hereda.
 *
 * `config.local.php` NO se versiona —`.gitignore` lo excluye— porque lleva las
 * credenciales de la base dentro. Cuidado con el nombre: cualquier otro archivo
 * dentro de `config/` **sí** se commitearía.
 *
 * En Hostinger, 'depurar' DEBE quedar en false: con true, cualquier error
 * mostraría rutas del servidor y fragmentos de consulta al visitante.
 *
 * Comprueba el resultado con `php scripts/verificar_despliegue.php`.
 */

return [
    'app' => [
        'entorno' => 'produccion',
        'depurar' => false,

        /*
         * Dominio canónico — OPCIONAL desde D-43.
         *
         * Enlaces, assets y redirecciones ya no dependen de esto: son relativos
         * a la raíz y funcionan bajo cualquier dominio sin tocar configuración.
         * Lo único que queda aquí es el QR del carné, que se imprime en papel y
         * necesita el dominio dentro.
         *
         *   · Déjalo VACÍO si el dominio es provisional o va a cambiar: cada
         *     carné saldrá con el dominio por el que se generó.
         *
         *   · Ponlo cuando tengas un dominio propio y quieras que los QR
         *     apunten siempre ahí, aunque se entre por otro. Sin barra final.
         *
         * Lo que ninguna de las dos opciones arregla: un QR **ya impreso** lleva
         * dentro el dominio de cuando se imprimió. Si cambias de dominio después,
         * ese papel apunta a donde ya no estás. Por eso conviene imprimir los
         * carnés lo más tarde posible — y por eso la puerta busca por código
         * tecleado en /control, que no depende del QR.
         */
        'url_base' => '',
    ],

    'db' => [
        // Las claves son las que lee Core\Database: `puerto`, `nombre` y
        // `usuario` — no `port`, `database` ni `username`.
        'host'     => 'localhost',
        'puerto'   => 3306,
        'nombre'   => 'NOMBRE_BD_EN_CPANEL',
        'usuario'  => 'USUARIO_BD',
        'password' => 'CONTRASENA_BD',
        'charset'  => 'utf8mb4',
    ],
];
