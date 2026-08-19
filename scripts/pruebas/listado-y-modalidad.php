<?php
declare(strict_types=1);
require __DIR__ . '/_comun.php';

use App\Models\Concurso;
use App\Models\Inscripcion;
use Core\Database;

$ok = 0; $mal = 0;
$c = static function (string $caso, $esp, $obt) use (&$ok, &$mal): void {
    if ($esp === $obt) { $ok++; echo "  OK    {$caso} => " . var_export($obt, true) . "\n"; }
    else { $mal++; echo "  FALLA {$caso}: esperaba " . var_export($esp, true) . ", obtuvo " . var_export($obt, true) . "\n"; }
};

$pdo = Database::conexion();
$pdo->beginTransaction();
try {
    $con = (int) Concurso::vigente()['id'];

    // Se marca UNA inscripción de delegación como organizadora.
    $victima = Database::uno(
        "SELECT i.id FROM inscripciones i JOIN participantes p ON p.id = i.participante_id
          WHERE p.tipo_participante = 'delegacion' AND i.tipo_origen = 'publica' LIMIT 1"
    );
    Database::ejecutar('UPDATE inscripciones SET tipo_origen = :t WHERE id = :id',
        ['t' => 'organizadora', 'id' => $victima['id']]);

    echo "Filtro por modalidad (Inscripcion::listar)\n";
    foreach (['publica', 'privada', 'libre', 'organizadora'] as $m) {
        $filas = Inscripcion::listar($con, ['tipo_origen' => $m]);
        $puras = array_unique(array_column($filas, 'tipo_origen'));
        $c("filtro '{$m}' devuelve solo esa modalidad", [$m], array_values($puras));
    }

    $todas = Inscripcion::listar($con);
    $c('el listado sin filtro trae la modalidad de cada fila', true,
        !in_array(null, array_column($todas, 'tipo_origen'), true));

    echo "\nRótulos\n";
    foreach (['publica' => 'Pública', 'privada' => 'Privada', 'libre' => 'Libre',
              'organizadora' => 'COCIAP', null => '—'] as $valor => $rotulo) {
        $c("etiqueta " . var_export($valor, true), $rotulo,
            Concurso::etiquetaModalidad($valor === '' ? null : (string) $valor));
    }

    echo "\nOrden de tarifas (caja del formulario)\n";
    $c('las cuatro, en orden', ['publica','privada','libre','organizadora'],
        array_column(Concurso::tarifas($con), 'tipo_origen'));
} finally { $pdo->rollBack(); }

echo "\n{$ok} correctas, {$mal} fallidas\n";
exit($mal === 0 ? 0 : 1);
