<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Models\Concurso;
use Core\Texto;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Las actas de los jurados, en Excel (Fase 5).
 *
 * **Un libro por bolsa de competencia, y dentro once hojas, una por grado.**
 * La estructura del archivo se corresponde con cómo se premia: cada libro
 * contiene exactamente a quienes compiten entre sí.
 *
 * Eso es deliberado y es la decisión más importante de este servicio. Un libro
 * **por modalidad** habría dado cuatro archivos y habría separado a los
 * privados de los libres, que por D-37 compiten JUNTOS: de cada archivo habría
 * salido un ganador por grado y habría habido **dos ganadores donde las bases
 * dicen uno**. El fallo que D-54 cerró en el código, reintroducido por el
 * reparto de archivos. Por eso los libros se recorren con `Concurso::bolsas()`
 * y nunca con la lista de modalidades.
 *
 * Las otras decisiones, todas del propietario (21 y 22-ago):
 *
 * 1. **Las once hojas salen siempre**, también donde esa bolsa no tiene a
 *    nadie: rotuladas «sin inscritos». Una pestaña que falta se confunde con
 *    un fallo del reporte.
 * 2. **Correctas, Incorrectas, Puntaje y H/E van en blanco**, sin fórmula. El
 *    sistema no calcula ni guarda puntajes: la §9 deja la calificación fuera de
 *    alcance, y una fórmula la pisa cualquiera al escribir encima.
 * 3. **Solo confirmadas**: al acta entra quien pagó.
 * 4. **Ni un dato de dinero.** El acta se fotocopia y circula por las mesas.
 * 5. **Firma el «Comité de Inscripción»**, no el jurado: el sistema certifica
 *    quién está inscrito, no quién califica.
 *
 * **Rendimiento, medido y no supuesto** (22-ago, con filas sintéticas): 1000
 * participantes tardan ~1 s y ocupan 36 MB de pico; 2000, ~1,8 s y 42 MB. El
 * escalado es lineal y con holgura, así que aquí no hay nada que optimizar. Lo
 * que peor escala del sistema son los carnés en PDF, no este reporte.
 */
final class GeneradorActa
{
    /**
     * Columnas de la tabla, en orden.
     *
     * Los anchos están medidos contra los datos reales: el código correlativo
     * ocupa 22 caracteres (`COCIAP2026-0026-ZRK44Z`) y el nombre de institución
     * más largo del catálogo, 31. Con 14 de ancho el código salía cortado justo
     * en la impresión, que es donde este documento se usa.
     */
    private const COLUMNAS = [
        'A' => ['rotulo' => 'N°',                  'ancho' => 4],
        'B' => ['rotulo' => 'Código',              'ancho' => 24],
        'C' => ['rotulo' => 'DNI',                 'ancho' => 11],
        'D' => ['rotulo' => 'Apellidos y nombres', 'ancho' => 38],
        'E' => ['rotulo' => 'Institución',         'ancho' => 34],
        'F' => ['rotulo' => 'Correctas',           'ancho' => 10],
        'G' => ['rotulo' => 'Incorrectas',         'ancho' => 11],
        'H' => ['rotulo' => 'Puntaje',             'ancho' => 9],
        'I' => ['rotulo' => 'H/E',                 'ancho' => 8],
    ];

    /** Las cuatro que el jurado llena a mano. */
    private const A_MANO = ['F', 'G', 'H', 'I'];

    private const ULTIMA = 'I';

    /**
     * Un libro por bolsa: `['acta-privada-libre.xlsx' => bytes, …]`.
     *
     * Devuelve los tres SIEMPRE, incluso si una bolsa se quedara sin nadie en
     * todo el concurso. Un archivo que no aparece obliga a preguntarse si es que
     * no hay inscritos o es que el reporte falló, y el día del concurso esa duda
     * cuesta tiempo que no hay.
     *
     * @param array<string, mixed>             $concurso
     * @param array<int, array<string, mixed>> $categorias las 11, en orden
     * @param array<int, array<string, mixed>> $inscritos  ya ordenados alfabéticamente
     * @return array<string, string>
     */
    public static function libros(array $concurso, array $categorias, array $inscritos): array
    {
        /*
         * Un solo recorrido reparte a todo el mundo en su bolsa y su categoría.
         * La bolsa se pregunta a `Concurso::bolsa()` (D-54) y no se decide aquí:
         * si este servicio reimplantara el agrupamiento habría dos copias de la
         * regla que reparte los premios.
         */
        $reparto = [];

        foreach ($inscritos as $fila) {
            $bolsa     = Concurso::bolsa((string) $fila['tipo_origen']);
            $categoria = (int) $fila['categoria_id'];

            $reparto[$bolsa][$categoria][] = $fila;
        }

        $libros = [];

        foreach (Concurso::bolsas() as $bolsa => $rotulo) {
            $libro = new Spreadsheet();
            $libro->removeSheetByIndex(0);

            foreach ($categorias as $categoria) {
                self::pintarHoja(
                    $libro->createSheet(),
                    $concurso,
                    $categoria,
                    $rotulo,
                    $reparto[$bolsa][(int) $categoria['id']] ?? []
                );
            }

            $libro->setActiveSheetIndex(0);

            $libros[self::nombreArchivo($rotulo)] = self::bytes($libro);

            // El libro puede pesar decenas de MB con miles de filas: soltarlo
            // antes de armar el siguiente mantiene el pico de memoria plano en
            // vez de acumular los tres a la vez.
            $libro->disconnectWorksheets();
            unset($libro);
        }

        return $libros;
    }

