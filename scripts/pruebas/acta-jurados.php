<?php

declare(strict_types=1);

/**
 * Las actas de los jurados (Fase 5), comprobadas sobre los datos reales.
 *
 * No se limita a ver que los archivos se generen: los **vuelve a leer** con
 * PhpSpreadsheet y comprueba lo que dicen dentro. Un `.xlsx` que se escribe sin
 * error pero con la gente en la bolsa equivocada pasaría cualquier prueba que
 * solo mirase que hay bytes, y el fallo se descubriría en la premiación.
 *
 * Lo que más se vigila aquí es el reparto en libros: **privada y libre tienen
 * que caer en el MISMO libro** (D-37). Un libro por modalidad los separaría y
 * daría dos ganadores donde las bases dicen uno.
 */

require __DIR__ . '/_comun.php';

use App\Models\Concurso;
use App\Models\Inscripcion;
use App\Servicios\GeneradorActa;
use Core\Database;
use Core\View;
use PhpOffice\PhpSpreadsheet\IOFactory;

$ok = 0;
$mal = 0;
$c = static function (string $caso, $esp, $obt) use (&$ok, &$mal): void {
    if ($esp === $obt) {
        $ok++;
        echo "  OK    {$caso}\n";
    } else {
        $mal++;
        echo "  FALLA {$caso}: esperaba " . var_export($esp, true)
            . ', obtuvo ' . var_export($obt, true) . "\n";
    }
};

/** Recorre las filas de participante de una hoja. */
$participantes = static function ($hoja): array {
    $filas = [];

    foreach (range(1, $hoja->getHighestRow()) as $f) {
        $codigo = trim((string) $hoja->getCell('B' . $f)->getValue());

        if ($codigo !== '' && $codigo !== 'Código') {
            $filas[$f] = $codigo;
        }
    }

    return $filas;
};

$pdo = Database::conexion();
$pdo->beginTransaction();

