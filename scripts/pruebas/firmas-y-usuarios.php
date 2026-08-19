<?php
declare(strict_types=1);
require __DIR__ . '/_comun.php';

use App\Models\Concurso;
use App\Models\Inscripcion;
use App\Models\Usuario;
use Core\Database;

$ok = 0; $mal = 0;
$c = static function (string $caso, $esp, $obt) use (&$ok, &$mal): void {
    if ($esp === $obt) { $ok++; echo "  OK    {$caso}\n"; }
    else { $mal++; echo "  FALLA {$caso}: esperaba " . var_export($esp, true) . ", obtuvo " . var_export($obt, true) . "\n"; }
};

$pdo = Database::conexion();
$pdo->beginTransaction();
try {
    $con = (int) Concurso::vigente()['id'];

    // Dos usuarios distintos, para que la firma pueda distinguirlos.
    $ana  = Usuario::crear('Ana Secretaria', 'ana.prueba@cociap.pe', 'clave1234', 'secretaria');
    $beto = Usuario::crear('Beto Secretario', 'beto.prueba@cociap.pe', 'clave1234', 'secretaria');

    // --- Firma del cobro ------------------------------------------------
    $pend = Database::uno("SELECT id FROM inscripciones WHERE estado='pendiente' LIMIT 1");
    Inscripcion::confirmarPago((int) $pend['id'], 'yape', '123', $ana);
    $tras = Inscripcion::porId((int) $pend['id']);
    $c('el cobro queda firmado', $ana, (int) $tras['confirmado_por']);
    $c('y sigue guardando el medio', 'yape', $tras['medio_pago']);

    // --- Firma de la anulación, por OTRA persona ------------------------
    Inscripcion::anular((int) $pend['id'], 'Prueba de firma', true, $beto);
    $tras2 = Inscripcion::porId((int) $pend['id']);
    $c('la anulación queda firmada por quien la hizo', $beto, (int) $tras2['anulado_por']);
    $c('y no borra quién había cobrado', $ana, (int) $tras2['confirmado_por']);
    $c('las dos firmas son personas distintas', true,
        (int) $tras2['anulado_por'] !== (int) $tras2['confirmado_por']);

    // --- El responsable de la inscripción, en el listado ----------------
    $nueva = Inscripcion::crear([
        'participante_id' => (int) $tras['participante_id'], 'categoria_id' => (int) $tras['categoria_id'],
        'usuario_id' => $beto, 'tipo_origen' => $tras['tipo_origen'], 'monto' => (float) $tras['monto'],
    ]);
    $fila = null;
    foreach (Inscripcion::listar($con) as $f) { if ((int) $f['id'] === $nueva) { $fila = $f; } }
    $c('el listado trae el nombre del responsable', 'Beto Secretario', $fila['registrado_por']);
    $c('ninguna fila del listado se queda sin responsable', true,
        !in_array(null, array_column(Inscripcion::listar($con), 'registrado_por'), true));

    // --- Guardas de la pantalla de usuarios -----------------------------
    $c('el correo repetido se detecta', true, Usuario::correoExiste('ana.prueba@cociap.pe'));
    $c('pero no contra uno mismo al editar', false, Usuario::correoExiste('ana.prueba@cociap.pe', $ana));
    $antes = Usuario::administradoresActivos();
    $c('hay al menos un administrador activo', true, $antes >= 1);
    Usuario::cambiarEstado($ana, false);
    $c('desactivar no borra: el usuario sigue existiendo', 0, (int) Usuario::porId($ana)['activo']);
    $c('y su firma sigue resolviendo', 'Ana Secretaria', Usuario::porId($ana)['nombres']);

    Usuario::actualizarPassword($ana, 'otraclave99');
    $c('la contraseña nueva verifica', true,
        password_verify('otraclave99', Usuario::porCorreo('ana.prueba@cociap.pe')['password_hash']));
    $c('y la vieja ya no', false,
        password_verify('clave1234', Usuario::porCorreo('ana.prueba@cociap.pe')['password_hash']));

    Usuario::actualizar($beto, 'Beto Editado', 'beto2.prueba@cociap.pe', 'administrador');
    $u = Usuario::porId($beto);
    $c('editar cambia nombre, correo y rol', ['Beto Editado', 'beto2.prueba@cociap.pe', 'administrador'],
        [$u['nombres'], $u['correo'], $u['rol']]);
    $c('editar NO toca la contraseña', true,
        password_verify('clave1234', Usuario::porCorreo('beto2.prueba@cociap.pe')['password_hash']));
} finally { $pdo->rollBack(); }

echo "\n{$ok} correctas, {$mal} fallidas\n";
exit($mal === 0 ? 0 : 1);
