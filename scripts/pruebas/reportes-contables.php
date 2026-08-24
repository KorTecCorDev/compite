<?php
declare(strict_types=1);
require __DIR__ . '/_comun.php';

use App\Models\Concurso;
use App\Models\Inscripcion;
use App\Models\Usuario;
use Core\Database;
use App\Servicios\Rendicion;
use Core\Fecha;
use Core\View;

/**
 * Reportes contables (D-59) y la firma que sobrevive a la reinscripción (D-60).
 *
 * Lo que se comprueba no es que las cifras «salgan», sino que **cuenten bien**:
 * un reporte de dinero que suma dos veces al mismo estudiante pasaría cualquier
 * prueba que solo mire que hay filas.
 *
 * Todo se mide **por diferencia** contra el estado real de la base, no contra
 * cifras absolutas: en la base de trabajo hay más de cien cobros reales y
 * cualquier número escrito a mano aquí caducaría con el siguiente. La suite
 * crea su propio caso (D-55) y la transacción lo revierte entero.
 */

$ok = 0; $mal = 0;
$c = static function (string $caso, $esp, $obt) use (&$ok, &$mal): void {
    if ($esp === $obt) { $ok++; echo "  OK    {$caso}\n"; }
    else { $mal++; echo "  FALLA {$caso}: esperaba " . var_export($esp, true) . ", obtuvo " . var_export($obt, true) . "\n"; }
};

// Los importes se comparan como texto con dos decimales: `===` sobre floats
// convierte cualquier suma en una lotería de bits.
$s = static fn (mixed $monto): string => number_format((float) $monto, 2, '.', '');

