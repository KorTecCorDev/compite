<?php

declare(strict_types=1);

namespace Core;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Las fechas guardadas, leídas en la hora del concurso (D-62).
 *
 * **El problema, medido sobre los datos reales del concurso.** `fecha_pago` es
 * `DATETIME` y la escribe `NOW()` de MySQL, así que guarda **la hora del
 * servidor**, que en Hostinger corre en UTC. `created_at` y `updated_at`, en
 * cambio, son `TIMESTAMP`: MySQL los guarda en UTC y los **convierte al leer**.
 *
 * Consecuencia: las horas de pago del concurso están **cinco horas
 * adelantadas** respecto de la hora de Ancash, y eso no es cosmético —
 * **191 cobros, S/ 1 965,00, quedaron archivados con fecha del sábado 22
 * cuando se cobraron el viernes 21 por la noche**. En un cierre de caja por
 * día, eso es la diferencia entre cuadrar y no cuadrar.
 *
 * Se comprobó comparando ambas columnas en las 805 filas pagadas: 803 dan un
 * desfase de **exactamente 18 000 segundos**, y las dos restantes son filas
 * que se modificaron después del cobro, así que su `updated_at` avanzó por otra
 * razón. Un desfase constante y redondo no es azar: es una zona horaria.
 *
 * **Por qué se corrige AL LEER y nunca tocando los datos.** Reescribir 805
 * fechas de pago es reescribir el libro de caja, y un libro que se reescribe
 * deja de ser prueba de nada. El dato guardado es correcto en su propia zona;
 * lo que faltaba era decir en cuál está.
 *
 * **Por qué la zona de los datos es CONFIGURACIÓN y no se detecta sola.** Se
 * intentó deducirla comparando `NOW()` de MySQL con la hora de PHP, y no sirve:
 * dice en qué zona escribe el servidor **de ahora**, no aquel en el que se
 * escribieron las filas. Este mismo volcado —creado en UTC— se está leyendo en
 * una máquina cuyo MySQL corre en hora de Lima, que es justo el caso en el que
 * la detección automática respondería «cero» y dejaría el error intacto.
 *
 * `zona_datos` es, por tanto, una propiedad **del volcado**, no del servidor
 * que lo lee, y por eso viaja en `config.php` y no se adivina.
 */
final class Fecha
{
    private function __construct()
    {
    }

    /**
     * La zona en la que están escritas las columnas `DATETIME` de la base.
     */
    public static function zonaDatos(): DateTimeZone
    {
        return new DateTimeZone((string) Config::obtener('app.zona_datos', 'UTC'));
    }

    /**
     * La zona en la que se vive el concurso, y en la que hay que leerlo todo.
     */
    public static function zonaLocal(): DateTimeZone
    {
        return new DateTimeZone((string) Config::obtener('app.zona', 'America/Lima'));
    }

    /**
     * Convierte un valor guardado a la hora local. `null` si no hay valor.
     */
    public static function local(?string $guardado): ?DateTimeImmutable
    {
        if ($guardado === null || trim($guardado) === '') {
            return null;
        }

        $fecha = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', trim($guardado), self::zonaDatos());

        if ($fecha === false) {
            // Formatos sueltos (una fecha sin hora, por ejemplo). Se intenta
            // interpretar igual antes de rendirse: perder la fecha entera por
            // un segundo de más sería peor que aproximarla.
            try {
                $fecha = new DateTimeImmutable($guardado, self::zonaDatos());
            } catch (\Exception) {
                return null;
            }
        }

        return $fecha->setTimezone(self::zonaLocal());
    }

    /**
     * Un **instante** para pintar en pantalla: se convierte a hora de Ancash.
     *
     * Úsalo con `DATETIME` y `TIMESTAMP` —`fecha_pago`, `created_at`,
     * `updated_at`, `generado_en`—, que son momentos en el tiempo.
     */
    public static function mostrar(?string $guardado, string $formato = 'd/m/Y H:i', string $vacio = '—'): string
    {
        $fecha = self::local($guardado);

        return $fecha === null ? $vacio : $fecha->format($formato);
    }

