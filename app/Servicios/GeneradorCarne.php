<?php

declare(strict_types=1);

namespace App\Servicios;

use Dompdf\Dompdf;
use Dompdf\Options;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Core\Config;
use Core\Correlativo;
use Core\View;

/**
 * Genera los carnés de inscripción en PDF.
 *
 * Tres decisiones de diseño gobiernan este servicio (2026-08-18):
 *
 * 1. **Tamaño ID-1** (85.6 × 54 mm), el del DNI y las tarjetas bancarias. Entra
 *    en cualquier portacarné comercial y cuadra 10 por hoja A4 con márgenes
 *    holgados. El tamaño anterior —100 × 70 mm— no correspondía a ningún
 *    estándar y dejaba solo 5 mm de margen lateral en A4, dentro de la zona no
 *    imprimible de la mayoría de impresoras.
 *
 * 2. **Siempre se maqueta sobre A4**, incluso para un solo carné. Un PDF del
 *    tamaño exacto del carné obliga a la impresora a escalarlo (~94%) para que
 *    entre en su área imprimible, y el carné resultante ya no mide lo que dice
 *    medir —el QR tampoco—. Sobre A4 el tamaño impreso es fiel.
 *
 * 3. **Nada se guarda en disco.** El PDF es un derivado de la base, no un
 *    documento con vida propia. Generarlo al vuelo evita que un cambio de
 *    diseño deje carnés viejos congelados, que `storage/carnes` tenga que
 *    viajar al despliegue, y que el archivo pueda faltar. La tabla `carnes`
 *    sigue registrando el hecho de negocio: que el carné fue emitido y cuándo.
 */
final class GeneradorCarne
{
    /** Tamaño ID-1 (ISO/IEC 7810), en milímetros. */
    private const CARNE_ANCHO_MM = 85.6;
    private const CARNE_ALTO_MM  = 53.98;

    /** Grilla en A4: 2 × 5 = 10 por hoja, con 19.2 mm de margen lateral. */
    private const COLUMNAS = 2;
    private const FILAS    = 5;
    private const POR_HOJA = self::COLUMNAS * self::FILAS;

    /**
     * Lado del QR impreso, en milímetros.
     *
     * No es un número fijo: lo calcula ladoQr() a partir de cuántos módulos
     * necesite la URL, para que cada módulo mida al menos MM_POR_MODULO. Un
     * módulo por debajo de 0.5 mm empieza a fallarle a la cámara de un celular,
     * y cuanto más larga sea `app.url_base`, más módulos hacen falta.
     *
     * El máximo no es estético: es el ancho que queda tras reservar la zona de
     * silencio del QR sin robarle sitio al nombre del estudiante.
     */
    private const QR_MM_MIN       = 15;
    private const QR_MM_MAX       = 17;
    private const QR_MM_POR_MODULO = 0.5;

    /**
     * Zona de silencio del QR, en milímetros, a cada lado.
     *
     * Antes la aportaba «el espacio en blanco que el layout deja alrededor».
     * Desde que el carné lleva marca de agua ese espacio ya no es blanco, así
     * que el QR se apoya sobre un recuadro blanco opaco de este padding. No es
     * decoración: la norma del QR exige 4 módulos de margen limpio, y a 0.52 mm
     * por módulo eso son 2.1 mm. Sin ellos el lector confunde el borde del
     * símbolo con datos y muchos teléfonos dejan de resolverlo.
     */
    private const QR_SILENCIO_MM = 2.1;

    /** Cuerpo base del nombre y de la procedencia, en puntos. */
    private const NOMBRE_PT = 8.4;
    private const ORIGEN_PT = 6.2;

    /**
     * Caracteres que entran en una línea a ese cuerpo base.
     *
     * Medido sobre la maqueta real, no estimado: se generaron hojas de 10
     * carnés alargando el texto hasta que la hoja se partió en dos. Al valor
     * medido se le deja ~10% de margen, porque el ancho real de un texto
     * depende de qué letras lo componen —una M ocupa el doble que una I— y al
     * filo el cálculo falla para unos apellidos y no para otros.
     *
     * Revisados el 2026-08-18 tras añadir la marca de agua: la caja blanca que
     * devuelve la zona de silencio al QR le quitó 2.2 mm de ancho a la columna
     * de datos (61.4 → 59.2 mm), y con ellos un carácter al nombre y dos a la
     * procedencia. **Si cambia el ancho del carné, el del QR, su zona de
     * silencio o el cuerpo base, hay que volver a medirlos.**
     */
    private const NOMBRE_POR_LINEA = 25;
    private const ORIGEN_POR_LINEA = 40;

