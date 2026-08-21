<?php

declare(strict_types=1);

namespace App\Models;

use Core\Database;

/**
 * Registro de las correcciones hechas sobre un participante o su inscripción
 * (D-50).
 *
 * Existe porque `participantes` era la ÚNICA mutación del sistema sin firma, y
 * en realidad ni siquiera había mutación: el modelo solo sabía `crear()`. Un
 * DNI mal tecleado no se podía arreglar, y la salida era anular y volver a
 * registrar — que duplica personas. Pasó de verdad el 20-ago: dos participantes
 * con el mismo nombre y un dígito de diferencia en el documento.
 *
 * Se guarda **una fila por campo cambiado**, todas con el mismo `lote`. Contra
 * un JSON con el diff, esto permite responder «¿cuál era el DNI antes?» con un
 * WHERE, que es la pregunta que de verdad se hace cuando algo no cuadra.
 *
 * `anterior` y `nuevo` guardan el TEXTO LEGIBLE, no el id: un registro de
 * auditoría tiene que poder leerse dentro de un año sin unirlo a tablas que
 * pueden haber cambiado desde entonces. Una categoría se guarda como
 * «Primaria 3°» y una institución por su nombre.
 */
final class Correccion
{
    /**
     * Rótulos de los campos, para que el historial se lea en castellano.
     *
     * Viven aquí y no en la vista porque son parte del significado del dato:
     * quien añada un campo corregible tiene que pasar por este mapa, y así no
     * puede aparecer un `participante.algo` sin nombre en la pantalla.
     */
    public const ETIQUETAS = [
        'participante.dni'           => 'Documento',
        'participante.ap_paterno'    => 'Apellido paterno',
        'participante.ap_materno'    => 'Apellido materno',
        'participante.nombres'       => 'Nombres',
        'participante.institucion_id' => 'Institución',
        'participante.tipo_participante' => 'Tipo de participante',
        'participante.apoderado_id'  => 'Apoderado',
        'inscripcion.categoria_id'   => 'Categoría',
        'inscripcion.tipo_origen'    => 'Modalidad',
        'inscripcion.monto'          => 'Monto',
    ];

    public static function etiqueta(string $campo): string
    {
        return self::ETIQUETAS[$campo] ?? $campo;
    }

    /**
     * Guarda un lote de cambios con su motivo y su firma.
     *
     * Devuelve el identificador del lote, que agrupa las filas de un mismo
     * envío del formulario.
     *
     * No abre transacción propia: se la llama SIEMPRE desde dentro de la
     * transacción que está aplicando los cambios, para que el registro y el
     * cambio vivan o mueran juntos. Una corrección aplicada sin firma sería
     * exactamente el agujero que D-50 vino a cerrar.
     *
     * @param array<string, array{0: string|null, 1: string|null}> $cambios
     *        campo => [valor anterior legible, valor nuevo legible]
     */
    public static function registrar(
        int $participanteId,
        ?int $inscripcionId,
        array $cambios,
        string $motivo,
        int $usuarioId
    ): string {
        $lote = bin2hex(random_bytes(16));

        foreach ($cambios as $campo => [$anterior, $nuevo]) {
            Database::insertar(
                'INSERT INTO correcciones (
                    participante_id, inscripcion_id, lote, campo,
                    anterior, nuevo, motivo, usuario_id
                 ) VALUES (
                    :participante, :inscripcion, :lote, :campo,
                    :anterior, :nuevo, :motivo, :usuario
                 )',
                [
                    'participante' => $participanteId,
                    'inscripcion'  => $inscripcionId,
                    'lote'         => $lote,
                    'campo'        => $campo,
                    'anterior'     => $anterior,
                    'nuevo'        => $nuevo,
                    'motivo'       => mb_substr($motivo, 0, 250),
                    'usuario'      => $usuarioId,
                ]
            );
        }

        return $lote;
    }

    /**
     * Historial de una inscripción, lo más reciente primero.
     *
     * Es lo que se pinta en la propia pantalla de corrección (decisión del
     * propietario, 20-ago): no hay pantalla de auditoría aparte, pero quien va
     * a corregir ve lo que ya se corrigió antes. Sin eso, con dos secretarias
     * trabajando a la vez, la segunda no tiene forma de saber que la primera
     * ya tocó ese mismo dato.
     *
     * Trae las correcciones del PARTICIPANTE, no solo las de esta inscripción:
     * los datos del estudiante son suyos y le siguen si alguna vez se le
     * reinscribe, así que esconder las de una inscripción anterior dejaría el
     * historial mintiendo por omisión.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function porParticipante(int $participanteId): array
    {
        return Database::todos(
            'SELECT c.*, u.nombres AS corregido_por
               FROM correcciones c
               JOIN usuarios u ON u.id = c.usuario_id
              WHERE c.participante_id = :p
           ORDER BY c.created_at DESC, c.id DESC',
            ['p' => $participanteId]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function porLote(string $lote): array
    {
        return Database::todos(
            'SELECT * FROM correcciones WHERE lote = :l ORDER BY id',
            ['l' => $lote]
        );
    }

    public static function total(): int
    {
        $fila = Database::uno('SELECT COUNT(*) AS n FROM correcciones');

        return (int) ($fila['n'] ?? 0);
    }
}