    /**
     * Un **día de calendario**, que NO se convierte.
     *
     * Úsalo con las columnas `DATE` —la fecha del evento, el cierre de
     * inscripción—. El concurso es el sábado 22 en Ancash y en Tokio: no es un
     * instante, es un día, y desplazarlo por zona horaria lo movería al 21.
     *
     * Existe para que la diferencia esté escrita en el código y no en la cabeza
     * de quien lo lea: sin dos métodos distintos, el próximo que vea un
     * `date()` suelto junto a un `Fecha::mostrar()` va a «arreglar» el que no
     * tocaba.
     */
    public static function dia(?string $guardado, string $formato = 'd/m/Y', string $vacio = '—'): string
    {
        if ($guardado === null || trim($guardado) === '') {
            return $vacio;
        }

        $marca = strtotime(trim($guardado));

        return $marca === false ? $vacio : date($formato, $marca);
    }

    /**
     * El momento actual, en hora de Ancash, **sin depender del `php.ini`**.
     *
     * `date()` a secas usa la zona por defecto de PHP, que la aplicación fija
     * en `public/index.php` pero un script de consola no, y que en el servidor
     * puede venir en UTC. Un documento que se firma no puede llevar una hora de
     * emisión que dependa de por dónde se lanzó.
     */
    public static function ahora(string $formato = 'd/m/Y H:i'): string
    {
        return (new DateTimeImmutable('now', self::zonaLocal()))->format($formato);
    }

    /**
     * Hoy, a medianoche, en hora de Ancash.
     *
     * Lo usa la cuenta atrás del panel. Con `new DateTimeImmutable('today')` a
     * secas, un servidor con el `php.ini` en UTC cree que ya es mañana desde las
     * siete de la tarde de Perú, y la víspera del concurso la pantalla diría que
     * el evento «ya pasó». Es el mismo tipo de fallo que D-53 corrigió con
     * «faltan 1 día», y en producción no se ve hasta que alguien lo mira.
     */
    public static function hoy(): DateTimeImmutable
    {
        return new DateTimeImmutable('today', self::zonaLocal());
    }

    /**
     * El desplazamiento, en horas, que hay que aplicar **dentro del SQL** para
     * que agrupar por día use el día real y no el del servidor.
     *
     * Se calcula sobre una fecha concreta y no como constante: aunque ni Lima
     * ni UTC tienen horario de verano, otra organización podría estar en una
     * zona que sí, y entonces el desplazamiento del 20 de agosto no es el de
     * enero. Se usa la fecha del concurso, que es la de los datos que se leen.
     *
     * Devuelve 0 cuando ambas zonas coinciden, con lo que las consultas quedan
     * exactamente como estaban.
     */
    public static function desplazamientoHoras(?string $referencia = null): int
    {
        $momento = $referencia ?? 'now';

        try {
            $enDatos = new DateTimeImmutable($momento, self::zonaDatos());
        } catch (\Exception) {
            $enDatos = new DateTimeImmutable('now', self::zonaDatos());
        }

        $offsetDatos = self::zonaDatos()->getOffset($enDatos);
        $offsetLocal = self::zonaLocal()->getOffset($enDatos);

        return (int) round(($offsetLocal - $offsetDatos) / 3600);
    }

    /**
     * La expresión SQL que devuelve una columna de fecha ya en hora local.
     *
     * Se interpola un entero calculado aquí dentro, nunca entrada del usuario:
     * `desplazamientoHoras()` sale de la configuración y de la zona horaria, y
     * se fuerza a `int` antes de concatenar.
     */
    public static function sqlLocal(string $columna): string
    {
        $horas = self::desplazamientoHoras();

        if ($horas === 0) {
            return $columna;
        }

        return '(' . $columna . ' + INTERVAL ' . $horas . ' HOUR)';
    }
}
