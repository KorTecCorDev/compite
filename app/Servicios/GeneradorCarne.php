<?php

declare(strict_types=1);

namespace App\Servicios;

use Dompdf\Dompdf;
use Dompdf\Options;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Core\Config;
use Core\View;
use RuntimeException;

/**
 * Genera el carné de inscripción en PDF, con su código QR.
 *
 * El QR codifica la URL pública de la vista digital del carné
 * (`/carne/{codigo}`), que es de acceso abierto por decisión del propietario.
 * Por eso el código lleva sufijo aleatorio: ver decisión D-04 del plan.
 */
final class GeneradorCarne
{
    /** Tamaño del carné en milímetros: 100 × 70, cómodo de imprimir y recortar. */
    private const ANCHO_MM = 100;
    private const ALTO_MM  = 70;

    /** 1 mm = 2.8346 puntos PostScript. */
    private const MM_A_PUNTOS = 2.8346;

    /**
     * Crea el PDF y lo guarda. Devuelve la ruta relativa a la raíz del proyecto.
     *
     * @param array<string, mixed> $datos ficha del participante y su inscripción
     */
    public static function generar(array $datos): string
    {
        $codigo = (string) $datos['codigo_correlativo'];
        $url    = self::urlPublica($codigo);

        $html = self::html($datos, $url, self::qrBase64($url));

        $opciones = new Options();
        $opciones->set('isRemoteEnabled', false);   // nada de recursos externos
        $opciones->set('isHtml5ParserEnabled', true);
        $opciones->set('defaultFont', 'DejaVu Sans'); // única con tildes y ñ

        $dompdf = new Dompdf($opciones);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper([
            0,
            0,
            self::ANCHO_MM * self::MM_A_PUNTOS,
            self::ALTO_MM * self::MM_A_PUNTOS,
        ]);
        $dompdf->render();

        $directorio = Config::ruta((string) Config::obtener('rutas.carnes', 'storage/carnes'));

        if (!is_dir($directorio) && !mkdir($directorio, 0775, true) && !is_dir($directorio)) {
            throw new RuntimeException('No se pudo crear el directorio de carnés.');
        }

        // El nombre sale del código, que ya es único y no adivinable.
        $relativa = rtrim((string) Config::obtener('rutas.carnes', 'storage/carnes'), '/')
            . '/' . $codigo . '.pdf';

        $bytes = file_put_contents(Config::ruta($relativa), $dompdf->output());

        if ($bytes === false) {
            throw new RuntimeException('No se pudo escribir el PDF del carné.');
        }

        return $relativa;
    }

    /**
     * URL que viaja dentro del QR.
     */
    public static function urlPublica(string $codigo): string
    {
        return rtrim((string) Config::obtener('app.url_base', ''), '/') . '/carne/' . $codigo;
    }

    /**
     * QR como data URI, para incrustarlo en el HTML del PDF sin archivo aparte.
     */
    private static function qrBase64(string $url): string
    {
        $qr = Builder::create()
            ->writer(new PngWriter())
            ->data($url)
            ->size(340)
            ->margin(4)
            // Nivel alto: el carné se imprime, se dobla y se manosea todo el día
            // del concurso. Con corrección alta sigue leyéndose aunque se raye.
            ->errorCorrectionLevel(ErrorCorrectionLevel::High)
            ->build();

        return 'data:image/png;base64,' . base64_encode($qr->getString());
    }

    /**
     * @param array<string, mixed> $datos
     */
    private static function html(array $datos, string $url, string $qr): string
    {
        $e = static fn (mixed $v): string => View::e($v);

        $nombre = trim(
            ($datos['ap_paterno'] ?? '') . ' ' . ($datos['ap_materno'] ?? '')
            . ', ' . ($datos['nombres'] ?? '')
        );

        $categoria = ucfirst((string) ($datos['nivel'] ?? '')) . ' ' . (int) ($datos['grado'] ?? 0) . '°';

        $origen = ($datos['tipo_participante'] ?? '') === 'libre'
            ? 'Estudiante libre'
            : (string) ($datos['institucion'] ?? '—');

        $fecha = !empty($datos['fecha_evento'])
            ? date('d/m/Y', strtotime((string) $datos['fecha_evento']))
            : '';

        return <<<HTML
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 0; }
    body {
        margin: 0;
        font-family: "DejaVu Sans", sans-serif;
        color: #1b2430;
    }
    .carne { padding: 5mm 5mm 4mm; }
    .cabecera {
        border-bottom: 1.2pt solid #1d4ed8;
        padding-bottom: 1.5mm;
        margin-bottom: 2.5mm;
    }
    .cabecera .evento { font-size: 8pt; font-weight: bold; color: #1d4ed8; }
    .cabecera .sede   { font-size: 5.6pt; color: #5b6878; }
    .cuerpo { width: 100%; }
    .datos  { width: 62%; vertical-align: top; }
    .qr     { width: 38%; text-align: right; vertical-align: top; }
    .qr img { width: 22mm; height: 22mm; }
    .qr .pie-qr { font-size: 4.6pt; color: #5b6878; margin-top: .6mm; }
    .rotulo { font-size: 5pt; color: #5b6878; text-transform: uppercase; letter-spacing: .3pt; }
    .valor  { font-size: 8pt; font-weight: bold; margin-bottom: 1.6mm; }
    .valor--nombre { font-size: 9pt; }
    .codigo {
        font-size: 8.5pt;
        font-weight: bold;
        letter-spacing: .4pt;
        color: #1d4ed8;
    }
    .pie {
        margin-top: 2mm;
        padding-top: 1.2mm;
        border-top: .5pt solid #dde3ea;
        font-size: 4.8pt;
        color: #5b6878;
    }
</style>
</head>
<body>
<div class="carne">

    <div class="cabecera">
        <div class="evento">{$e($datos['concurso'] ?? 'COCIAP 2026')}</div>
        <div class="sede">{$e($datos['sede'] ?? '')} &middot; {$fecha}</div>
    </div>

    <table class="cuerpo">
        <tr>
            <td class="datos">
                <div class="rotulo">Participante</div>
                <div class="valor valor--nombre">{$e($nombre)}</div>

                <div class="rotulo">Documento</div>
                <div class="valor">{$e($datos['dni'] ?? '')}</div>

                <div class="rotulo">Categoría</div>
                <div class="valor">{$e($categoria)}</div>

                <div class="rotulo">Procedencia</div>
                <div class="valor">{$e($origen)}</div>
            </td>
            <td class="qr">
                <img src="{$qr}" alt="">
                <div class="pie-qr">Escanea para verificar</div>
            </td>
        </tr>
    </table>

    <div class="codigo">{$e($datos['codigo_correlativo'] ?? '')}</div>

    <div class="pie">
        Carné de inscripción. Preséntalo el día del concurso.
    </div>

</div>
</body>
</html>
HTML;
    }
}
