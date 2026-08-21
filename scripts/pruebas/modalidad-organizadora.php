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

    // --- 5. Las bolsas de competencia -----------------------------------
    echo "\n5) Bolsas (privada+libre | publica | organizadora)\n";
    $bolsas = Database::todos(
        "SELECT cat.nivel, cat.grado,
                CASE i.tipo_origen
                     WHEN 'organizadora' THEN 'COCIAP'
                     WHEN 'publica'      THEN 'Publica'
                     ELSE 'Privada y libre'
                END AS bolsa,
                COUNT(*) AS inscritos
           FROM inscripciones i
           JOIN participantes p ON p.id = i.participante_id
           JOIN categorias cat  ON cat.id = i.categoria_id
          WHERE p.concurso_id = :con AND i.estado <> 'anulada'
       GROUP BY cat.nivel, cat.grado, bolsa
       ORDER BY FIELD(cat.nivel,'primaria','secundaria'), cat.grado, bolsa",
        ['con' => (int) $concurso['id']]
    );
    foreach ($bolsas as $b) {
        printf("  %-11s %d°  %-16s %d\n", $b['nivel'], $b['grado'], $b['bolsa'], $b['inscritos']);
    }
} finally {
    $pdo->rollBack();
    echo "\nTransaccion revertida: la base queda como estaba.\n";
}

echo "\n{$ok} correctas, {$mal} fallidas\n";
exit($mal === 0 ? 0 : 1);
