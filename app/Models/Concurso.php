<?php

declare(strict_types=1);

namespace App\Models;

use Core\Database;
use RuntimeException;

/**
 * Concurso vigente y sus catálogos: categorías y tarifas.
 */
final class Concurso
{
    /**
     * Las tres bolsas de competencia de D-37, en el orden en que las numera el
     * plan. Son identificadores propios, no modalidades: `BOLSA_PUBLICA` y
     * `BOLSA_ORGANIZADORA` valen lo mismo que su `tipo_origen` porque esas dos
     * bolsas SON una sola modalidad cada una, y forzar un nombre distinto solo
     * para que no coincidieran habría sido artificial.
     *
     * Cuidado con esa coincidencia en un sitio concreto: pasar una bolsa a
     * `tarifa()` es un error. Con `BOLSA_PRIVADA_LIBRE` revienta —no hay tal
     * tarifa—, pero con las otras dos devolvería un número correcto por
     * casualidad. La bolsa no elige precio; lo elige la modalidad.
     */
    public const BOLSA_PRIVADA_LIBRE = 'privada_libre';
    public const BOLSA_PUBLICA       = 'publica';
    public const BOLSA_ORGANIZADORA  = 'organizadora';

    /**
     * El concurso sobre el que se trabaja.
     *
     * En este MVP hay uno solo; se toma el de fecha de evento más reciente
     * para que el día que exista un segundo, el sistema no tenga que cambiar.
     *
     * @return array<string, mixed>|null
     */
    public static function vigente(): ?array
    {
        return Database::uno(
            'SELECT c.*,
                    o.nombre         AS organizacion,
                    o.institucion_id AS organizacion_institucion_id
               FROM concursos c
               JOIN organizaciones o ON o.id = c.organizacion_id
           ORDER BY c.fecha_evento DESC
              LIMIT 1'
        );
    }

    /**
     * Modalidad (`tipo_origen`) de un participante: la que elige su tarifa y la
     * bolsa en la que compite.
     *
     * Es el ÚNICO sitio donde se decide (D-37). Antes la misma regla estaba
     * copiada en cuatro —el alta, el filtro del listado y los dos carnés—, y con
     * una modalidad más habría habido cuatro copias que mantener sincronizadas.
     *
     * `$institucion` en null significa estudiante libre: no tiene colegio.
     *
     * 'organizadora' cuando el colegio es el anfitrión del concurso. La
     * comparación es contra `organizaciones.institucion_id` —un entero— y no
     * contra el nombre del colegio, que es lo que la haría frágil.
     */
    public static function modalidad(array $concurso, ?array $institucion): string
    {
        if ($institucion === null) {
            return 'libre';
        }

        $anfitriona = $concurso['organizacion_institucion_id'] ?? null;

        if ($anfitriona !== null && (int) $anfitriona === (int) $institucion['id']) {
            return 'organizadora';
        }

        return (string) $institucion['tipo'];
    }

    /**
     * Rótulo de la modalidad, tal como lo lee una persona: en el carné, en la
     * vista pública y en el listado.
     *
     * Separado del valor guardado a propósito (D-37). En la base la modalidad
     * del anfitrión se llama `'organizadora'` —el esquema no puede llevar el
     * nombre de un inquilino, porque el día que organice otro colegio seguiría
     * diciendo COCIAP—, pero quien lee el carné espera «COCIAP». Cambiar el
     * rótulo es cambiar esta línea; cambiar el valor sería una migración.
     */
    public static function etiquetaModalidad(?string $tipoOrigen): string
    {
        return match ($tipoOrigen) {
            'publica'      => 'Pública',
            'privada'      => 'Privada',
            'libre'        => 'Libre',
            'organizadora' => 'COCIAP',
            default        => '—',
        };
    }

    /**
     * Bolsa de competencia: contra quién compite de verdad un participante,
     * dentro de su nivel y su grado.
     *
     * **La bolsa NO es la modalidad**, y confundirlas es el fallo más caro que
     * puede cometer un reporte: `privada` y `libre` compiten JUNTAS (D-37,
     * confirmado por el propietario). Agrupar el acta por las cuatro
     * modalidades daría **dos ganadores donde las bases dicen uno**, y nadie lo
     * notaría hasta la premiación.
     *
     * Hasta D-54 esta regla no vivía en el dominio: existía solo como un `CASE`
     * dentro de un `printf` en `scripts/pruebas/modalidad-organizadora.php`,
     * sin ninguna aserción encima. Este es ahora su único sitio.
     *
     * Nivel y grado no entran aquí: ya los lleva `categorias`. Una bolsa
     * completa es la tupla (categoría, bolsa).
     *
     * Lanza ante una modalidad desconocida en vez de devolver un valor de
     * relleno. Es deliberadamente más estricto que `etiquetaModalidad()`: allí
     * lo peor que ocurre es un guion en pantalla; aquí un valor inventado mete
     * a alguien en una bolsa fantasma y le quita el premio sin avisar.
     */
    public static function bolsa(string $tipoOrigen): string
    {
        return match ($tipoOrigen) {
            'privada', 'libre' => self::BOLSA_PRIVADA_LIBRE,
            'publica'          => self::BOLSA_PUBLICA,
            'organizadora'     => self::BOLSA_ORGANIZADORA,
            default            => throw new RuntimeException(
                "Modalidad desconocida '{$tipoOrigen}': no se puede decidir en qué bolsa compite."
            ),
        };
    }