    /** Los bytes del `.xlsx`, sin pasar por disco. */
    private static function bytes(Spreadsheet $libro): string
    {
        $escritor = new Xlsx($libro);

        ob_start();
        $escritor->save('php://output');

        return (string) ob_get_clean();
    }

    /**
     * `Privada + Libre` → `acta-privada-libre.xlsx`.
     *
     * El nombre sale del RÓTULO y no del identificador interno, para que el
     * archivo diga «cociap» y no «organizadora»: quien reparte las actas busca
     * la palabra que ve en el carné, no el valor que guarda la base (D-37).
     */
    private static function nombreArchivo(string $rotulo): string
    {
        $sinAcentos = strtr(mb_strtolower($rotulo), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);

        $limpio = trim((string) preg_replace('/[^a-z0-9]+/', '-', $sinAcentos), '-');

        return 'acta-' . $limpio . '.xlsx';
    }

    /**
     * Una hoja: un grado dentro de un libro de bolsa.
     *
     * @param array<string, mixed>             $concurso
     * @param array<string, mixed>             $categoria
     * @param array<int, array<string, mixed>> $filas
     */
    private static function pintarHoja(
        Worksheet $hoja,
        array $concurso,
        array $categoria,
        string $bolsa,
        array $filas
    ): void {
        $etiqueta = (string) $categoria['etiqueta'];

        // El título de pestaña no admite * : / \ ? [ ] y se corta en 31.
        $hoja->setTitle(mb_substr(
            str_replace(['/', '\\', '*', '?', '[', ']', ':'], '-', $etiqueta),
            0,
            31
        ));

        foreach (self::COLUMNAS as $letra => $columna) {
            $hoja->getColumnDimension($letra)->setWidth((float) $columna['ancho']);
        }

        // Altura por defecto de la hoja, en vez de fijarla fila a fila: con mil
        // participantes eso eran mil objetos de dimensión para el mismo valor.
        $hoja->getDefaultRowDimension()->setRowHeight(18);

        $f = self::pintarCabecera($hoja, $concurso, $etiqueta, $bolsa, count($filas));

        if ($filas !== []) {
            $f = self::pintarTabla($hoja, $f, $filas);
        }

        self::pintarFirma($hoja, $f + 2);
        self::configurarImpresion($hoja);
    }

