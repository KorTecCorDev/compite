<?php

declare(strict_types=1);

/**
 * Los nombres propios salen uniformes en MAYÚSCULAS (D-58).
 *
 * Lo que se vigila aquí es doble:
 *
 * 1. Que la normalización esté **al mostrar y no al guardar**. Si algún día
 *    alguien la mete en el alta, «De la Cruz» se pierde para siempre y ninguna
 *    capitalización automática sabe reconstruirlo. Esta prueba comprueba que la
 *    base sigue conservando las formas originales.
 * 2. Que los DOS documentos oficiales —carné y acta— coincidan entre sí, que es
 *    la incoherencia real que D-58 vino a cerrar.
 */

require __DIR__ . '/_comun.php';

use App\Models\Concurso;
use App\Models\Inscripcion;
use App\Models\Participante;
use App\Servicios\GeneradorActa;
use Core\Database;
use Core\Texto;
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

$pdo = Database::conexion();
$pdo->beginTransaction();

try {
    // --- 1. El helper ------------------------------------------------------
    echo "1) Core\\Texto::nombrePropio()\n";

    $c('pone en mayúsculas', 'RODRIGUEZ CAMILO', Texto::nombrePropio('Rodriguez Camilo'));
    $c('respeta la Ñ', 'ÑOPO ÑIQUÉN', Texto::nombrePropio('Ñopo Ñiquén'));
    $c('conserva las tildes, como manda la RAE', 'RAMÍREZ', Texto::nombrePropio('Ramírez'));
    $c('colapsa espacios repetidos', 'JUAN PEREZ', Texto::nombrePropio('Juan   Perez'));
    $c('recorta los extremos', 'MIA', Texto::nombrePropio('  Mia  '));
    $c('aguanta null', '', Texto::nombrePropio(null));

    // Idempotente: aplicarlo dos veces da lo mismo. Es lo que hace segura esta
    // transformación frente a una capitalización «inteligente», que al segundo
    // pase puede devolver algo distinto.
    $c('es idempotente', Texto::nombrePropio('De la Cruz'),
        Texto::nombrePropio(Texto::nombrePropio('De la Cruz')));

    // --- 2. La base NO se toca --------------------------------------------
    echo "\n2) Los datos originales siguen intactos\n";

    // «De la Cruz» es el caso que se perdería si alguien normalizara al
    // guardar: ninguna capitalización automática lo reconstruye —MB_CASE_TITLE
    // devuelve «De La Cruz»—, así que su presencia en la base es la prueba de
    // que la normalización sigue siendo solo de presentación.
    $conMinusculas = (int) Database::uno(
        'SELECT COUNT(*) AS n FROM participantes
          WHERE BINARY CONCAT(ap_paterno, ap_materno, nombres)
             <> BINARY UPPER(CONCAT(ap_paterno, ap_materno, nombres))'
    )['n'];

    $c('la base conserva nombres con minúsculas', true, $conMinusculas > 0);

    // --- 3. Carné y acta dicen lo MISMO ------------------------------------
    echo "\n3) El carné y el acta coinciden entre sí\n";

    $concurso   = Concurso::vigente();
    $concursoId = (int) $concurso['id'];
    $inscritos  = Inscripcion::paraActa($concursoId);

    if ($inscritos === []) {
        throw new RuntimeException('No hay confirmadas: la prueba no puede comparar documentos.');
    }

    $libros = GeneradorActa::libros($concurso, Concurso::categorias($concursoId), $inscritos);

    // Se busca en el acta el nombre de un participante concreto y se compara
    // con lo que el carné pinta para ese mismo código.
    $muestra = $inscritos[0];
    $codigo  = (string) $muestra['codigo_correlativo'];

    $enActa = null;

    foreach ($libros as $bytes) {
        $ruta = sys_get_temp_dir() . '/mayus-' . bin2hex(random_bytes(4)) . '.xlsx';
        file_put_contents($ruta, $bytes);
        $libro = IOFactory::load($ruta);
        unlink($ruta);

        foreach ($libro->getAllSheets() as $hoja) {
            foreach (range(1, $hoja->getHighestRow()) as $f) {
                if (trim((string) $hoja->getCell('B' . $f)->getValue()) === $codigo) {
                    $enActa = trim((string) $hoja->getCell('D' . $f)->getValue());
                }
            }
        }
    }

    $esperado = Texto::nombrePropio($muestra['ap_paterno'] . ' ' . $muestra['ap_materno'])
        . ', ' . Texto::nombrePropio((string) $muestra['nombres']);

    $c('el acta trae el nombre en mayúsculas', $esperado, $enActa);

    // El carné arma el nombre con el mismo helper: se comprueba que su código
    // lo llama, porque generar un PDF y leerle el texto es mucho más frágil que
    // comprobar el contrato.
    $carne = (string) file_get_contents(\Core\Config::ruta('app/Servicios/GeneradorCarne.php'));

    $c('el carné normaliza los apellidos', true,
        str_contains($carne, "Texto::nombrePropio((\$d['ap_paterno'] ?? '')"));
    $c('el carné normaliza los nombres', true,
        str_contains($carne, "\$nombres   = Texto::nombrePropio("));
    $c('el carné normaliza la procedencia', true,
        str_contains($carne, "\$origen   = Texto::nombrePropio("));

    // --- 4. La institución del acta, en mayúsculas ------------------------
    echo "\n4) La institución también\n";

    $conInstitucion = null;

    foreach ($inscritos as $fila) {
        if (trim((string) ($fila['institucion'] ?? '')) !== '') {
            $conInstitucion = $fila;
            break;
        }
    }

    $c('hay alguien con institución (si no, no se probaría nada)', true, $conInstitucion !== null);

    /*
     * Se lee la celda del ACTA, no se recalcula el valor esperado con el mismo
     * helper: comparar `Texto::nombrePropio($x)` con `mb_strtoupper($x)` habría
     * dado verde siempre sin comprobar que el generador llama al helper.
     */
    $institucionesEnActa = [];

    foreach ($libros as $bytes) {
        $ruta = sys_get_temp_dir() . '/mayus-ie-' . bin2hex(random_bytes(4)) . '.xlsx';
        file_put_contents($ruta, $bytes);
        $libro = IOFactory::load($ruta);
        unlink($ruta);

        foreach ($libro->getAllSheets() as $hoja) {
            foreach (range(1, $hoja->getHighestRow()) as $f) {
                $codigo = trim((string) $hoja->getCell('B' . $f)->getValue());

                if ($codigo === '' || $codigo === 'Código') {
                    continue;
                }

                $institucionesEnActa[] = trim((string) $hoja->getCell('E' . $f)->getValue());
            }
        }
    }

    $conMinuscula = array_filter(
        $institucionesEnActa,
        static fn (string $ie): bool => $ie !== mb_strtoupper($ie, 'UTF-8')
    );

    $c('el acta trae instituciones', true, $institucionesEnActa !== []);
    $c('y ninguna con minúsculas', [], array_values($conMinuscula));

    // El dato original SÍ tiene minúsculas: es lo que demuestra que la
    // transformación la hizo el generador y no venía hecha de la base.
    $c('mientras la base las conserva con minúsculas', true,
        (string) $conInstitucion['institucion'] !== mb_strtoupper((string) $conInstitucion['institucion'], 'UTF-8'));

    // --- 5. Las tablas llevan la clase, y los inputs NO -------------------
    echo "\n5) En pantalla: clase en las tablas, nada en los inputs\n";

    $c('la clase existe en el CSS compilado', true, str_contains(
        (string) file_get_contents(\Core\Config::ruta('public/build/css/app.css')),
        '.mayus{text-transform:uppercase}'
    ));

    iniciarSesionComo('administrador');

    $listado = View::renderizar('inscripciones.index', [
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

    $c('el listado marca la columna de nombres', true,
        str_contains($listado, 'tabla__principal mayus'));

    /*
     * Lo que NO puede pasar: `text-transform` sobre un campo de captura. El
     * usuario vería mayúsculas mientras teclea y se enviaría lo que escribió, así
     * que la pantalla estaría mintiendo sobre lo que se va a guardar.
     */
    $formulario = View::renderizar('inscripciones.libre', [
        'titulo'     => 'Estudiante libre',
        'concurso'   => $concurso,
        'categorias' => Concurso::categorias($concursoId),
        'tarifa'     => Concurso::tarifa($concursoId, 'libre'),
        'valores'    => [],
        'errores'    => [],
    ], 'principal');

    $c('ningún input lleva la clase de mayúsculas', false,
        (bool) preg_match('/<input[^>]*class="[^"]*\bmayus\b/', $formulario));
    $c('ningún textarea tampoco', false,
        (bool) preg_match('/<textarea[^>]*class="[^"]*\bmayus\b/', $formulario));
} finally {
    $pdo->rollBack();
    echo "\nTransaccion revertida: la base queda como estaba.\n";
}

echo "\n{$ok} correctas, {$mal} fallidas\n";
exit($mal === 0 ? 0 : 1);
