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

    /**
     * Alto del escudo institucional en la cabecera, en milímetros.
     *
     * Calibrado midiendo, no elegido a ojo. Generando hojas de diez carnés con
     * los casos más largos que el sistema puede recibir, el techo de la franja
     * está en **10.0 mm**: a 10.5 mm la hoja se parte en dos páginas. La franja
     * se fija en 9.0 mm y el escudo en 8.5, de modo que le queda medio
     * milímetro de aire dentro de su franja y la franja un milímetro largo
     * hasta el techo.
     *
     * Sale **8.5 × 7.08 mm** impresos, verificado sobre las matrices de
     * colocación del propio PDF. El primer intento se quedó en 6.0 mm porque la
     * cabecera y el pie fluían pegados al cuerpo; lo que permitió casi doblar el
     * escudo no fue apretar nada, sino repartir la altura de antemano (D-35) y
     * quitar el relleno por defecto que Dompdf daba a la tabla del cuerpo.
     *
     * **Si cambia, hay que volver a generar la hoja de diez y comprobar que no
     * se parte en dos páginas.** El presupuesto vertical del carné no tiene
     * holgura para estimaciones.
     */
    private const ESCUDO_ALTO_MM = 10.5;

    /** Aire entre el escudo y el texto de la cabecera. */
    private const ESCUDO_SEPARACION_MM = 2.0;

    /**
     * Alto de las franjas de cabecera y de pie, en milímetros.
     *
     * Las dos están fijadas de antemano y apoyadas en los extremos del carné
     * (D-35): eso es lo que impide que un dato largo empuje la altura y parta
     * la hoja de diez.
     *
     * **No miden lo mismo, y es deliberado (D-36).** D-35 las igualó en 9 mm
     * leyendo al pie de la letra la petición de simetría, y el resultado fue un
     * pie sobredimensionado: su contenido es una sola línea de 2.3 mm, así que
     * sobraban casi 7 mm que dejaban el código flotando a 7 mm del borde en vez
     * de apoyado en él. La simetría que se percibe en el papel es la de los dos
     * filetes enmarcando el cuerpo, no la de dos franjas invisibles de igual
     * altura; el pie ocupa ahora lo que su contenido necesita, y los milímetros
     * liberados van al escudo y al aire entre los datos.
     *
     * De ZONA_CAB_MM sale el techo del escudo: lo que no quepa en esa franja no
     * cabe en la cabecera.
     */
    private const ZONA_CAB_MM = 11.0;
    private const ZONA_PIE_MM = 4.0;
    private const ZONA_CUERPO_MM = 34.0;

    /**
     * Cuerpo base del nombre del concurso, en puntos, y ancho medio de sus
     * caracteres a ese cuerpo.
     *
     * El milímetro por carácter está medido con las métricas reales de la
     * fuente (DejaVu Sans bold, 6.4 pt, mayúsculas), no estimado: el nombre del
     * concurso ocupa 75.17 mm de los 80.40 mm útiles del carné, y con el escudo
     * al lado le quedan 71.3 mm de ancho.
     *
     * El titular solo se encoge si no cabe **en dos líneas**, que es lo que la
     * franja de cabecera admite desde D-35. El suelo del 85% es deliberado: por
     * debajo, el rótulo del evento empieza a competir con los rótulos de 4.6 pt
     * y deja de leerse como titular.
     */
    private const CONCURSO_PT          = 6.4;
    private const CONCURSO_MM_POR_CAR  = 1.534;
    private const CONCURSO_PT_MINIMO   = 5.4;

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
     * Escudo institucional de la cabecera: ruta y medidas ya resueltas.
     *
     * El ancho se deriva de las proporciones reales del archivo en lugar de
     * fijarse a mano —mismo criterio que la marca de agua—: si algún año llega
     * un escudo con otro recorte, el carné se adapta solo en vez de imprimirlo
     * deformado.
     *
     * Se pasa por ruta y no como data URI por lo mismo que la marca de agua: en
     * una hoja de diez, el base64 viajaría diez veces dentro del HTML.
     *
     * @return array{ruta: string, ancho: float, alto: float}|null
     */
    private static function escudo(): ?array
    {
        /** @var array{ruta: string, ancho: float, alto: float}|null|false $cache */
        static $cache = false;

        if ($cache !== false) {
            return $cache;
        }

        $ruta = Config::ruta('public/img/logo-cociap.png');

        if (!is_file($ruta)) {
            // El carné se genera igual, sin escudo: un lote de carnés no puede
            // quedarse sin emitir porque falte una imagen decorativa.
            return $cache = null;
        }

        $medidas = @getimagesize($ruta);
        $alto    = self::ESCUDO_ALTO_MM;

        return $cache = [
            'ruta'  => str_replace('\\', '/', $ruta),
            'alto'  => $alto,
            'ancho' => $medidas === false ? $alto : round($alto * $medidas[0] / $medidas[1], 2),
        ];
    }

    /**
     * Cabecera del carné: escudo a la izquierda, identidad del evento a la
     * derecha.
     *
     * Dos columnas y no una fila encima de otra: así el escudo y el texto se
     * reparten los mismos milímetros de alto en vez de sumarlos. Apilados, el
     * escudo le cobraba su altura entera al cuerpo del carné, que es lo que en
     * D-27 obligó a quitarlo.
     *
     * **El nombre del concurso conserva su cuerpo y usa dos líneas si las
     * necesita.** Mientras la cabecera fluía pegada al cuerpo hubo que
     * encogerlo hasta meterlo en una sola línea, porque la segunda costaba
     * 2.6 mm de altura y partía la hoja de diez. Con la franja de altura fija
     * (D-35) esos milímetros ya están pagados, así que el titular vuelve a sus
     * 6.4 pt y solo se encoge si no cabe ni en dos líneas.
     */
    private static function cabecera(string $concurso): string
    {
        $org    = 'Colegio de Aplicación «Víctor Valenzuela Guardia» — UNASAM';
        $escudo = self::escudo();

        if ($escudo === null) {
            return <<<HTML
    <div class="cab">
        <div class="cab-evento">{$concurso}</div>
        <div class="cab-org">{$org}</div>
    </div>
HTML;
        }

        /*
         * Ancho que le queda al texto una vez el escudo se lleva su columna, y
         * cuerpo que hace que el nombre del concurso siga cabiendo en una sola
         * línea. Se calcula sobre el ancho real disponible en vez de fijarse a
         * un número: si el escudo cambia de tamaño, el titular se reajusta solo.
         */
        $disponible = self::CARNE_ANCHO_MM - 2 * 2.6
                    - $escudo['ancho'] - self::ESCUDO_SEPARACION_MM;

        $pt = self::titularQueQuepa(html_entity_decode($concurso, ENT_QUOTES, 'UTF-8'), $disponible);

        return <<<HTML
    <div class="cab">
        <table class="cab-marco">
            <tr>
                <td class="cab-escudo">
                    <img src="{$escudo['ruta']}" alt=""
                         style="width: {$escudo['ancho']}mm; height: {$escudo['alto']}mm">
                </td>
                <td class="cab-texto">
                    <div class="cab-evento" style="font-size: {$pt}pt">{$concurso}</div>
                    <div class="cab-org">{$org}</div>
                </td>
            </tr>
        </table>
    </div>
HTML;
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

        $cabecera = self::cabecera($e($d['concurso'] ?? 'COCIAP 2026'));

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
            $ptOrigen = self::tamanoQueQuepa($origen, self::ORIGEN_PT, self::ORIGEN_POR_LINEA, 0.65);

            $procedencia = '<div class="rotulo">Procedencia</div>'
                . '<div class="valor valor--origen" style="font-size: ' . $ptOrigen . 'pt">'
                . $e($origen) . '</div>';
        }

        return <<<HTML