    /**
     * Cabecera del documento. Devuelve la primera fila libre.
     *
     * @param array<string, mixed> $concurso
     */
    private static function pintarCabecera(
        Worksheet $hoja,
        array $concurso,
        string $etiqueta,
        string $bolsa,
        int $total
    ): int {
        /*
         * La bolsa de UN solo participante se avisa en la propia cabecera de la
         * hoja. Quien compite solo gana su bolsa por defecto, y eso hay que
         * verlo al repartir las actas, no descubrirlo en la premiación.
         */
        $cuenta = match (true) {
            $total === 0 => 'sin inscritos',
            $total === 1 => '1 inscrito — COMPITE SOLO, gana su bolsa por defecto',
            default      => $total . ' inscritos',
        };

        $fecha = !empty($concurso['fecha_evento'])
            ? date('d/m/Y', (int) strtotime((string) $concurso['fecha_evento']))
            : '';

        $lineas = [
            [mb_strtoupper((string) $concurso['nombre']), 13, true],
            ['ACTA DE EVALUACIÓN — ' . mb_strtoupper($etiqueta), 12, true],
            ['BOLSA: ' . mb_strtoupper($bolsa) . '   (' . $cuenta . ')', 11, true],
            [trim('Fecha: ' . $fecha . '    Sede: ' . (string) ($concurso['sede'] ?? '')), 10, false],
        ];

        $f = 1;

        foreach ($lineas as [$texto, $tamano, $negrita]) {
            $hoja->mergeCells("A{$f}:" . self::ULTIMA . $f);
            $hoja->setCellValue("A{$f}", $texto);
            $hoja->getStyle("A{$f}")->getFont()->setBold($negrita)->setSize($tamano);
            $hoja->getStyle("A{$f}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $f++;
        }

        return $f + 1;
    }

    /**
     * La tabla de participantes. Devuelve la primera fila libre debajo.
     *
     * Los estilos se aplican **por rango** una sola vez al final, no celda a
     * celda dentro del bucle: con mil filas, pedir el estilo de cada casilla
     * multiplica por nueve el número de objetos que PhpSpreadsheet crea y
     * guarda, y el resultado en pantalla es idéntico.
     *
     * @param array<int, array<string, mixed>> $filas
     */
    private static function pintarTabla(Worksheet $hoja, int $f, array $filas): int
    {
        $encabezado = $f;

        foreach (self::COLUMNAS as $letra => $columna) {
            $hoja->setCellValue($letra . $f, $columna['rotulo']);
        }

        $f++;
        $primera = $f;
        $n = 1;

        foreach ($filas as $fila) {
            $hoja->setCellValue('A' . $f, $n);

            /*
             * El código y el DNI son identificadores, no números: como texto no
             * pierden ceros a la izquierda ni se convierten en notación
             * científica al abrir el archivo.
             */
            $hoja->setCellValueExplicit('B' . $f, (string) $fila['codigo_correlativo'], DataType::TYPE_STRING);
            $hoja->setCellValueExplicit('C' . $f, (string) $fila['dni'], DataType::TYPE_STRING);
            $hoja->setCellValue('D' . $f, self::nombre($fila));
            $hoja->setCellValue('E' . $f, self::institucion($fila));

            $n++;
            $f++;
        }

        $ultima = $f - 1;

        $hoja->getStyle("A{$encabezado}:" . self::ULTIMA . $encabezado)->getFont()->setBold(true);
        $hoja->getStyle("A{$encabezado}:" . self::ULTIMA . $encabezado)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setWrapText(true);

        $hoja->getStyle("A{$primera}:A{$ultima}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $hoja->getStyle("A{$encabezado}:" . self::ULTIMA . $ultima)
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // Las cuatro casillas que se rellenan a mano, con borde más marcado
        // para que se vea dónde hay que escribir.
        foreach (self::A_MANO as $letra) {
            $hoja->getStyle("{$letra}{$primera}:{$letra}{$ultima}")
                ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_MEDIUM);
        }

        return $f;
    }

    /** El pie: una sola línea de firma, del Comité de Inscripción. */
    private static function pintarFirma(Worksheet $hoja, int $f): void
    {
        $hoja->mergeCells("D{$f}:F{$f}");
        $hoja->setCellValue("D{$f}", str_repeat('_', 40));
        $hoja->getStyle("D{$f}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $f++;
        $hoja->mergeCells("D{$f}:F{$f}");
        $hoja->setCellValue("D{$f}", 'Comité de Inscripción');
        $hoja->getStyle("D{$f}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $hoja->getStyle("D{$f}")->getFont()->setBold(true);
    }

    /**
     * Horizontal y ajustada al ancho: con nueve columnas, en vertical la tabla
     * se parte y las casillas de la derecha —las que hay que rellenar— caen en
     * una segunda página.
     */
    private static function configurarImpresion(Worksheet $hoja): void
    {
        $configuracion = $hoja->getPageSetup();
        $configuracion->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $configuracion->setPaperSize(PageSetup::PAPERSIZE_A4);
        $configuracion->setFitToWidth(1);
        $configuracion->setFitToHeight(0);

        // Las cuatro líneas de cabecera se repiten en cada página impresa: sin
        // esto, la segunda hoja de un grado numeroso no dice de qué categoría ni
        // de qué bolsa es, y con mil participantes habrá grados de varias
        // páginas.
        $configuracion->setRowsToRepeatAtTopByStartAndEnd(1, 4);

        $hoja->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.4)->setRight(0.4);
    }

    /**
     * Apellidos y nombres, **todo en mayúsculas** (D-58).
     *
     * Antes solo los apellidos iban en mayúsculas y los nombres salían tal como
     * estuvieran en la base, así que el acta mezclaba «RODRIGUEZ CAMILO, EDWARD
     * FABRIZZIO» con «BRAVO CAMONES, Kerim Elián» según quién hubiera tecleado.
     *
     * @param array<string, mixed> $fila
     */
    private static function nombre(array $fila): string
    {
        $apellidos = Texto::nombrePropio((string) $fila['ap_paterno'] . ' ' . (string) $fila['ap_materno']);

        return $apellidos . ', ' . Texto::nombrePropio((string) $fila['nombres']);
    }

    /**
     * El estudiante libre no tiene colegio, y aun así comparte bolsa con los
     * privados: dejar la casilla vacía obligaría al jurado a adivinar si es un
     * libre o un dato que falta.
     *
     * @param array<string, mixed> $fila
     */
    private static function institucion(array $fila): string
    {
        $institucion = trim((string) ($fila['institucion'] ?? ''));

        return $institucion !== '' ? Texto::nombrePropio($institucion) : 'LIBRE';
    }
}
