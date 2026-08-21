<?php

declare(strict_types=1);

/**
 * Comprobación de D-37 sobre la base real, TODO dentro de una transacción que
 * se revierte al final: no queda ni una fila.
 */

$raiz = 'C:/xampp/htdocs/compite';
require $raiz . '/vendor/autoload.php';

use App\Models\Concurso;
use App\Models\Inscripcion;
use Core\Config;
use Core\Database;



$ok = 0;
$mal = 0;
$comprobar = static function (string $caso, $esperado, $obtenido) use (&$ok, &$mal): void {
    if ($esperado === $obtenido) {
        $ok++;
        echo "  OK   {$caso} => " . var_export($obtenido, true) . "\n";
    } else {
        $mal++;
        echo "  FALLA {$caso}: esperaba " . var_export($esperado, true)
            . ", obtuvo " . var_export($obtenido, true) . "\n";
    }
};

$pdo = Database::conexion();
$pdo->beginTransaction();

try {
    $concurso = Concurso::vigente();
    echo "Concurso: {$concurso['nombre']}\n";
    echo "I.E. anfitriona enlazada: "
        . var_export($concurso['organizacion_institucion_id'], true) . "\n\n";

    // --- 1. La derivación de la modalidad -------------------------------
    echo "1) Concurso::modalidad()\n";
    // La I.E. anfitriona se descarta al elegir los casos. Si le toca, el
    // sistema responde 'organizadora' —que es lo correcto por D-37— y esta
    // prueba lo leería como un fallo suyo. Pasó de verdad el 20-ago, al
    // traer los datos reales: la primera privada por id ES el anfitrión.
    $anfitriona = $concurso['organizacion_institucion_id'] !== null
        ? (int) $concurso['organizacion_institucion_id']
        : 0;

    $ies = Database::todos('SELECT id, nombre, tipo FROM instituciones_educativas ORDER BY id');
    $elegir = static function (string $tipo) use ($ies, $anfitriona): ?array {
        foreach ($ies as $ie) {
            if ($ie['tipo'] === $tipo && (int) $ie['id'] !== $anfitriona) {
                return $ie;
            }
        }
        return null;
    };
    $publica = $elegir('publica');
    $privada = $elegir('privada');

    // Sin caso no hay prueba: más vale decirlo que reventar más abajo con un
    // 'array offset on null' que no explica nada.
    if ($publica === null || $privada === null) {
        throw new RuntimeException(
            'El catalogo necesita al menos una I.E. publica y una privada'
            . ' que no sean la anfitriona.'
        );
    }

    $comprobar('libre (sin colegio)', 'libre', Concurso::modalidad($concurso, null));
    $comprobar('colegio privado',     'privada', Concurso::modalidad($concurso, $privada));
    $comprobar('colegio publico',     'publica', Concurso::modalidad($concurso, $publica));

    // Ahora se enlaza el colegio publico como anfitrion, dentro de la
    // transaccion, para ver que el mismo colegio cambia de modalidad.
    Database::ejecutar(
        'UPDATE organizaciones SET institucion_id = :ie WHERE id = :org',
        ['ie' => $publica['id'], 'org' => $concurso['organizacion_id']]
    );
    $concursoAnfitrion = Concurso::vigente();

    $comprobar('el anfitrion pasa a organizadora', 'organizadora',
        Concurso::modalidad($concursoAnfitrion, $publica));
    $comprobar('el colegio privado sigue privada', 'privada',
        Concurso::modalidad($concursoAnfitrion, $privada));

    // --- 2. La tarifa propia --------------------------------------------
    echo "\n2) Concurso::tarifa()\n";
    $comprobar('tarifa organizadora', 10.0, Concurso::tarifa((int) $concurso['id'], 'organizadora'));
    $comprobar('tarifa publica',      10.0, Concurso::tarifa((int) $concurso['id'], 'publica'));
    $comprobar('tarifa privada',      15.0, Concurso::tarifa((int) $concurso['id'], 'privada'));

    // --- 3. El alta guarda la modalidad ---------------------------------
    echo "\n3) Inscripcion::crear() guarda tipo_origen\n";
    $base = Database::uno(
        'SELECT i.participante_id, i.categoria_id, i.usuario_id
           FROM inscripciones i LIMIT 1'
    );

    $id = Inscripcion::crear([
        'participante_id' => $base['participante_id'],
        'categoria_id'    => $base['categoria_id'],
        'usuario_id'      => $base['usuario_id'],
        'tipo_origen'     => 'organizadora',
        'monto'           => 10.00,
    ]);
    $creada = Database::uno('SELECT tipo_origen, monto FROM inscripciones WHERE id = :id', ['id' => $id]);
    $comprobar('modalidad guardada', 'organizadora', $creada['tipo_origen']);

    // --- 4. Sin modalidad tiene que fallar, no colarse -------------------
    echo "\n4) Sin tipo_origen el alta se niega (no entra a medias)\n";
    foreach ([null, '', 'cociap'] as $malo) {
        try {
            Inscripcion::crear([
                'participante_id' => $base['participante_id'],
                'categoria_id'    => $base['categoria_id'],
                'usuario_id'      => $base['usuario_id'],
                'tipo_origen'     => $malo,
                'monto'           => 10.00,
            ]);
            $comprobar('rechaza modalidad ' . var_export($malo, true), true, false);
        } catch (InvalidArgumentException $e) {
            $comprobar('rechaza modalidad ' . var_export($malo, true), true, true);
        }
    }

    // --- 5. Las bolsas de competencia (D-54) ----------------------------
    //
    // Hasta D-54 este bloque era un `CASE` dentro de un `printf`: imprimía un
    // resumen bonito y no comprobaba absolutamente nada. La regla que decide
    // quién gana un premio no tenía ni una aserción encima.
    echo "\n5) Concurso::bolsa() — la regla de D-54\n";

    $comprobar('privada y libre comparten bolsa', true,
        Concurso::bolsa('privada') === Concurso::bolsa('libre'));

    // El corazón de D-37: si estas dos colapsaran, el acta daría UN ganador
    // donde las bases dicen dos.
    $comprobar('publica NO comparte bolsa con privada+libre', true,
        Concurso::bolsa('publica') !== Concurso::bolsa('privada'));
    $comprobar('organizadora NO comparte bolsa con publica', true,
        Concurso::bolsa('organizadora') !== Concurso::bolsa('publica'));
    $comprobar('organizadora NO comparte bolsa con privada+libre', true,
        Concurso::bolsa('organizadora') !== Concurso::bolsa('privada'));

    $comprobar('las bolsas distintas son exactamente 3', 3,
        count(array_unique(array_map(
            static fn (string $m): string => Concurso::bolsa($m),
            ['privada', 'libre', 'publica', 'organizadora']
        ))));

    $comprobar('rotulo de privada+libre', 'Privada + Libre',
        Concurso::etiquetaBolsa(Concurso::bolsa('privada')));

    // --- 5b. El ENUM de la base y el match de PHP, sincronizados ---------
    //
    // Si alguien añade una modalidad al ENUM y se olvida de `bolsa()`, el acta
    // reventaría en producción —donde los errores no se ven— y con el concurso
    // encima. Esta comprobación lee el ENUM real y exige que el dominio sepa
    // responder a cada valor, así que el olvido se caza aquí y no allá.
    echo "\n5b) Ninguna modalidad del ENUM se queda sin bolsa\n";
    $columna = Database::uno(
        "SELECT COLUMN_TYPE AS tipo
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = 'inscripciones'
            AND COLUMN_NAME  = 'tipo_origen'"
    );
    preg_match_all("/'([^']+)'/", (string) $columna['tipo'], $coincidencias);
    $modalidadesEnum = $coincidencias[1];

    $comprobar('el ENUM trae las 4 modalidades conocidas',
        ['publica', 'privada', 'libre', 'organizadora'], $modalidadesEnum);

    foreach ($modalidadesEnum as $modalidad) {
        try {
            $bolsa = Concurso::bolsa($modalidad);
            $comprobar("{$modalidad} tiene bolsa", true, $bolsa !== '');
        } catch (RuntimeException $e) {
            $comprobar("{$modalidad} tiene bolsa", true, false);
        }
    }

    // --- 5c. El reparto real, ya derivado por el dominio -----------------
    echo "\n5c) Reparto de los inscritos vivos (agrupado por el dominio)\n";
    $vivas = Database::todos(
        "SELECT cat.nivel, cat.grado, i.tipo_origen
           FROM inscripciones i
           JOIN participantes p ON p.id = i.participante_id
           JOIN categorias cat  ON cat.id = i.categoria_id
          WHERE p.concurso_id = :con AND i.estado <> 'anulada'
       ORDER BY FIELD(cat.nivel,'primaria','secundaria'), cat.grado",
        ['con' => (int) $concurso['id']]
    );

    $reparto = [];
    foreach ($vivas as $fila) {
        $clave = $fila['nivel'] . ' ' . $fila['grado'] . '°';
        $bolsa = Concurso::bolsa((string) $fila['tipo_origen']);
        $reparto[$clave][$bolsa] = ($reparto[$clave][$bolsa] ?? 0) + 1;
    }

    $sumaBolsas = 0;
    foreach ($reparto as $categoria => $porBolsa) {
        foreach (Concurso::bolsas() as $bolsa => $rotulo) {
            $n = $porBolsa[$bolsa] ?? 0;
            if ($n > 0) {
                // `%-16s` cuenta BYTES, y «Pública» lleva un acento de dos:
                // la columna salía torcida. El relleno se calcula en caracteres.
                $relleno = str_repeat(' ', max(0, 16 - mb_strlen($rotulo)));
                printf("  %-14s %s%s%d%s\n", $categoria, $rotulo, $relleno, $n,
                    $n === 1 ? '   <- bolsa de UNO' : '');
            }
            $sumaBolsas += $n;
        }
    }

    // Que nadie se pierda ni se cuente dos veces al repartir: si una modalidad
    // cayera fuera de las tres bolsas, este total no cuadraría.
    $comprobar('el reparto no pierde ni duplica a nadie', count($vivas), $sumaBolsas);
} finally {
    $pdo->rollBack();
    echo "\nTransaccion revertida: la base queda como estaba.\n";
}

echo "\n{$ok} correctas, {$mal} fallidas\n";
exit($mal === 0 ? 0 : 1);