$pdo = Database::conexion();
$pdo->beginTransaction();
try {
    $con = idConcurso();

    $ana  = Usuario::crear('Ana Contable', 'ana.contable@cociap.pe', 'clave1234', 'secretaria');
    $beto = Usuario::crear('Beto Contable', 'beto.contable@cociap.pe', 'clave1234', 'secretaria');

    $base = Inscripcion::saldos($con);

    // ------------------------------------------------------------------
    echo "\n1) Un cobro entra al bruto y queda en firme\n";
    // ------------------------------------------------------------------
    $i1 = inscripcionPendienteDePrueba($ana);
    $m1 = (float) Inscripcion::porId($i1)['monto'];

    $tras = Inscripcion::saldos($con);
    $c('la pendiente sube lo por cobrar', $s($base['por_cobrar']['monto'] + $m1), $s($tras['por_cobrar']['monto']));
    $c('y NO toca el cobrado bruto', $s($base['bruto']), $s($tras['bruto']));

    Inscripcion::confirmarPago($i1, 'yape', '111', $ana);

    $tras = Inscripcion::saldos($con);
    $c('cobrar sube el bruto por su monto', $s($base['bruto'] + $m1), $s($tras['bruto']));
    $c('y lo pone en firme', $s($base['en_firme']['monto'] + $m1), $s($tras['en_firme']['monto']));
    $c('una inscripción más en firme', $base['en_firme']['n'] + 1, $tras['en_firme']['n']);
    $c('lo por cobrar vuelve a donde estaba', $s($base['por_cobrar']['monto']), $s($tras['por_cobrar']['monto']));

    // ------------------------------------------------------------------
    echo "\n2) Anulada PARA REINSCRIBIR: el dinero no desaparece, queda en limbo\n";
    // ------------------------------------------------------------------
    // `esDefinitiva = false` es el botón «anular para reinscribir» (D-15): no
    // marca `requiere_devolucion`, así que este dinero no está en el fondo y
    // hasta D-59 no estaba en ninguna parte.
    Inscripcion::anular($i1, 'Prueba: anulada para reinscribir', false, $ana);

    $tras = Inscripcion::saldos($con);
    $c('sale de «en firme»', $s($base['en_firme']['monto']), $s($tras['en_firme']['monto']));
    $c('entra en «cobrado sin reasignar»', $s($base['sin_reasignar']['monto'] + $m1), $s($tras['sin_reasignar']['monto']));
    $c('y el bruto NO se mueve: la plata sigue en el cajón', $s($base['bruto'] + $m1), $s($tras['bruto']));

    $idsLimbo = array_map('intval', array_column(Inscripcion::cobradoSinReasignar($con), 'id'));
    $idsFondo = array_map('intval', array_column(Inscripcion::fondoDevoluciones($con), 'id'));
    $c('aparece en el listado de cobrado sin reasignar', true, in_array($i1, $idsLimbo, true));
    $c('y NO en el fondo de devoluciones: no hay que devolverla', false, in_array($i1, $idsFondo, true));

    // ------------------------------------------------------------------
    echo "\n3) Reinscrita: el mismo dinero se cuenta UNA sola vez\n";
    // ------------------------------------------------------------------
    // Se replica lo que hace `AnulacionController::reinscribir()`: la fila nueva
    // COPIA medio, código, fecha y —desde D-60— la firma del cobro. Las dos
    // filas quedan con `fecha_pago`, que es la trampa que este caso vigila.
    $anulada = Inscripcion::porId($i1);
    $i2 = Inscripcion::crear([
        'participante_id'       => (int) $anulada['participante_id'],
        'categoria_id'          => (int) $anulada['categoria_id'],
        'usuario_id'            => $beto,
        'estado'                => 'confirmada',
        'tipo_origen'           => $anulada['tipo_origen'],
        'monto'                 => (float) $anulada['monto'],
        'medio_pago'            => $anulada['medio_pago'],
        'yape_codigo_seguridad' => $anulada['yape_codigo_seguridad'],
        'fecha_pago'            => $anulada['fecha_pago'],
        'confirmado_por'        => $anulada['confirmado_por'],
    ]);
    Inscripcion::limpiarDevolucion($i1);

    $c('las dos filas tienen fecha de pago', true,
        Inscripcion::porId($i1)['fecha_pago'] !== null && Inscripcion::porId($i2)['fecha_pago'] !== null);

    $tras = Inscripcion::saldos($con);
    $c('EL BRUTO NO SE DUPLICA', $s($base['bruto'] + $m1), $s($tras['bruto']));
    $c('vuelve a estar en firme', $s($base['en_firme']['monto'] + $m1), $s($tras['en_firme']['monto']));
    $c('y sale del limbo', $s($base['sin_reasignar']['monto']), $s($tras['sin_reasignar']['monto']));

    $idsLimbo = array_map('intval', array_column(Inscripcion::cobradoSinReasignar($con), 'id'));
    $c('la anulada ya no figura como sin reasignar', false, in_array($i1, $idsLimbo, true));

    // ------------------------------------------------------------------
    echo "\n4) D-60 — la firma del cobro sobrevive a la reinscripción\n";
    // ------------------------------------------------------------------
    $c('la fila nueva conserva quién cobró', $ana, (int) Inscripcion::porId($i2)['confirmado_por']);
    $c('y registra a quién la reinscribió', $beto, (int) Inscripcion::porId($i2)['usuario_id']);
    $c('son personas distintas: no se confunden las dos firmas', true, $ana !== $beto);

    $sinFirma = Inscripcion::crear([
        'participante_id' => (int) $anulada['participante_id'],
        'categoria_id'    => (int) $anulada['categoria_id'],
        'usuario_id'      => $beto,
        'estado'          => 'pendiente',
        'tipo_origen'     => $anulada['tipo_origen'],
        'monto'           => (float) $anulada['monto'],
    ]);
    $c('sin cobro no se inventa firma', null, Inscripcion::porId($sinFirma)['confirmado_por']);

    /*
     * Que el modelo sepa guardar la firma no dice que el controlador se la pase:
     * ese era exactamente el defecto de D-60. Como `reinscribir()` redirige y
     * exige CSRF, aquí se comprueba el cableado leyendo la fuente, igual que
     * hace `propiedad-de-registros.php`.
     */
    $fuente = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/AnulacionController.php');
    $cuerpo = substr($fuente, (int) strpos($fuente, 'public function reinscribir('));
    $cuerpo = substr($cuerpo, 0, (int) strpos($cuerpo, 'private function inscripcionReinscribibleOFallar'));
    $c('reinscribir() pasa la firma del cobro', true, str_contains($cuerpo, "'confirmado_por'"));
    $c('y la toma de la inscripción anulada, no de quien reinscribe', true,
        str_contains($cuerpo, "\$inscripcion['confirmado_por']"));

    // ------------------------------------------------------------------
    echo "\n5) Anulada DEFINITIVA de algo pagado: al fondo de devoluciones\n";
    // ------------------------------------------------------------------
    $i3 = inscripcionPendienteDePrueba($beto);
    $m3 = (float) Inscripcion::porId($i3)['monto'];
    Inscripcion::confirmarPago($i3, 'efectivo', null, $beto);
    Inscripcion::anular($i3, 'Prueba: anulación definitiva', true, idAdministrador());

    $tras = Inscripcion::saldos($con);
    $c('marca la devolución', 1, (int) Inscripcion::porId($i3)['requiere_devolucion']);
    $c('suma en «por devolver»', $s($base['por_devolver']['monto'] + $m3), $s($tras['por_devolver']['monto']));
    $c('el bruto la incluye: ese dinero entró', $s($base['bruto'] + $m1 + $m3), $s($tras['bruto']));
    $c('aparece en el fondo de devoluciones', true,
        in_array($i3, array_map('intval', array_column(Inscripcion::fondoDevoluciones($con), 'id')), true));

    // ------------------------------------------------------------------
    echo "\n6) El cuadre: el arqueo y el saldo dicen lo mismo\n";
    // ------------------------------------------------------------------
    $arqueo = Inscripcion::arqueoPorUsuario($con);
    $suma   = 0.0;

    foreach ($arqueo as $fila) {
        $suma += (float) $fila['monto_total'];

        $porMedio = (float) $fila['monto_yape'] + (float) $fila['monto_transferencia'] + (float) $fila['monto_efectivo'];
        $c('cuadra la fila de ' . $fila['cobrador'], $s($fila['monto_total']), $s($porMedio));
    }

    $c('el total del arqueo es el cobrado bruto', $s($tras['bruto']), $s($suma));

    $deAna = Inscripcion::arqueoPorUsuario($con, $ana);
    $c('el arqueo acotado trae una sola caja', 1, count($deAna));
    $c('y es la de quien se pidió', 'Ana Contable', $deAna[0]['cobrador']);
    $c('con lo que cobró ella', $s($m1), $s($deAna[0]['monto_total']));
    $c('en el medio con el que cobró', $s($m1), $s($deAna[0]['monto_yape']));
    $c('y nada en los otros medios', $s(0), $s($deAna[0]['monto_efectivo']));

    // ------------------------------------------------------------------
    echo "\n7) Un cobro masivo se reconstruye como UNA operación\n";
    // ------------------------------------------------------------------
    // Las dos filas se escriben con la MISMA fecha de pago a propósito: es lo
    // que produce una confirmación masiva real, y fijarla evita que la prueba
    // dependa de caer o no dentro del mismo minuto del reloj.
    $momento = date('Y-m-d H:i:s');
    $lote    = [];

    foreach ([0, 1] as $_) {
        $id = inscripcionPendienteDePrueba($ana);
        Inscripcion::confirmarPago($id, 'transferencia', null, $beto);
        Database::ejecutar(
            'UPDATE inscripciones SET fecha_pago = :f WHERE id = :id',
            ['f' => $momento, 'id' => $id]
        );
        $lote[] = $id;
    }

    $mLote  = (float) Inscripcion::porId($lote[0])['monto'];
    $grupo  = null;

    foreach (Inscripcion::operacionesDeCobro($con, $beto) as $op) {
        if ($op['medio_pago'] === 'transferencia' && (int) $op['cantidad'] === 2) {
            $grupo = $op;
        }
    }

    $c('las dos inscripciones salen como una sola operación', true, $grupo !== null);
    $c('con el importe sumado', $s($mLote * 2), $s($grupo['monto'] ?? 0));

    // --- D-64: la operación lleva dentro a quiénes la componen -----------
    $c('la operación lista a sus dos participantes', 2, count($grupo['participantes'] ?? []));
    /*
     * Se comparan como CONJUNTO y no como lista: dentro de una operación los
     * participantes van en orden alfabético a propósito —es como se cotejan
     * contra la nómina de la delegación—, y los dos casos de esta prueba se
     * llaman igual, así que su desempate es arbitrario. Afirmar un orden aquí
     * sería afirmar algo que el código no promete.
     */
    $enFicha = array_map(static fn (array $p): int => (int) $p['id'], $grupo['participantes'] ?? []);
    sort($enFicha);
    $esperados = $lote;
    sort($esperados);
    $c('y los dos son los del lote', $esperados, $enFicha);

    /*
     * Lo que de verdad vigila esta comprobación: que la cabecera de la ficha sea
     * **la suma de lo que hay debajo**. Salen de la misma consulta a propósito
     * (D-64); con dos consultas distintas podrían divergir, y un papel que se
     * firma al entregar dinero no puede decir S/ 300 arriba y listar S/ 290.
     */
    $descuadradas = [];
    $totalOperaciones = 0.0;

    foreach (Inscripcion::operacionesDeCobro($con) as $op) {
        $sumaFicha = 0.0;
        foreach ($op['participantes'] as $p) { $sumaFicha += (float) $p['monto']; }

        $totalOperaciones += (float) $op['monto'];

        if ($s($sumaFicha) !== $s($op['monto']) || count($op['participantes']) !== (int) $op['cantidad']) {
            $descuadradas[] = (string) $op['momento'];
        }
    }

    $c('ninguna ficha discrepa de sus propios participantes', [], $descuadradas);
    $c('y todas las operaciones juntas dan el cobrado bruto',
        $s(Inscripcion::saldos($con)['bruto']), $s($totalOperaciones));

    $c('una operación de un solo colegio no se marca como mezclada', 1,
        count($grupo['procedencias'] ?? []));

    // ------------------------------------------------------------------
    echo "\n8) La secretaria no ve la caja ajena\n";
    // ------------------------------------------------------------------
    $cobradores = array_column(Inscripcion::arqueoPorUsuario($con, $ana), 'cobrador');
    $c('el arqueo de Ana no menciona a Beto', false, in_array('Beto Contable', $cobradores, true));

    $rutas = (string) file_get_contents(dirname(__DIR__, 2) . '/routes/web.php');
    $ctrl  = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/ReporteController.php');

    foreach (['caja', 'saldos', 'devoluciones'] as $accion) {
        $c("la ruta /reportes/{$accion} existe", true, str_contains($rutas, "/reportes/{$accion}'"));
    }

    $trozo = static function (string $desde) use ($ctrl): string {
        $cuerpo = substr($ctrl, (int) strpos($ctrl, $desde));
        $corte  = strpos($cuerpo, '    /**', 10);

        return $corte === false ? $cuerpo : substr($cuerpo, 0, $corte);
    };

    $c('el arqueo exige sesión y acota por rol', true,
        str_contains($trozo('public function caja('), 'Auth::exigirSesion()')
        && str_contains($trozo('public function caja('), 'Auth::esAdministrador()'));
    $c('el estado de la caja es solo del administrador', true,
        str_contains($trozo('public function saldos('), 'Auth::exigirAdministrador()'));
    $c('el fondo de devoluciones también', true,
        str_contains($trozo('public function devoluciones('), 'Auth::exigirAdministrador()'));

    // ------------------------------------------------------------------
    echo "\n9) Las tres pantallas se dibujan de verdad\n";
    // ------------------------------------------------------------------
    /*
     * Se RENDERIZAN, no se leen. Comprobar el código fuente de una vista no
     * ejecuta una sola línea de PHP: un índice mal escrito o un método que no
     * existe pasarían enteros hasta que alguien abriera la pantalla — y estas
     * se abren el día que hay que entregar dinero.
     */
    $concurso = Concurso::vigente();

    iniciarSesionComo('administrador', idAdministrador());

    $caja = View::renderizar('reportes.caja', [
        'titulo' => 'Arqueo', 'columnaAncha' => true, 'concurso' => $concurso, 'esPropia' => false,
        'filas' => Inscripcion::arqueoPorUsuario($con),
        'operaciones' => Inscripcion::operacionesDeCobro($con),
    ], 'principal');

    $c('el arqueo se dibuja', true, str_contains($caja, 'Arqueo de caja'));
    $c('y trae la identidad del documento', true, str_contains($caja, 'reporte-identidad'));
    $c('el administrador ve las tres pantallas en su navegación', true,
        str_contains($caja, '/reportes/saldos') && str_contains($caja, '/reportes/devoluciones'));
    $c('la caja de Ana sale con su nombre', true, str_contains($caja, 'Ana Contable'));
    $c('el pie de firmas va en el papel', true, str_contains($caja, 'Entregué conforme'));

    // D-64: cada operación se dibuja como ficha, con su gente dentro.
    $opsAdmin = Inscripcion::operacionesDeCobro($con);
    $gente = 0;
    foreach ($opsAdmin as $op) { $gente += count($op['participantes']); }

    $c('hay una ficha por operación', count($opsAdmin), substr_count($caja, 'class="operacion"'));
    $c('y una línea por participante dentro de ellas', $gente, substr_count($caja, 'operacion__item'));
    $c('cada participante sale con su código', true,
        str_contains($caja, 'operacion__codigo'));
    $c('y con su grado en formato legible', true, str_contains($caja, '°'));

    $vistaSaldos = View::renderizar('reportes.saldos', [
        'titulo' => 'Saldos', 'columnaAncha' => true, 'concurso' => $concurso,
        'saldos' => Inscripcion::saldos($con),
        'sinReasignar' => Inscripcion::cobradoSinReasignar($con),
    ], 'principal');

    $c('el estado de la caja se dibuja', true, str_contains($vistaSaldos, 'En poder de la organización'));
    $c('y no avisa de descuadre', false, str_contains($vistaSaldos, 'El cuadre no cierra'));
    $c('dice que las devoluciones efectuadas no se registran', true,
        str_contains($vistaSaldos, 'no las registra todavía'));

    $vistaDev = View::renderizar('reportes.devoluciones', [
        'titulo' => 'Devoluciones', 'columnaAncha' => true, 'concurso' => $concurso,
        'filas' => Inscripcion::fondoDevoluciones($con),
    ], 'principal');

    $c('el fondo de devoluciones se dibuja', true, str_contains($vistaDev, 'Fondo de devoluciones'));
    $c('e incluye la anulada definitiva de la prueba', true, str_contains($vistaDev, 'anulación definitiva'));

    // La secretaria: su caja, y ninguna puerta que le vaya a dar 403.
    iniciarSesionComo('secretaria', $ana);

    $propia = View::renderizar('reportes.caja', [
        'titulo' => 'Arqueo', 'columnaAncha' => true, 'concurso' => $concurso, 'esPropia' => true,
        'filas' => Inscripcion::arqueoPorUsuario($con, $ana),
        'operaciones' => Inscripcion::operacionesDeCobro($con, $ana),
    ], 'principal');

    $c('a la secretaria se le rotula como cierre propio', true, str_contains($propia, 'Tu cierre de caja'));
    $c('no se le ofrece el estado de la caja', false, str_contains($propia, '/reportes/saldos'));
    $c('ni el fondo de devoluciones', false, str_contains($propia, '/reportes/devoluciones'));
    $c('y no ve la caja de Beto', false, str_contains($propia, 'Beto Contable'));
    $c('pero sí el enlace «Caja» de la barra', true, str_contains($propia, '/reportes/caja'));

    // ------------------------------------------------------------------
    echo "\n10) D-61 — la grilla de cobros: todas las filas, y en su orden\n";
    // ------------------------------------------------------------------
    $vacios = array_fill_keys(Inscripcion::FILTROS_COBROS, '');
    $todas  = Inscripcion::cobros($con, $vacios);

    $estados = array_unique(array_column($todas, 'estado'));
    sort($estados);
    $c('trae los tres estados, no solo lo cobrado', ['anulada', 'confirmada', 'pendiente'], $estados);

    /*
     * El orden es la mitad de lo que se pidió, así que se comprueba entero y no
     * mirando solo la primera fila: pagadas de más reciente a más antigua, y lo
     * no cobrado al final. Una columna que puede ser NULL ordenada sin decidir
     * dónde caen los nulos deja ese trozo del listado al criterio del motor.
     */
    $ordenBien   = true;
    $yaSinCobrar = false;
    $anterior    = null;

    foreach ($todas as $fila) {
        if ($fila['fecha_pago'] === null) {
            $yaSinCobrar = true;
            continue;
        }

        // Una pagada después de una sin cobrar rompe el «los nulos al final».
        if ($yaSinCobrar) { $ordenBien = false; break; }

        if ($anterior !== null && strtotime((string) $fila['fecha_pago']) > strtotime($anterior)) {
            $ordenBien = false;
            break;
        }

        $anterior = (string) $fila['fecha_pago'];
    }

    $c('ordena por fecha de confirmación, lo más reciente primero', true, $ordenBien);
    $c('y lo no cobrado queda al final', true, $yaSinCobrar);

    // La marca que impide leer la grilla como si fuera una suma.
    $porId = [];
    foreach ($todas as $fila) { $porId[(int) $fila['id']] = $fila; }

    $c('la fila viva de la reinscripción es la que cuenta', 1, (int) $porId[$i2]['pago_contado']);
    $c('y la anulada que dejó atrás va marcada como ya contada', 0, (int) $porId[$i1]['pago_contado']);
    $c('aunque las dos enseñen su pago', true,
        $porId[$i1]['fecha_pago'] !== null && $porId[$i2]['fecha_pago'] !== null);
    $c('la pendiente no se marca como contada', 0, (int) $porId[$sinFirma]['pago_contado']);

    // El detalle que se pidió, fila a fila.
    $c('la fila trae el código de Yape del cobro', '111', $porId[$i2]['yape_codigo_seguridad']);
    $c('y quién lo confirmó', 'Ana Contable', $porId[$i2]['cobrador']);
    $c('en efectivo no hay código que enseñar', null, $porId[$i3]['yape_codigo_seguridad']);
    $c('y su cobrador es el que corresponde', 'Beto Contable', $porId[$i3]['cobrador']);

    echo "\n11) Los filtros de la grilla\n";

    $soloYape = Inscripcion::cobros($con, array_merge($vacios, ['medio_pago' => 'yape']));
    $mediosVistos = array_unique(array_column($soloYape, 'medio_pago'));
    $c('el filtro de medio deja solo ese medio', ['yape'], array_values($mediosVistos));

    $sinCobrar = Inscripcion::cobros($con, array_merge($vacios, ['medio_pago' => 'sin_cobrar']));
    $c('«sin cobrar» trae solo lo que no tiene medio', [null], array_values(array_unique(array_column($sinCobrar, 'medio_pago'))));

    $deAnaGrilla = Inscripcion::cobros($con, array_merge($vacios, ['confirmado_por' => (string) $ana]));
    $c('el filtro por cobrador trae solo lo suyo', ['Ana Contable'],
        array_values(array_unique(array_column($deAnaGrilla, 'cobrador'))));

    $sinFirmaGrilla = Inscripcion::cobros($con, array_merge($vacios, ['confirmado_por' => 'sin_firma']));
    $todosSinFirma = true;
    foreach ($sinFirmaGrilla as $fila) {
        if ($fila['cobrador'] !== null || $fila['fecha_pago'] === null) { $todosSinFirma = false; }
    }
    $c('«(sin firma)» trae cobros sin firmar, y solo cobros', true, $todosSinFirma);

    /*
     * El rango de fechas se compara contra el final del día: `fecha_pago` es
     * DATETIME, así que un `<=` contra la fecha pelada dejaría fuera todo lo
     * cobrado ese día después de medianoche — es decir, todo.
     */
    $hoy = date('Y-m-d');
    $deHoy = Inscripcion::cobros($con, array_merge($vacios, ['desde' => $hoy, 'hasta' => $hoy]));
    $c('el rango de un solo día incluye lo cobrado ese día', true,
        in_array($i3, array_map('intval', array_column($deHoy, 'id')), true));
    $c('y deja fuera lo no cobrado', false,
        in_array($sinFirma, array_map('intval', array_column($deHoy, 'id')), true));

    $c('la grilla y el contador aplican el MISMO filtro',
        Inscripcion::contarFiltradas($con, array_merge($vacios, ['medio_pago' => 'yape'])),
        count($soloYape));

    echo "\n12) La grilla se dibuja y es solo del administrador\n";

    iniciarSesionComo('administrador', idAdministrador());

    $grilla = View::renderizar('reportes.cobros', [
        'titulo' => 'Cobros', 'columnaAncha' => true, 'concurso' => $concurso,
        'filtros' => $vacios, 'filas' => $todas,
        'total' => Inscripcion::contarFiltradas($con, $vacios), 'tope' => Inscripcion::TOPE_LISTADO,
        'instituciones' => [], 'usuarios' => Usuario::todos(),
    ], 'principal');

    $c('la grilla se dibuja', true, str_contains($grilla, 'Código Yape'));
    $c('marca la fila cuyo pago ya está contado', true, str_contains($grilla, 'ya contado'));
    $c('avisa de por qué no suma dinero', true, str_contains($grilla, 'cobraría dos veces'));
    $c('y manda los totales a Estado de la caja', true, str_contains($grilla, '/reportes/saldos'));
    $c('ofrece filtrar por quién confirmó', true, str_contains($grilla, 'name="confirmado_por"'));
    $c('y por rango de fechas', true,
        str_contains($grilla, 'name="desde"') && str_contains($grilla, 'name="hasta"'));

    $c('la grilla exige administrador', true,
        str_contains($trozo('public function cobros('), 'Auth::exigirAdministrador()'));
    $c('y lee los filtros por lista blanca', true,
        str_contains($trozo('public function cobros('), 'FILTROS_COBROS'));
    $c('la ruta /reportes/cobros existe', true, str_contains($rutas, "/reportes/cobros'"));

    // La secretaria no llega: ni ruta ofrecida, ni pantalla.
    iniciarSesionComo('secretaria', $ana);
    $navPropia = View::renderizar('reportes.caja', [
        'titulo' => 'Arqueo', 'columnaAncha' => true, 'concurso' => $concurso, 'esPropia' => true,
        'filas' => Inscripcion::arqueoPorUsuario($con, $ana),
        'operaciones' => Inscripcion::operacionesDeCobro($con, $ana),
    ], 'principal');
    $c('a la secretaria no se le ofrece la grilla', false, str_contains($navPropia, '/reportes/cobros'));

    // ------------------------------------------------------------------
    echo "\n13) D-62 — la hora guardada no es la hora del concurso\n";
    // ------------------------------------------------------------------
    /*
     * El desfase se comprueba por RELACIÓN y no contra «5 horas»: el número sale
     * de la configuración, y una prueba que lo escriba a mano deja de comprobar
     * la conversión para comprobar el config.
     */
    $horas = Fecha::desplazamientoHoras();
    $c('sin fecha no se inventa una', '—', Fecha::mostrar(null));
    $c('la conversión aplica el desplazamiento configurado',
        (new DateTimeImmutable('2026-08-22 01:30:00'))->modify($horas . ' hours')->format('d/m/Y H:i'),
        Fecha::mostrar('2026-08-22 01:30:00'));
    $c('con las dos zonas iguales, el SQL no se toca',
        $horas === 0, Fecha::sqlLocal('i.fecha_pago') === 'i.fecha_pago');

    /*
     * **La comprobación que de verdad importa:** que la conversión de PHP y la
     * de SQL digan lo mismo. Si divergieran, el día por el que se agrupa la
     * recaudación no sería el día que se imprime al lado, y el documento se
     * contradiría a sí mismo sin que nada fallara.
     */
    $extremos = Database::todos(
        'SELECT i.fecha_pago, DATE(' . Fecha::sqlLocal('i.fecha_pago') . ') AS dia_sql
           FROM inscripciones i JOIN participantes p ON p.id = i.participante_id
          WHERE p.concurso_id = :c AND i.fecha_pago IS NOT NULL
       ORDER BY i.fecha_pago ASC LIMIT 1',
        ['c' => $con]
    );
    $extremos = array_merge($extremos, Database::todos(
        'SELECT i.fecha_pago, DATE(' . Fecha::sqlLocal('i.fecha_pago') . ') AS dia_sql
           FROM inscripciones i JOIN participantes p ON p.id = i.participante_id
          WHERE p.concurso_id = :c AND i.fecha_pago IS NOT NULL
       ORDER BY i.fecha_pago DESC LIMIT 1',
        ['c' => $con]
    ));

    $coinciden = true;
    foreach ($extremos as $e) {
        if (Fecha::mostrar($e['fecha_pago'], 'Y-m-d') !== (string) $e['dia_sql']) { $coinciden = false; }
    }
    $c('PHP y SQL convierten la hora igual, también en los extremos', true, $coinciden);

    /*
     * D-63 — la sesión habla UTC, en cualquier máquina.
     *
     * Es lo que hace que `DATETIME` y `TIMESTAMP` —que MySQL entrega de forma
     * distinta— salgan por fin en la misma zona, y que la hora que se ve en
     * desarrollo sea la que se verá en el servidor. Sin esto, `created_at` salía
     * bien en local y cinco horas adelantado en producción, que es donde no se
     * ven los errores.
     */
    $c('la sesión de base de datos habla UTC', '+00:00',
        (string) Database::uno('SELECT @@session.time_zone AS tz')['tz']);

    $mismaFila = Database::uno(
        'SELECT fecha_pago, created_at FROM inscripciones WHERE id = :id',
        ['id' => $i3]
    );
    $c('los dos tipos de columna se leen en la misma zona', true,
        abs(strtotime((string) $mismaFila['fecha_pago']) - strtotime((string) $mismaFila['created_at'])) < 120);

    /*
     * Un día de calendario NO se convierte: el concurso es el sábado 22 en
     * Ancash y en Tokio. Desplazarlo por zona horaria lo movería al 21, que es
     * justo el fallo que `Fecha::dia()` existe para impedir.
     */
    $c('un día de calendario no se mueve', '22/08/2026', Fecha::dia('2026-08-22'));
    $c('y sigue sin moverse aunque el desplazamiento no sea cero',
        $horas !== 0, Fecha::dia('2026-08-22') !== Fecha::mostrar('2026-08-22 00:00:00', 'd/m/Y'));

    /*
     * Ninguna fecha se pinta ya fuera de `Core\Fecha`. Se comprueba sobre el
     * código porque es la única forma de que la próxima pantalla que nazca con
     * un `date()` suelto no pase inadvertida: el fallo que deja no revienta, solo
     * enseña una hora equivocada.
     */
    $sueltas = [];
    foreach (['app/Views', 'app/Servicios', 'app/Controllers', 'core'] as $carpeta) {
        $dir = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/' . $carpeta)
        );
        foreach ($dir as $archivo) {
            if ($archivo->getExtension() !== 'php' || $archivo->getFilename() === 'Fecha.php') { continue; }
            $cuerpo = (string) file_get_contents($archivo->getPathname());
            // Se quitan los comentarios antes de mirar: varios explican el
            // problema citando `date()` y `strtotime()`, y no son código.
            $cuerpo = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $cuerpo);
            if (preg_match('/\bstrtotime\s*\(|\bdate\s*\(\s*[\'"]d\/m/', $cuerpo) === 1) {
                $sueltas[] = $archivo->getFilename();
            }
        }
    }
    $c('ninguna fecha se pinta fuera de Core\\Fecha', [], $sueltas);

    /*
     * El caso real que motivó todo esto: un cobro de las 21:00 del viernes queda
     * guardado con fecha del sábado. Se reproduce con una fecha fijada a mano,
     * no con el reloj, para que la prueba diga lo mismo un martes a las tres.
     */
    $tarde = inscripcionPendienteDePrueba($ana);
    Inscripcion::confirmarPago($tarde, 'efectivo', null, $ana);
    $guardada = (new DateTimeImmutable('2026-08-22 02:00:00'))->format('Y-m-d H:i:s');
    Database::ejecutar('UPDATE inscripciones SET fecha_pago = :f WHERE id = :id',
        ['f' => $guardada, 'id' => $tarde]);

    $diaReal = Fecha::mostrar($guardada, 'Y-m-d');
    $enDiaReal = array_map('intval', array_column(
        Inscripcion::cobros($con, array_merge($vacios, ['desde' => $diaReal, 'hasta' => $diaReal])), 'id'));
    $enDiaGuardado = array_map('intval', array_column(
        Inscripcion::cobros($con, array_merge($vacios, ['desde' => '2026-08-22', 'hasta' => '2026-08-22'])), 'id'));

    $c('el cobro se filtra por su día REAL', true, in_array($tarde, $enDiaReal, true));
    $c('y no por el día con el que quedó guardado', $horas === 0,
        in_array($tarde, $enDiaGuardado, true));

    // ------------------------------------------------------------------
    echo "\n14) D-62 — la rendición cuadra y declara los sobre registros\n";
    // ------------------------------------------------------------------
    $antes = Rendicion::armar($concurso);

    $ejes = ['por_dia', 'por_medio', 'por_modalidad', 'por_cobrador'];
    foreach ($ejes as $eje) {
        $suma = 0.0;
        foreach ($antes[$eje] as $f) { $suma += (float) $f['soles']; }
        $c("el desglose {$eje} cuadra con el bruto", $s($antes['bruto']), $s($suma));
    }

    $c('el neto es el bruto menos lo indebido',
        $s($antes['bruto'] - $antes['indebido']), $s($antes['neto']));
    $c('el padrón nominal trae todas las inscripciones',
        $antes['recuento']['inscripciones'], count($antes['nominal']));

    // Ningún nombre puede estar a la vez descontado y declarado como homónimo:
    // se leería dos veces con dos criterios distintos.
    $nombresDuplicados = array_column($antes['duplicados'], 'nombre');
    $solapan = array_intersect($nombresDuplicados, array_column($antes['homonimos'], 'nombre'));
    $c('un mismo nombre no sale como duplicado y como homónimo', [], array_values($solapan));

    $c('el pago escrito en dos filas está declarado', true,
        in_array((int) $anulada['participante_id'], array_map(
            static fn (array $f): int => (int) (Database::uno(
                'SELECT participante_id FROM inscripciones WHERE id = :id',
                ['id' => (int) explode(' ', (string) $f['inscripciones'])[0]]
            )['participante_id'] ?? 0), $antes['pagos_dobles']), true));

    /*
     * Añadir una persona duplicada sube el bruto y sube lo indebido en el mismo
     * importe, **así que el ingreso legítimo no se mueve**. Es la propiedad que
     * hace defendible el documento: cobrar dos veces a la misma persona no le
     * añade un sol al concurso.
     */
    $extra = inscripcionPendienteDePrueba($ana);
    $mExtra = (float) Inscripcion::porId($extra)['monto'];
    Inscripcion::confirmarPago($extra, 'efectivo', null, $ana);

    $despues = Rendicion::armar($concurso);

    $c('un cobro duplicado sube el bruto', $s($antes['bruto'] + $mExtra), $s($despues['bruto']));
    $c('y sube lo indebido en lo mismo', $s($antes['indebido'] + $mExtra), $s($despues['indebido']));
    $c('EL INGRESO LEGÍTIMO NO SE MUEVE', $s($antes['neto']), $s($despues['neto']));

    foreach ($ejes as $eje) {
        $suma = 0.0;
        foreach ($despues[$eje] as $f) { $suma += (float) $f['soles']; }
        $c("y {$eje} sigue cuadrando", $s($despues['bruto']), $s($suma));
    }

    echo "\n15) D-62 — el documento se dibuja y es solo del administrador\n";

    iniciarSesionComo('administrador', idAdministrador());

    $doc = View::renderizar('reportes.rendicion',
        ['titulo' => 'Rendición', 'columnaAncha' => true, 'r' => $despues], 'principal');

    $c('la rendición se dibuja', true, str_contains($doc, 'Rendición de cuentas'));
    $c('con su cadena de conciliación', true, str_contains($doc, 'Cadena de conciliación'));
    $c('y sus tres anexos', true,
        str_contains($doc, 'Anexo I') && str_contains($doc, 'Anexo II') && str_contains($doc, 'Anexo III'));
    $c('declara que no se corrigió nada en la base', true,
        str_contains($doc, 'se corrigió en la base'));
    $c('NO avisa de descuadre', false, str_contains($doc, 'El documento no cuadra'));
    $c('lleva pie de firmas', true, str_contains($doc, 'Recibido por dirección'));
    $c('la rendición exige administrador', true,
        str_contains($trozo('public function rendicion('), 'Auth::exigirAdministrador()'));
    $c('la ruta existe', true, str_contains($rutas, "/reportes/rendicion'"));

    iniciarSesionComo('secretaria', $ana);
    $navSec = View::renderizar('reportes.caja', [
        'titulo' => 'Arqueo', 'columnaAncha' => true, 'concurso' => $concurso, 'esPropia' => true,
        'filas' => Inscripcion::arqueoPorUsuario($con, $ana),
        'operaciones' => Inscripcion::operacionesDeCobro($con, $ana),
    ], 'principal');
    $c('a la secretaria no se le ofrece la rendición', false, str_contains($navSec, '/reportes/rendicion'));
} finally { $pdo->rollBack(); }

echo "\n{$ok} correctas, {$mal} fallidas\n";
exit($mal === 0 ? 0 : 1);