try {
    $concurso   = Concurso::vigente();
    $concursoId = (int) $concurso['id'];
    $categorias = Concurso::categorias($concursoId);

    // --- 1. La consulta: solo confirmadas, y sin dinero -------------------
    echo "1) Inscripcion::paraActa()\n";

    $inscritos = Inscripcion::paraActa($concursoId);

    $confirmadas = (int) Database::uno(
        "SELECT COUNT(*) AS n FROM inscripciones i
           JOIN participantes p ON p.id = i.participante_id
          WHERE p.concurso_id = :con AND i.estado = 'confirmada'",
        ['con' => $concursoId]
    )['n'];

    $c('trae exactamente las confirmadas', $confirmadas, count($inscritos));

    $prohibidas = ['monto', 'medio_pago', 'yape_codigo_seguridad', 'fecha_pago'];
    $filtradas  = $inscritos === [] ? [] : array_intersect($prohibidas, array_keys($inscritos[0]));
    $c('no expone ningún dato de dinero', [], array_values($filtradas));

    // --- 2. Un libro por bolsa -------------------------------------------
    echo "\n2) GeneradorActa::libros() — uno por bolsa, no por modalidad\n";

    $libros = GeneradorActa::libros($concurso, $categorias, $inscritos);

    $c('son 3 libros, no 4', 3, count($libros));
    $c('con los nombres esperados',
        ['acta-privada-libre.xlsx', 'acta-publica.xlsx', 'acta-cociap.xlsx'],
        array_keys($libros));

    // El nombre sale del rótulo, no del valor de la base: quien reparte las
    // actas busca «cociap», que es lo que ve en el carné (D-37).
    $c('el libro del anfitrión se llama por su rótulo, no «organizadora»',
        true, isset($libros['acta-cociap.xlsx']));

    $abiertos = [];

    foreach ($libros as $archivo => $bytes) {
        $ruta = sys_get_temp_dir() . '/acta-prueba-' . bin2hex(random_bytes(4)) . '.xlsx';
        file_put_contents($ruta, $bytes);
        $abiertos[$archivo] = IOFactory::load($ruta);
        unlink($ruta);
    }

    foreach ($abiertos as $archivo => $libro) {
        $c("{$archivo}: una hoja por grado", count($categorias), $libro->getSheetCount());
    }

    // --- 3. Nadie se pierde ni se cuenta dos veces ------------------------
    echo "\n3) El reparto no pierde a nadie\n";

    $codigos = [];
    $filas   = 0;

    foreach ($abiertos as $libro) {
        foreach ($libro->getAllSheets() as $hoja) {
            foreach ($participantes($hoja) as $codigo) {
                $filas++;
                $codigos[$codigo] = true;
            }
        }
    }

    $c('hay una fila por confirmada, sumando los 3 libros', $confirmadas, $filas);
    $c('y ningún código repetido entre libros', $confirmadas, count($codigos));

    // --- 4. Cada quien en el libro de SU bolsa ----------------------------
    echo "\n4) Cada participante en el libro de su bolsa (D-54)\n";

    $libroDe = [];

    foreach ($inscritos as $fila) {
        $rotulo = Concurso::etiquetaBolsa(Concurso::bolsa((string) $fila['tipo_origen']));
        $libroDe[(string) $fila['codigo_correlativo']] = $rotulo;
    }

    $rotuloDeArchivo = [
        'acta-privada-libre.xlsx' => 'Privada + Libre',
        'acta-publica.xlsx'       => 'Pública',
        'acta-cociap.xlsx'        => 'COCIAP',
    ];

    $malColocados = 0;

    foreach ($abiertos as $archivo => $libro) {
        foreach ($libro->getAllSheets() as $hoja) {
            foreach ($participantes($hoja) as $codigo) {
                if (($libroDe[$codigo] ?? '¿?') !== $rotuloDeArchivo[$archivo]) {
                    $malColocados++;
                }
            }
        }
    }

    $c('ninguno cae en el libro equivocado', 0, $malColocados);

    /*
     * El corazón de D-37, comprobado sobre el archivo y no sobre la teoría:
     * los privados y los libres tienen que estar en el MISMO libro. Si alguna
     * vez alguien reparte por modalidad, esto se pone rojo.
     */
    $privadaLibre = [];

    foreach ($abiertos['acta-privada-libre.xlsx']->getAllSheets() as $hoja) {
        foreach ($participantes($hoja) as $codigo) {
            $privadaLibre[$codigo] = true;
        }
    }

    $privadosFuera = 0;
    $libresFuera   = 0;
    $hayPrivado    = false;
    $hayLibre      = false;

    foreach ($inscritos as $fila) {
        $codigo = (string) $fila['codigo_correlativo'];

        if ((string) $fila['tipo_origen'] === 'privada') {
            $hayPrivado = true;
            $privadosFuera += isset($privadaLibre[$codigo]) ? 0 : 1;
        }

        if ((string) $fila['tipo_origen'] === 'libre') {
            $hayLibre = true;
            $libresFuera += isset($privadaLibre[$codigo]) ? 0 : 1;
        }
    }

    $c('hay privados y libres en los datos (si no, la prueba no probaría nada)',
        true, $hayPrivado && $hayLibre);
    $c('TODOS los privados están en el libro privada+libre', 0, $privadosFuera);
    $c('TODOS los libres están en ese MISMO libro', 0, $libresFuera);

    // --- 5. Hojas vacías y bolsas de uno ----------------------------------
    echo "\n5) Hojas vacías y bolsas de un solo participante\n";

    $vacias = 0;
    $deUno  = 0;

    foreach ($abiertos as $libro) {
        foreach ($libro->getAllSheets() as $hoja) {
            $cabecera = (string) $hoja->getCell('A3')->getValue();

            if (str_contains($cabecera, 'sin inscritos')) {
                $vacias++;
            }

            if (str_contains($cabecera, 'COMPITE SOLO')) {
                $deUno++;
            }
        }
    }

    // Cuántas combinaciones bolsa×categoría deberían salir vacías y cuántas
    // con uno, según los datos.
    $cuenta = [];

    foreach ($inscritos as $fila) {
        $clave = Concurso::bolsa((string) $fila['tipo_origen']) . '|' . (int) $fila['categoria_id'];
        $cuenta[$clave] = ($cuenta[$clave] ?? 0) + 1;
    }

    $totalCombinaciones = count($categorias) * 3;
    $vaciasEsperadas    = $totalCombinaciones - count($cuenta);
    $unoEsperado        = count(array_filter($cuenta, static fn (int $n): bool => $n === 1));

    $c('las hojas sin gente lo dicen', $vaciasEsperadas, $vacias);
    $c('las bolsas de un solo participante avisan', $unoEsperado, $deUno);

    // --- 6. Las cuatro columnas a mano, vacías ----------------------------
    echo "\n6) Correctas / Incorrectas / Puntaje / H/E van EN BLANCO\n";

    $rotulos  = [];
    $conValor = 0;

    foreach ($abiertos as $libro) {
        foreach ($libro->getAllSheets() as $hoja) {
            foreach (range(1, $hoja->getHighestRow()) as $f) {
                if (trim((string) $hoja->getCell('A' . $f)->getValue()) === 'N°') {
                    foreach (['F', 'G', 'H', 'I'] as $col) {
                        $rotulos[trim((string) $hoja->getCell($col . $f)->getValue())] = true;
                    }
                }
            }

            foreach (array_keys($participantes($hoja)) as $f) {
                foreach (['F', 'G', 'H', 'I'] as $col) {
                    if (trim((string) $hoja->getCell($col . $f)->getValue()) !== '') {
                        $conValor++;
                    }
                }
            }
        }
    }

    $c('están las cuatro columnas', ['Correctas', 'Incorrectas', 'Puntaje', 'H/E'],
        array_keys($rotulos));
    $c('y ninguna trae valor ni fórmula', 0, $conValor);

    // --- 7. Cabecera y firma ----------------------------------------------
    echo "\n7) Cabecera y firma\n";

    $hoja  = $abiertos['acta-publica.xlsx']->getSheet(0);
    $texto = '';

    foreach (range(1, $hoja->getHighestRow()) as $f) {
        $texto .= trim((string) $hoja->getCell('A' . $f)->getValue()) . "\n"
                . trim((string) $hoja->getCell('D' . $f)->getValue()) . "\n";
    }

    $c('la cabecera nombra el concurso', true, str_contains($texto, 'COCIAP 2026'));
    $c('dice de qué categoría es la hoja', true, str_contains($texto, 'ACTA DE EVALUACIÓN'));
    $c('y de qué bolsa', true, str_contains($texto, 'BOLSA: PÚBLICA'));
    $c('firma el Comité de Inscripción', true, str_contains($texto, 'Comité de Inscripción'));
    $c('y NO habla de jurados que califican', false, str_contains($texto, 'Jurado 1'));

    // --- 8. El estudiante libre se distingue ------------------------------
    echo "\n8) El libre no queda con la casilla vacía\n";

    $sinInstitucion = 0;
    $rotuladoLibre  = false;

    foreach ($abiertos as $libro) {
        foreach ($libro->getAllSheets() as $hoja) {
            foreach (array_keys($participantes($hoja)) as $f) {
                $institucion = trim((string) $hoja->getCell('E' . $f)->getValue());

                if ($institucion === '') {
                    $sinInstitucion++;
                }

                // En mayúsculas como todo lo demás del acta desde D-58.
                if ($institucion === 'LIBRE') {
                    $rotuladoLibre = true;
                }
            }
        }
    }

    $c('ninguna institución queda en blanco', 0, $sinInstitucion);
    $c('los libres salen rotulados «Libre»', true, $rotuladoLibre);

    // --- 9. El enlace, solo para el administrador -------------------------
    echo "\n9) «Descargar actas» solo lo ve el administrador\n";

    $pintarListado = static function (string $rol) use ($concurso, $concursoId): string {
        iniciarSesionComo($rol);

        return View::renderizar('inscripciones.index', [
            'titulo'        => 'Inscripciones',
            'concurso'      => $concurso,
            'inscripciones' => Inscripcion::listar($concursoId),
            'instituciones' => [],
            'filtros'       => ['institucion_id' => '', 'tipo_origen' => '', 'nivel' => '',
                                'grado' => '', 'estado' => '', 'q' => ''],
            'resumen'       => Inscripcion::resumen($concursoId),
            'total'         => Inscripcion::contarFiltradas($concursoId),
            'tope'          => Inscripcion::TOPE_LISTADO,
        ], 'principal');
    };

    $c('el administrador ve el enlace', true,
        str_contains($pintarListado('administrador'), '/reportes/actas.zip'));
    $c('la secretaria NO lo ve', false,
        str_contains($pintarListado('secretaria'), '/reportes/actas.zip'));
} finally {
    $pdo->rollBack();
    echo "\nTransaccion revertida: la base queda como estaba.\n";
}

echo "\n{$ok} correctas, {$mal} fallidas\n";
exit($mal === 0 ? 0 : 1);