<div class="carne">
<table class="marco">
    <tr><td class="zona zona--cab">

{$cabecera}

    </td></tr>
    <tr><td class="zona zona--cuerpo">

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

    </td></tr>
    <tr><td class="zona zona--pie">

    <table class="pie">
        <tr>
            <td class="pie-codigo">{$e($codigo)}</td>
            <td class="pie-fecha">{$e($fecha)}</td>
        </tr>
    </table>

    </td></tr>
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
    private static function tamanoQueQuepa(
        string $texto,
        float $base,
        int $porLinea,
        float $suelo = 0.7
    ): float {
        $largo = mb_strlen($texto);

        if ($largo <= $porLinea) {
            return $base;
        }

        /*
         * Nunca por debajo del suelo: más pequeño deja de leerse a un metro de
         * distancia, que es justo para lo que sirve el carné.
         *
         * El suelo se puede aflojar por campo porque no todos se leen igual. El
         * nombre conserva el 70% —es el dato que se lee en la fila de la
         * puerta—, pero la procedencia baja al 65%, y esa diferencia de cinco
         * puntos no es cosmética: el nombre oficial de una I.E. peruana pasa de
         * los 70 caracteres («Institución Educativa Emblemática 86002 Virgen de
         * Fátima de Independencia»), y al 70% se quedaba en 4.34 pt ocupando
         * 59.8 mm de los 57.7 disponibles. Saltaba a dos líneas por dos
         * milímetros, y esa línea de más partía la hoja de diez en dos páginas.
         * Al 65% entra en una, y sigue en el mismo orden de tamaño que los
         * rótulos de 4.6 pt que ya lleva el carné.
         */
        return max(round($base * $suelo, 1), round($base * $porLinea / $largo, 1));
    }

    /**
     * Cuerpo que hace caber el nombre del concurso en una línea.
     *
     * Va aparte de tamanoQueQuepa() porque el criterio es distinto: allí se
     * cuentan caracteres contra un cupo calibrado; aquí se compara el ancho
     * estimado del texto contra los milímetros que el escudo deja libres, que
     * es un número que cambia con el tamaño del escudo.
     *
     * El 3% que se descuenta es el margen por composición: el ancho medio por
     * carácter se midió sobre el nombre del concurso actual, y un nombre con
     * más letras anchas —M, W, mayúsculas seguidas— ocupa algo más a igual
     * número de caracteres.
     */
    private static function titularQueQuepa(string $texto, float $disponibleMm): float
    {
        $necesarioMm = mb_strlen($texto) * self::CONCURSO_MM_POR_CAR;

        /*
         * El objetivo son DOS líneas, no una. Cuando la cabecera fluía pegada
         * al cuerpo, la segunda línea costaba 2.6 mm de altura y partía la hoja,
         * así que había que encoger el titular hasta meterlo en una. Con la
         * franja de altura fija (D-35) esa altura ya está pagada: dos líneas
         * caben dentro de los mismos milímetros, y el nombre del concurso
         * conserva su cuerpo completo en vez de bajar a 5.9 pt para nada.
         */
        if ($necesarioMm <= $disponibleMm * 2) {
            return self::CONCURSO_PT;
        }

        $ajustado = round(self::CONCURSO_PT * ($disponibleMm * 2 * 0.97) / $necesarioMm, 1);

        /*
         * Ni al cuerpo mínimo entra: el nombre del concurso ocupará dos líneas
         * y la cabecera crecerá ~2.6 mm. El carné se genera igual —dos líneas
         * se leen perfectamente—, pero queda constancia, porque una hoja de
         * diez puede pasar a partirse en dos páginas y eso no se descubre hasta
         * tener el papel delante. Mismo criterio que el aviso del QR: es un
         * problema del dato, no del maquetado, y se arregla acortando el nombre
         * del concurso.
         */
        if ($ajustado < self::CONCURSO_PT_MINIMO) {
            error_log(sprintf(
                'Carné: el nombre del concurso «%s» (%d caracteres) no cabe en la cabecera '
                . 'ni a %.1f pt en dos líneas. Se imprimirá igual, pero acórtalo o comprueba '
                . 'que la hoja de diez siga entrando en un A4.',
                $texto, mb_strlen($texto), self::CONCURSO_PT_MINIMO
            ));
        }

        return max(self::CONCURSO_PT_MINIMO, $ajustado);
    }
    private static function css(?string $marca): string
    {
        $ancho = self::CARNE_ANCHO_MM;
        $alto  = self::CARNE_ALTO_MM;

        $silencio = self::QR_SILENCIO_MM;

        /*
         * Ancho de la columna del escudo. Si el archivo no está, la cabecera
         * cae a su forma de una sola columna y estos valores quedan a cero sin
         * dejar un hueco fantasma.
         */
        $escudo    = self::escudo();
        $colEscudo = $escudo === null ? 0 : $escudo['ancho'];
        $sepEscudo = $escudo === null ? 0 : self::ESCUDO_SEPARACION_MM;

        /*
         * Altura interior del carné: la de la celda, menos el padding vertical
         * de `.carne`, menos el grosor de los dos filetes.
         *
         * Ese último descuento no es un número de ajuste: el modelo de caja de
         * Dompdf es content-box, así que el borde de la cabecera (1 pt) y el del
         * pie (0.5 pt) se suman **por encima** de la altura declarada. Sin
         * descontarlos el carné salía 0.53 mm más alto de lo que dice medir, y
         * multiplicado por las cinco filas de la hoja son 2.7 mm que se comen el
         * margen de corte. Verificado midiendo la distancia entre las guías en
         * el propio PDF: 53.98 mm.
         */
        $filetes  = round((1 + 0.5) * 25.4 / 72, 3);
        $interior = round(self::CARNE_ALTO_MM - 2 * 2.0 - $filetes, 2);
        $zonaCab   = self::ZONA_CAB_MM;
        $zonaPie   = self::ZONA_PIE_MM;
        $zonaCuerpo = self::ZONA_CUERPO_MM;
        $zonaCuerpo = round($interior - $zonaCab - $zonaPie, 2);

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
    /* Marco: tres zonas de altura gobernada                               */
    /* ------------------------------------------------------------------ */

    /* El carné se reparte en cabecera, cuerpo y pie con la altura fijada de
       antemano (D-35). Antes las tres zonas fluían una detrás de otra desde
       arriba, y eso tenía dos consecuencias que se ven en el papel:

         · el pie quedaba pegado al cuerpo, así que su distancia al borde
           inferior dependía de lo largo que fuera el nombre del estudiante, y
           dos carnés de la misma hoja no se parecían;
         · cualquier dato más largo de lo previsto empujaba la altura del carné
           y, con cinco filas por hoja, mandaba la última a una página nueva.

       Con la altura repartida de antemano cada franja se apoya en su extremo y
       el cuerpo trabaja dentro de lo que queda, así que la hoja deja de depender
       del largo de los datos.

       El `height` de la fila del cuerpo es lo que obliga a la tabla a ocupar
       todo el alto declarado. Sin él, Dompdf no la estira —trata el `height` de
       la tabla como contenido— y el sobrante quedaba debajo del pie, que es
       como el código acabó flotando a 7 mm del canto (D-36). */
    .marco {
        width: 100%;
        height: {$interior}mm;
        border-collapse: collapse;
    }

    .marco > tr > td, .zona { padding: 0; }

    /* La cabecera centra su contenido en la franja: el escudo la llena casi
       entera y queda con el mismo aire arriba y abajo. */
    /* Las celdas no llevan ni altura ni filete: el reparto de la tabla las
       estira cuando sobra espacio, y con ellos dentro el filete se movía con la
       celda —así acabó a 11.5 mm del canto en vez de a 6—. La celda solo dice
       en qué extremo se apoya su franja; la altura y la línea viven dentro. */
    .zona--cab { vertical-align: top; }

    /* El pie se apoya en el canto inferior: es una sola línea de 2.3 mm y
       centrarla en su franja la dejaba flotando a 7 mm del borde, justo lo
       contrario de lo que un pie de página debe parecer. */
    .zona--pie { vertical-align: bottom; }

    /* El cuerpo lleva altura explícita, y no se deja al reparto automático, por
       una razón que costó una medición descubrir: `height` en una celda es un
       MÍNIMO, no una medida, así que Dompdf reparte el espacio sobrante entre
       las filas y engordaba la del pie —el filete acababa a 11.5 mm del canto
       en vez de a 6—. Con las tres alturas sumando exactamente el interior del
       carné no queda sobrante que repartir, y cada franja mide lo que dice. */
    .zona--cuerpo { height: {$zonaCuerpo}mm; vertical-align: middle; }

    /* ------------------------------------------------------------------ */
    /* Cabecera: identidad del evento                                      */
    /* ------------------------------------------------------------------ */

    /* El escudo vuelve a la cabecera por decisión del propietario (D-33), tras
       haberse quitado en D-27. Lo que cambia respecto de entonces no es el
       tamaño sino la maqueta: antes ocupaba una fila propia encima del texto y
       le cobraba su altura al cuerpo; ahora comparte fila con la identidad del
       evento, así que los milímetros que impone se reparten entre los dos.

       El borde y el padding viven en el div y no en la tabla: en Dompdf, un
       borde sobre un elemento de tabla se dibuja de forma desigual según haya
       o no `border-collapse`, y aquí la cabecera lleva su propia tabla. */

    /* Aquí vive la altura de la franja superior, y aquí se dibuja su filete: al
       estar en un elemento de altura fija, la línea cae siempre a los mismos
       milímetros del canto, sin depender del alto del escudo ni de cuántas
       líneas ocupe el nombre del concurso. */
    .cab {
        height: {$zonaCab}mm;
        border-bottom: 1pt solid #1d4ed8;
    }

    .cab-marco { width: 100%; height: 100%; border-collapse: collapse; }

    /* La columna del escudo se fija al ancho exacto de la imagen más su aire:
       sin ancho declarado, Dompdf reparte la tabla a partes iguales y el escudo
       se lleva media cabecera. */
    .cab-escudo {
        width: {$colEscudo}mm;
        padding: 0 {$sepEscudo}mm 0 0;
        vertical-align: middle;
        /* line-height 0: la imagen es un elemento en línea y arrastra debajo el
           hueco del descender de la fuente. Es medio milímetro invisible en
           pantalla que en una hoja de diez carnés se multiplica por cinco filas
           y basta para empujar la última a una página nueva. */
        line-height: 0;
    }

    /* Centrado vertical y no arriba: el escudo es más alto que las dos líneas
       de texto, y alinearlo por arriba dejaba el bloque de texto colgando con
       todo el aire debajo. */
    .cab-texto { vertical-align: middle; padding: 0; }

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

    /* border-collapse y padding cero por lo mismo que en `.trio`: sin ellos
       Dompdf aplica el relleno y el espaciado por defecto de las tablas HTML, y
       eso son milímetros que nadie ve pero que el reparto de altura sí paga. */
    .cuerpo { width: 100%; border-collapse: collapse; }
    .cuerpo > tr > td { padding: 0; }

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
       escaso.

       Los porcentajes no son estéticos: son el ancho que necesita el valor más
       largo de cada columna, medido con las métricas de la fuente a 7.2 pt
       sobre los 57.7 mm de la columna de datos.

         · DNI       «CE1234567890» (extranjería, 12 dígitos) → 21.3 mm
         · Grado     «1° Secundaria»                          → 20.0 mm
         · Modalidad «Privada»                                → 10.9 mm

       El reparto anterior —34 / 36 / 30— daba 19.6 mm al DNI y 20.8 mm al
       grado, y **los dos primeros partían en dos líneas**: el de extranjería
       siempre, y el grado en cuanto era de secundaria. Cada línea de más suma
       altura al carné, y con cinco filas por hoja bastaba para que la última se
       fuera a una página nueva. Estaba en el código como si se hubiera medido;
       no lo estaba.

       `padding: 0` en las celdas no es cosmético: sin él, Dompdf aplica el
       relleno por defecto de las tablas HTML y se pierde algo más de un
       milímetro y medio repartido entre las tres columnas, que es justo el
       margen que separa a «1° Secundaria» de partirse. */
    .trio { width: 100%; border-collapse: collapse; }
    .trio td    { padding: 0; }
    .trio-dni   { width: 38%; }
    .trio-grado { width: 36%; }

    /* ------------------------------------------------------------------ */
    /* Pie: código y fecha                                                 */
    /* ------------------------------------------------------------------ */

    /* Simétrico a `.cab`: la franja del pie lleva su altura y su filete. Sus
       celdas se alinean abajo para que el código y la fecha queden apoyados en
       el canto inferior del carné. */
    .pie {
        width: 100%;
        height: {$zonaPie}mm;
        border-top: .5pt solid #dde3ea;
        border-collapse: collapse;
    }

    .pie td { padding: 0; vertical-align: bottom; }

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
