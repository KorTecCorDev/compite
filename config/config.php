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

        /*
         * La zona en la que están escritas las columnas DATETIME de la base
         * —hoy solo `inscripciones.fecha_pago`— (D-62).
         *
         * NO es la zona del servidor que lee: es la del servidor que ESCRIBIÓ.
         * Hostinger corre MySQL en UTC, así que `NOW()` guardó horas UTC en una
         * columna que no las convierte al leer. Medido sobre los datos reales:
         * **5 horas de adelanto**, y 191 cobros del viernes por la noche
         * archivados con fecha del sábado.
         *
         * Se corrige AL LEER, en `Core\Fecha`. Los datos no se tocan: reescribir
         * 805 fechas de pago es reescribir el libro de caja.
         *
         * Ponlo igual que `zona` solo si la base la escribió un MySQL que ya
         * corría en hora local; entonces el desplazamiento es cero y las
         * consultas quedan como estaban.
         */
        'zona_datos' => 'UTC',
        /*
         * Dominio canónico. **Opcional desde D-43.**
         *
         * Ya NO decide los enlaces ni los assets ni las redirecciones: todo eso
         * es relativo a la raíz y funciona bajo cualquier dominio sin tocar
         * nada. Lo único que queda aquí es el QR del carné, que se imprime en
         * papel y necesita el dominio dentro.
         *
         *   · Con valor  → los QR apuntan siempre ahí, aunque se entre por otro
         *                  dominio. Es lo que se quiere con un dominio propio.
         *   · Vacío      → cada carné toma el dominio por el que se generó. Es
         *                  lo razonable con un dominio provisional.
         *
         * En LOCAL se deja con valor a propósito: con BrowserSync delante, un
         * carné generado a través del proxy quedaría apuntando a localhost:3000.
         * Aquí el dominio no es provisional, es falso.
         */
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
    //
    // `carnes` ya no está: desde D-24 el PDF del carné se genera al vuelo en
    // cada descarga y no se escribe en disco.
    'rutas' => [
        'logs' => 'storage/logs',
    ],
];
