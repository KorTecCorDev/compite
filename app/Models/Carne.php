<?php

declare(strict_types=1);

namespace App\Models;

use Core\Database;

/**
 * Carné emitido para una inscripción confirmada.
 *
 * Uno por inscripción (`inscripcion_id` es UNIQUE). Al anular y reinscribir,
 * la nueva inscripción genera su propio carné: el anterior queda ligado a la
 * inscripción anulada y su vista pública lo mostrará como ANULADO.
 */
final class Carne
{
    /**
     * @return array<string, mixed>|null
     */
    public static function porInscripcion(int $inscripcionId): ?array
    {
        return Database::uno(
            'SELECT * FROM carnes WHERE inscripcion_id = :id LIMIT 1',
            ['id' => $inscripcionId]
        );
    }

    /**
     * Registra el carné. Si ya existía uno para esa inscripción, se
     * sobrescribe la ruta: puede haberse regenerado el PDF.
     *
     * `$codigoQr` es el código correlativo que el QR codifica, NO una URL: una
     * URL absoluta ataría la base a un entorno y, al desplegar, todos los
     * carnés existentes apuntarían a localhost. La URL se arma cuando se
     * necesita con GeneradorCarne::urlPublica(). Ver D-21.
     */
    public static function registrar(int $inscripcionId, string $codigoQr, string $rutaPdf): void
    {
        Database::ejecutar(
            'INSERT INTO carnes (inscripcion_id, codigo_qr, ruta_pdf)
                  VALUES (:inscripcion, :qr, :ruta)
             ON DUPLICATE KEY UPDATE
                  codigo_qr   = VALUES(codigo_qr),
                  ruta_pdf    = VALUES(ruta_pdf),
                  generado_en = CURRENT_TIMESTAMP',
            ['inscripcion' => $inscripcionId, 'qr' => $codigoQr, 'ruta' => $rutaPdf]
        );
    }

    public static function total(int $concursoId): int
    {
        $fila = Database::uno(
            'SELECT COUNT(*) AS total
               FROM carnes c
               JOIN inscripciones i ON i.id = c.inscripcion_id
               JOIN participantes p ON p.id = i.participante_id
              WHERE p.concurso_id = :con',
            ['con' => $concursoId]
        );

        return (int) ($fila['total'] ?? 0);
    }
}