    /**
     * Rótulo de la bolsa, tal como lo leen los jurados en el acta.
     *
     * «Privada + Libre» dice explícitamente que son dos modalidades en una sola
     * bolsa: quien lee el acta tiene que poder ver por qué ahí hay un solo
     * ganador. Un término único —«Particular»— habría sido más corto y habría
     * escondido justo eso.
     */
    public static function etiquetaBolsa(string $bolsa): string
    {
        return match ($bolsa) {
            self::BOLSA_PRIVADA_LIBRE => 'Privada + Libre',
            self::BOLSA_PUBLICA       => 'Pública',
            self::BOLSA_ORGANIZADORA  => 'COCIAP',
            default                   => throw new RuntimeException(
                "Bolsa desconocida '{$bolsa}'."
            ),
        };
    }

    /**
     * Las tres bolsas con su rótulo, en el orden de D-37.
     *
     * Existe para que el acta pueda recorrer SIEMPRE las tres por cada
     * categoría, también las que quedaron vacías o con un solo inscrito. Si el
     * reporte se limitara a agrupar lo que hay, una bolsa de un participante
     * pasaría por normal — y eso se descubre en la premiación, que es el peor
     * momento posible.
     *
     * @return array<string, string> bolsa => rótulo
     */
    public static function bolsas(): array
    {
        return [
            self::BOLSA_PRIVADA_LIBRE => self::etiquetaBolsa(self::BOLSA_PRIVADA_LIBRE),
            self::BOLSA_PUBLICA       => self::etiquetaBolsa(self::BOLSA_PUBLICA),
            self::BOLSA_ORGANIZADORA  => self::etiquetaBolsa(self::BOLSA_ORGANIZADORA),
        ];
    }

    /**
     * Las 11 categorías, ordenadas como las lee una persona:
     * primaria 1°…6°, luego secundaria 1°…5°.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function categorias(int $concursoId): array
    {
        return Database::todos(
            "SELECT id, nivel, grado,
                    CONCAT(UPPER(LEFT(nivel,1)), SUBSTRING(nivel,2), ' ', grado, '°') AS etiqueta
               FROM categorias
              WHERE concurso_id = :concurso
           ORDER BY FIELD(nivel,'primaria','secundaria'), grado",
            ['concurso' => $concursoId]
        );
    }

    /**
     * Verifica que una categoría pertenezca al concurso indicado.
     *
     * Sin esto, alguien podría enviar por POST el id de una categoría de otro
     * concurso: los `<select>` del navegador se editan sin esfuerzo.
     */
    public static function categoriaPertenece(int $categoriaId, int $concursoId): bool
    {
        return Database::uno(
            'SELECT 1 AS ok FROM categorias WHERE id = :cat AND concurso_id = :con LIMIT 1',
            ['cat' => $categoriaId, 'con' => $concursoId]
        ) !== null;
    }

    /**
     * Monto vigente para un tipo de origen.
     *
     * Decisión D-11: la secretaria no elige el monto. Para una delegación se
     * deriva del tipo de la I.E.; para un estudiante libre es siempre 'libre'.
     */
    public static function tarifa(int $concursoId, string $tipoOrigen): float
    {
        $fila = Database::uno(
            'SELECT monto FROM tarifas WHERE concurso_id = :con AND tipo_origen = :tipo LIMIT 1',
            ['con' => $concursoId, 'tipo' => $tipoOrigen]
        );

        if ($fila === null) {
            throw new RuntimeException(
                "No hay tarifa configurada para el tipo de origen '{$tipoOrigen}'."
            );
        }

        return (float) $fila['monto'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function tarifas(int $concursoId): array
    {
        return Database::todos(
            "SELECT tipo_origen, monto
               FROM tarifas
              WHERE concurso_id = :con
           ORDER BY FIELD(tipo_origen,'publica','privada','libre','organizadora')",
            ['con' => $concursoId]
        );
    }
}