    /**
     * PDF con los carnés de varias inscripciones, paginado de 10 en 10.
     *
     * @param array<int, array<string, mixed>> $fichas
     * @return string bytes del PDF
     */
    public static function hoja(array $fichas): string
    {
        $paginas = array_chunk(array_values($fichas), self::POR_HOJA);
        $html    = self::documento($paginas);

        $opciones = new Options();
        $opciones->set('isRemoteEnabled', false);   // nada de recursos externos
        $opciones->set('isHtml5ParserEnabled', true);
        $opciones->set('defaultFont', 'DejaVu Sans'); // única con tildes y ñ

        /*
         * Sin chroot explícito, Dompdf resuelve las rutas de imagen contra el
         * directorio del documento —que aquí no existe, porque el HTML se le
         * pasa como cadena— y el logo saldría como recuadro roto sin explicar
         * por qué. Apuntarlo a public/ es lo que permite cargar el escudo por
         * ruta de archivo, y de paso acota desde dónde puede leer.
         */
        $opciones->set('chroot', Config::ruta('public'));

        $dompdf = new Dompdf($opciones);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * PDF de un solo carné. Es una hoja de uno, para no mantener dos motores de
     * maquetación que acabarían desincronizándose.
     *
     * @param array<string, mixed> $ficha
     */
    public static function individual(array $ficha): string
    {
        return self::hoja([$ficha]);
    }

    /**
     * URL corta que viaja dentro del QR.
     *
     * Corta a propósito: cada carácter de más engorda el QR y encoge sus
     * módulos. Con `/carne/COCIAP2026-0042-K7M9X3` el QR necesitaba 37 × 37
     * módulos; con `/c/K7M9X3` baja a 29 × 29, un 29% más grande a igual tamaño
     * impreso. Por eso el sufijo tiene que ser único por sí solo —ver
     * Participante::existeSufijo().
     *
     * Lee `app.url_base` a propósito, y NUNCA Core\View::url(): esta URL se
     * imprime en el carné del estudiante y no se puede corregir después. Si se
     * derivara del request, un carné generado mientras se prueba con el proxy
     * de BrowserSync quedaría apuntando a localhost:3000 de forma permanente.
     */
    public static function urlPublica(string $codigo): string
    {
        $base   = rtrim((string) Config::obtener('app.url_base', ''), '/');
        $sufijo = Correlativo::sufijoDe($codigo);

        // Sin sufijo reconocible se cae a la ruta larga: siempre resuelve.
        return $sufijo === null
            ? $base . '/carne/' . $codigo
            : $base . '/c/' . $sufijo;
    }

    /**
     * QR como data URI.
     *
     * Corrección **Quartile** y no High, al contrario que antes. Parece un
     * downgrade y es justo lo opuesto: más corrección de errores significa más
     * módulos en el mismo espacio impreso, y un QR de módulos diminutos con
     * corrección alta se lee peor que uno de módulos grandes con corrección
     * media. Quartile tolera un 25% de daño, de sobra para un carné que se
     * manosea todo el día del concurso.
     */
    private static function qr(string $url): array
    {
        $qr = Builder::create()
            ->writer(new PngWriter())
            ->data($url)
            // 240 px sobre 15 mm son ~406 dpi: de sobra para imprimir, y mucho
            // más liviano que los 340 px de antes, que multiplicados por 10
            // carnés en una misma hoja sí pesaban.
            ->size(240)
            // Margen 0: la zona de silencio la da el espacio en blanco que el
            // layout deja alrededor (≥2 mm), así se controla en milímetros
            // reales y no en píxeles de una imagen que luego se escala.
            ->margin(0)
            ->errorCorrectionLevel(ErrorCorrectionLevel::Quartile)
            ->build();

        $modulos = $qr->getMatrix()->getBlockCount();
        $lado    = self::ladoQr($modulos);

        /*
         * Si ni al tamaño máximo se alcanza la densidad mínima, el carné se
         * genera igual —un QR apretado sigue leyéndose en muchos teléfonos—
         * pero queda constancia: es un problema de configuración, no del
         * carné, y se arregla acortando `app.url_base`.
         */
        if ($lado / $modulos < self::QR_MM_POR_MODULO) {
            error_log(sprintf(
                'Carné: la URL «%s» necesita %d módulos y a %.1f mm cada uno mide %.3f mm, '
                . 'por debajo de los %.2f mm recomendados. Acorta app.url_base.',
                $url, $modulos, $lado, $lado / $modulos, self::QR_MM_POR_MODULO
            ));
        }

        return ['data:image/png;base64,' . base64_encode($qr->getString()), $lado];
    }

    /**
     * Lado impreso que necesita un QR de $modulos para seguir siendo legible.
     */
    private static function ladoQr(int $modulos): float
    {
        $ideal = $modulos * self::QR_MM_POR_MODULO;

        return min(self::QR_MM_MAX, max(self::QR_MM_MIN, round($ideal, 1)));
    }

    /**
     * Ruta de la marca de agua, tal como la entiende Dompdf.
     *
     * Es el logo de aniversario con el alfa ya aplicado en el archivo
     * (`scripts/generar_marca_agua.php`), no el original: la transparencia no se
     * pide por CSS porque Dompdf la soporta de forma parcial y lo impreso
     * dejaría de coincidir con lo previsto.
     *
     * Se pasa por ruta de archivo y no como data URI a propósito: en una hoja
     * de 10 carnés, incrustar el PNG en base64 diez veces son ~2 MB de HTML
     * repetido. Con la ruta, Dompdf carga la imagen una sola vez.
     */
    private static function rutaMarcaAgua(): ?string
    {
        $ruta = Config::ruta('public/img/marca-agua-carne.png');

        if (!is_file($ruta)) {
            return null;   // el carné se genera igual, sin marca de agua
        }

        return str_replace('\\', '/', $ruta);
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $paginas
     */
    private static function documento(array $paginas): string
    {
        $cuerpo = '';

        foreach ($paginas as $indice => $fichas) {
            /*
             * El salto va ANTES de cada hoja menos la primera, y no después de
             * cada hoja menos la última. Con page-break-after, una hoja que ya
             * llena la página —y estas la llenan casi al milímetro— hace que
             * Dompdf abra una página más solo para colocar el salto, y aparece
             * una hoja en blanco entre medias.
             */
            $clase = $indice === 0 ? 'hoja' : 'hoja hoja--nueva';

            $cuerpo .= '<div class="' . $clase . '">' . self::grilla($fichas) . '</div>';
        }

        $css = self::css(self::rutaMarcaAgua());

        return <<<HTML
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>{$css}</style>
</head>
<body>{$cuerpo}</body>
</html>
HTML;
    }

    /**
     * Una hoja A4: tabla de 2 columnas con los carnés pegados entre sí.
     *
     * Pegados y no separados a propósito: con `border-collapse` dos carnés
     * vecinos comparten la línea de corte, así que una sola pasada de guillotina
     * sirve para ambos. Separarlos obligaría a cortar el doble y a descartar
     * tiras de papel entre medias.
     *
     * @param array<int, array<string, mixed>> $fichas
     */
    private static function grilla(array $fichas): string
    {
        $celdas = '';
        $total  = count($fichas);

        for ($i = 0; $i < $total; $i += self::COLUMNAS) {
            $celdas .= '<tr>';

            for ($col = 0; $col < self::COLUMNAS; $col++) {
                $ficha = $fichas[$i + $col] ?? null;

                $celdas .= $ficha === null
                    // Celda vacía: mantiene la grilla cuadrada en la última
                    // hoja cuando los carnés no son múltiplo de 2.
                    ? '<td class="celda celda--vacia"></td>'
                    : '<td class="celda">' . self::carne($ficha) . '</td>';
            }

            $celdas .= '</tr>';
        }

        return '<table class="grilla">' . $celdas . '</table>';
    }

    /**
     * Un carné individual.
     *
     * @param array<string, mixed> $d
     */
    private static function carne(array $d): string
    {
        $e = static fn (mixed $v): string => View::e($v);

        /*
         * Apellidos y nombres van en líneas separadas, como en el DNI. No es
         * estética: en una sola línea, un nombre completo peruano pasa de 29
         * caracteres, salta a una segunda línea y el carné crece lo justo para
         * que la quinta fila no entre en la hoja A4. Partido en dos, la altura
         * del bloque es constante y la maqueta deja de depender del largo.
         */
        $apellidos = trim(($d['ap_paterno'] ?? '') . ' ' . ($d['ap_materno'] ?? ''));
        $nombres   = trim((string) ($d['nombres'] ?? ''));

        $grado = (int) ($d['grado'] ?? 0) . '° ' . ucfirst((string) ($d['nivel'] ?? ''));

        /*
         * Modalidad: libre / pública / privada. Son los mismos tres valores que
         * `tarifas.tipo_origen` usa para decidir cuánto paga el estudiante, y se
         * derivan igual —de `participantes.tipo_participante` cuando es libre y
         * de `instituciones_educativas.tipo` cuando viene por delegación—, para
         * que el carné no pueda contradecir a la tarifa que se cobró.
         */
        $esLibre = ($d['tipo_participante'] ?? '') === 'libre';

        $modalidad = $esLibre ? 'Libre' : match ((string) ($d['institucion_tipo'] ?? '')) {
            'publica' => 'Pública',
            'privada' => 'Privada',
            default   => '—',
        };

        $fecha = !empty($d['fecha_evento'])
            ? date('d/m/Y', strtotime((string) $d['fecha_evento']))
            : '';

        $ptApellidos = self::tamanoQueQuepa($apellidos, self::NOMBRE_PT, self::NOMBRE_POR_LINEA);
        $ptNombres   = self::tamanoQueQuepa($nombres,   self::NOMBRE_PT, self::NOMBRE_POR_LINEA);

        $codigo = (string) ($d['codigo_correlativo'] ?? '');
        [$qr, $qrMm] = self::qr(self::urlPublica($codigo));

        // Ancho de la caja blanca del QR: el símbolo más su zona de silencio a
        // ambos lados. Va inline porque el lado del QR no es fijo (ladoQr()).
        $cajaMm = round($qrMm + 2 * self::QR_SILENCIO_MM, 1);

        /*
         * La procedencia solo existe si el estudiante viene por una delegación.
         * Para un libre, repetirla como «Estudiante libre» sería decir dos veces
         * lo que ya dice Modalidad, en un carné donde cada milímetro se pelea.
         */
        $procedencia = '';

        if (!$esLibre) {
            $origen   = (string) ($d['institucion'] ?? '—');
            $ptOrigen = self::tamanoQueQuepa($origen, self::ORIGEN_PT, self::ORIGEN_POR_LINEA);

            $procedencia = '<div class="rotulo">Procedencia</div>'
                . '<div class="valor valor--origen" style="font-size: ' . $ptOrigen . 'pt">'
                . $e($origen) . '</div>';
        }

        return <<<HTML
<div class="carne">

    <div class="cab">
        <div class="cab-evento">{$e($d['concurso'] ?? 'COCIAP 2026')}</div>
        <div class="cab-org">Colegio de Aplicación «Víctor Valenzuela Guardia» — UNASAM</div>
    </div>

    <table class="cuerpo">
        <tr>
            <td class="datos">
                <div class="rotulo">Apellidos</div>
                <div class="valor valor--nombre" style="font-size: {$ptApellidos}pt">{$e($apellidos)}</div>

                <div class="rotulo">Nombres</div>
                <div class="valor valor--nombre" style="font-size: {$ptNombres}pt">{$e($nombres)}</div>

                <table class="trio">
                    <tr>
                        <td class="trio-dni">
                            <div class="rotulo">DNI</div>
                            <div class="valor">{$e($d['dni'] ?? '')}</div>
                        </td>
                        <td class="trio-grado">
                            <div class="rotulo">Grado</div>
                            <div class="valor">{$e($grado)}</div>
                        </td>
                        <td>
                            <div class="rotulo">Modalidad</div>
                            <div class="valor">{$e($modalidad)}</div>
                        </td>
                    </tr>
                </table>

                {$procedencia}
            </td>
            <td class="qr" style="width: {$cajaMm}mm">
                <div class="qr-caja" style="width: {$qrMm}mm">
                    <img src="{$qr}" alt="" style="width: {$qrMm}mm; height: {$qrMm}mm">
                </div>
            </td>
        </tr>
    </table>

    <table class="pie">
        <tr>
            <td class="pie-codigo">{$e($codigo)}</td>
            <td class="pie-fecha">{$e($fecha)}</td>
        </tr>
    </table>

</div>
HTML;
    }

    /**
     * Tamaño de fuente que hace que un texto quepa en dos líneas.
     *
     * Los nombres peruanos completos —dos apellidos y dos nombres— pasan sin
     * esfuerzo de los 45 caracteres, y a cuerpo fijo el bloque de datos crecía
     * lo justo para que la quinta fila de carnés no entrara en la hoja: diez
     * carnés se convertían en dos páginas. En vez de truncar el nombre, que es
     * el dato más importante del carné, se encoge la letra lo mínimo necesario.
     *
     * $porLinea es cuántos caracteres entran en una línea al cuerpo base. El
     * objetivo es una sola línea por campo: el presupuesto de altura del carné
     * no da para que ninguno de ellos salte a la siguiente.
     */
    private static function tamanoQueQuepa(string $texto, float $base, int $porLinea): float
    {
        $largo = mb_strlen($texto);

        if ($largo <= $porLinea) {
            return $base;
        }

        // Nunca por debajo del 70% del cuerpo base: más pequeño deja de leerse
        // a un metro de distancia, que es justo para lo que sirve el carné. Un
        // texto tan largo que ni así entre hará dos líneas, y para eso está la
        // holgura vertical que el layout se reserva.
        return max(round($base * 0.7, 1), round($base * $porLinea / $largo, 1));
    }
    private static function css(?string $marca): string
    {
        $ancho = self::CARNE_ANCHO_MM;
        $alto  = self::CARNE_ALTO_MM;

        $silencio = self::QR_SILENCIO_MM;

        /*
         * Fondo del carné. El tamaño se calcula a partir de las proporciones
         * reales del archivo en vez de fijarse a mano: así, cambiar la imagen de
         * aniversario el año que viene no exige recalcular nada. Se ajusta al
         * ALTO del carné —el lado corto— para que la marca quede contenida y
         * deje aire a izquierda y derecha, en lugar de recortarse por arriba.
         */
        $fondo = '';

        if ($marca !== null) {
            $medidas = @getimagesize($marca);
            $marcaMm = $medidas === false
                ? $alto
                : round($alto * $medidas[0] / $medidas[1], 2);

            $fondo = <<<FONDO
        background-image: url("{$marca}");
        background-repeat: no-repeat;
        background-position: center center;
        background-size: {$marcaMm}mm {$alto}mm;
FONDO;
        }

        return <<<CSS
    @page { margin: 0; }

    body {
        margin: 0;
        font-family: "DejaVu Sans", sans-serif;
        color: #1b2430;
    }

    /* Márgenes de la hoja.
       Horizontal: (210 - 2 × 85.6) / 2 = 19.2 mm, rebajado a 18.5 mm para
       que las guías de corte quepan sin rozar el borde de la hoja.
       Vertical: los 5 carnés suman 269.9 mm y el ideal serían 13.5 mm arriba y
       abajo, pero eso da 297 mm clavados y el grosor de las guías de corte
       basta para que Dompdf empuje la última fila a una página nueva. Con 12 mm
       quedan ~3 mm de holgura y la hoja sigue muy lejos de la zona no
       imprimible (~5 mm). */
    .hoja { padding: 12mm 18.5mm; }
    .hoja--nueva { page-break-before: always; }

    .grilla { border-collapse: collapse; table-layout: fixed; }

    .celda {
        width: {$ancho}mm;
        height: {$alto}mm;
        padding: 0;
        vertical-align: top;
        /* Guía de corte. Punteada y clara: orienta la tijera sin ensuciar el
           carné si el corte no queda exacto. */
        border: 0.4pt dashed #9aa6b4;
{$fondo}
    }

    /* La celda de relleno conserva las guías de corte —la guillotina corta la
       hoja entera de lado a lado, no carné por carné— pero NO el fondo: un
       rectángulo con la marca institucional y sin datos es un carné en blanco
       esperando a que alguien lo recorte y lo rellene a mano. */
    .celda--vacia { background-image: none; }

    /* Sin width ni height aquí a propósito: el modelo de caja de Dompdf es
       content-box, así que fijar 85.6 × 53.98 mm y encima añadir padding daba
       un carné real de 90.8 × 58.78 mm —y cinco de esos no entran en un A4—.
       La medida la impone .celda, que no tiene padding; este div solo aporta
       el margen interior del contenido. */
    .carne { padding: 2mm 2.6mm; }

    /* ------------------------------------------------------------------ */
    /* Cabecera: identidad del evento                                      */
    /* ------------------------------------------------------------------ */

    /* Sin escudo. La marca de agua del fondo ya contiene el mismo escudo del
       Colegio de Aplicación, y repetirlo a 12.5 mm en la cabecera lo dejaba
       como una mancha de color ilegible además de duplicado. Quitarlo devuelve
       el ancho completo al nombre del concurso —que antes se partía en dos
       líneas— y libera la altura que necesitan Modalidad y Procedencia. */

    .cab { border-bottom: 1pt solid #1d4ed8; padding-bottom: .8mm; }

    .cab-evento {
        font-size: 6.4pt;
        font-weight: bold;
        color: #1d4ed8;
        line-height: 1.15;
        text-transform: uppercase;
    }

    /* El escudo lleva el nombre del colegio en su borde curvo, pero a 12.5 mm
       esa letra mide 0.6 mm y es ilegible. Se repite aquí como texto real: el
       escudo aporta el reconocimiento visual, el texto aporta la información. */
    .cab-org {
        font-size: 4.4pt;
        color: #5b6878;
        line-height: 1.2;
        margin-top: .5mm;
    }

    /* ------------------------------------------------------------------ */
    /* Cuerpo: datos + QR                                                  */
    /* ------------------------------------------------------------------ */

    .cuerpo { width: 100%; margin-top: 1.2mm; }

    .datos { vertical-align: top; padding-right: 2mm; }

    /* El ancho de esta columna va inline: depende de cuántos módulos pida la
       URL, que es lo que decide el lado del QR (ladoQr()). */
    .qr { vertical-align: top; }

    /* Recuadro blanco opaco bajo el QR: le devuelve la zona de silencio que la
       marca de agua le quitó. Sin él, el fondo se cuela hasta el borde del
       símbolo y muchos lectores dejan de resolverlo. */
    .qr-caja {
        background-color: #ffffff;
        padding: {$silencio}mm;
        line-height: 0;
    }

    .rotulo {
        font-size: 4.6pt;
        color: #5b6878;
        text-transform: uppercase;
        letter-spacing: .3pt;
    }

    .valor {
        font-size: 7.2pt;
        font-weight: bold;
        margin-bottom: 1.3mm;
    }

    /* El nombre es el dato que se lee a un metro de distancia en la puerta, y
       el único que se agranda. Lleva rótulo propio —Apellidos / Nombres, como
       en el DNI— porque son dos campos distintos y quien revisa una nómina
       necesita saber cuál es cuál: «Nolasco Mendoza Sara» sin rótulos se puede
       leer con el apellido en cualquiera de los dos sitios. La altura para esos
       rótulos salió de quitar el escudo de la cabecera. */
    /* Sin font-size aquí: lo calcula tamanoQueQuepa() carné por carné. */
    .valor--nombre { line-height: 1.08; margin-bottom: .8mm; }
    .valor--origen { font-weight: normal; margin-bottom: 0; }

    /* DNI, grado y modalidad comparten fila: los tres son cortos y ninguno
       merece una línea propia en un carné donde la altura es el recurso
       escaso. Los porcentajes no son estéticos —«1° Secundaria» y un carné de
       extranjería de 12 dígitos son los valores más largos que puede recibir
       cada columna, y cada una tiene el ancho justo para que no partan. */
    .trio { width: 100%; }
    .trio-dni   { width: 34%; }
    .trio-grado { width: 36%; }

    /* ------------------------------------------------------------------ */
    /* Pie: código y fecha                                                 */
    /* ------------------------------------------------------------------ */

    .pie {
        width: 100%;
        margin-top: 1.2mm;
        border-top: .5pt solid #dde3ea;
        padding-top: .8mm;
    }

    .pie-codigo {
        font-size: 6.6pt;
        font-weight: bold;
        letter-spacing: .3pt;
        color: #1d4ed8;
    }

    .pie-fecha {
        font-size: 5.6pt;
        color: #5b6878;
        text-align: right;
    }
CSS;
    }
}
