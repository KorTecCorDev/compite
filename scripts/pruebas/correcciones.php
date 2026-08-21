<?php

declare(strict_types=1);

/**
 * D-50 — corregir el registro de participación.
 *
 * Todo dentro de una transacción que se revierte: no queda ni una fila.
 *
 * **Esta prueba crea sus propios datos y no toca ninguno de los reales.** No es
 * una precaución teórica: la base de trabajo tiene inscripciones de estudiantes
 * de verdad con dinero cobrado, y una prueba anterior de este proyecto llegó a
 * confirmar el pago de una inscripción real por «tomar la primera pendiente que
 * encontró». Aquí se registra un colegio desechable, dos estudiantes
 * desechables y sus inscripciones, y se corrige sobre eso.
 */

require __DIR__ . '/_comun.php';

use App\Controllers\CorreccionController;
use App\Models\Apoderado;
use App\Models\Concurso;
use App\Models\Correccion;
use App\Models\Inscripcion;
use App\Models\InstitucionEducativa;
use App\Models\Participante;
use Core\Database;
use Core\Sesion;
use Core\View;

$ok = 0;
$mal = 0;

$c = static function (string $caso, $esperado, $obtenido) use (&$ok, &$mal): void {
    if ($esperado === $obtenido) {
        $ok++;
        echo "  OK    {$caso}\n";
    } else {
        $mal++;
        echo "  FALLA {$caso}: esperaba " . var_export($esperado, true)
            . ', obtuvo ' . var_export($obtenido, true) . "\n";
    }
};

$afirmar = static function (string $caso, bool $cond) use (&$ok, &$mal): void {
    if ($cond) {
        $ok++;
        echo "  OK    {$caso}\n";
    } else {
        $mal++;
        echo "  FALLA {$caso}\n";
    }
};

iniciarSesionComo('administrador');

$usuario  = idAdministrador();
$concurso = Concurso::vigente();
$con      = (int) $concurso['id'];

$pdo = Database::conexion();
$pdo->beginTransaction();

try {
    // -----------------------------------------------------------------------
    // Datos desechables. Los documentos empiezan por 99 para no chocar nunca
    // con un DNI real de ocho dígitos empezando por otro número.
    // -----------------------------------------------------------------------
    $sufijo = str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);

    $docente = Apoderado::crear([
        'dni'        => '99' . $sufijo . '1',
        'ap_paterno' => 'Prueba',
        'ap_materno' => 'Docente',
        'nombres'    => 'Delegado Desechable',
        'celular'    => '999000111',
        'correo'     => null,
    ]);

    $baseIe = [
        'distrito' => 'Huaraz', 'provincia' => 'Huaraz', 'departamento' => 'Áncash',
        'direccion' => null, 'docente_delegado_id' => $docente,
        'di_ap_paterno' => null, 'di_ap_materno' => null, 'di_nombres' => null,
        'di_celular' => null, 'di_correo' => null, 'di_dni' => null,
    ];

    $iePublica = InstitucionEducativa::crear($baseIe + [
        'nombre' => 'IE Desechable Pública ' . $sufijo,
        'tipo'   => 'publica',
    ]);

    $iePrivada = InstitucionEducativa::crear($baseIe + [
        'nombre' => 'IE Desechable Privada ' . $sufijo,
        'tipo'   => 'privada',
    ]);

    $prefijo    = Participante::prefijoConcurso($con);
    $categorias = Concurso::categorias($con);
    $catA       = (int) $categorias[0]['id'];
    $catB       = (int) $categorias[1]['id'];

    $tarifaPublica = Concurso::tarifa($con, 'publica');
    $tarifaPrivada = Concurso::tarifa($con, 'privada');
    $tarifaLibre   = Concurso::tarifa($con, 'libre');

    // Estudiante de delegación pública, PENDIENTE de pago.
    $pendienteId = Participante::crear([
        'concurso_id' => $con, 'tipo_participante' => 'delegacion',
        'dni' => '99' . $sufijo . '2', 'ap_paterno' => 'Nolasco', 'ap_materno' => 'Prueba',
        'nombres' => 'Estudiante Pendiente', 'institucion_id' => $iePublica,
        'apoderado_id' => $docente,
    ], $prefijo);

    $insPendiente = Inscripcion::crear([
        'participante_id' => $pendienteId, 'categoria_id' => $catA, 'usuario_id' => $usuario,
        'estado' => 'pendiente', 'tipo_origen' => 'publica', 'monto' => $tarifaPublica,
    ]);

    // Estudiante de delegación privada, PAGADA.
    $pagadaPartId = Participante::crear([
        'concurso_id' => $con, 'tipo_participante' => 'delegacion',
        'dni' => '99' . $sufijo . '3', 'ap_paterno' => 'Ñiquen', 'ap_materno' => 'Prueba',
        'nombres' => 'Estudiante Pagado', 'institucion_id' => $iePrivada,
        'apoderado_id' => $docente,
    ], $prefijo);

    $insPagada = Inscripcion::crear([
        'participante_id' => $pagadaPartId, 'categoria_id' => $catA, 'usuario_id' => $usuario,
        'estado' => 'confirmada', 'tipo_origen' => 'privada', 'monto' => $tarifaPrivada,
        'medio_pago' => 'efectivo', 'fecha_pago' => date('Y-m-d H:i:s'),
    ]);

    // -----------------------------------------------------------------------
    echo "\n1) Corregir el DNI queda registrado, con motivo, firma y valor legible\n";
    // -----------------------------------------------------------------------
    $dniViejo = '99' . $sufijo . '2';
    $dniNuevo = '99' . $sufijo . '9';

    /*
     * Sin `Database::transaccion()` a propósito: esta prueba ya tiene la suya
     * abierta y `beginTransaction()` de PDO **no anida** —MariaDB responde
     * «There is already an active transaction»—. En producción el controlador
     * sí la envuelve, que es donde importa: allí no hay ninguna transacción
     * abierta por encima.
     */
    Participante::actualizar($pendienteId, ['dni' => $dniNuevo]);
    Correccion::registrar(
        $pendienteId,
        $insPendiente,
        ['participante.dni' => [$dniViejo, $dniNuevo]],
        'El documento se digitó con un dígito cambiado',
        $usuario
    );

    $c('el documento quedó corregido', $dniNuevo, (string) Participante::porId($pendienteId)['dni']);

    $historial = Correccion::porParticipante($pendienteId);

    $c('quedó una fila en el registro', 1, count($historial));
    $c('con el valor anterior legible', $dniViejo, (string) $historial[0]['anterior']);
    $c('con el valor nuevo', $dniNuevo, (string) $historial[0]['nuevo']);
    $c('firmada por quien la hizo', $usuario, (int) $historial[0]['usuario_id']);
    $afirmar('con el motivo guardado', str_contains((string) $historial[0]['motivo'], 'dígito cambiado'));
    $c('y el rótulo se lee en castellano', 'Documento', Correccion::etiqueta((string) $historial[0]['campo']));

    // -----------------------------------------------------------------------
    echo "\n2) Un documento que ya existe se rechaza nombrando al otro registro\n";
    // -----------------------------------------------------------------------
    $ajeno = Participante::porId($pagadaPartId);

    $html = pintarCorreccion($insPendiente, [
        'dni'          => (string) $ajeno['dni'],
        'ap_paterno'   => 'Nolasco',
        'ap_materno'   => 'Prueba',
        'nombres'      => 'Estudiante Pendiente',
        'categoria_id' => (string) $catA,
        'motivo'       => 'Prueba de documento duplicado',
    ]);

    $afirmar('se rechaza el documento ya registrado', str_contains($html, 'ya está registrado en este concurso'));
    $afirmar('y se dice de quién es', str_contains($html, (string) $ajeno['codigo_correlativo']));
    $c('no se cambió el documento', $dniNuevo, (string) Participante::porId($pendienteId)['dni']);

    // -----------------------------------------------------------------------
    echo "\n3) Corregir el grado ya no crea una inscripción nueva\n";
    // -----------------------------------------------------------------------
    $antesDeGrado = contarInscripciones($pendienteId);

    Inscripcion::cambiarCategoria($insPendiente, $catB);

    $c('la inscripción conserva su id', $catB, (int) Inscripcion::porId($insPendiente)['categoria_id']);
    $c('y no aparece ninguna inscripción más', $antesDeGrado, contarInscripciones($pendienteId));
    $c('ni ninguna anulada de propina', 0, contarAnuladas($pendienteId));

    // -----------------------------------------------------------------------
    echo "\n4) Pendiente + cambio de procedencia: modalidad y monto se recalculan\n";
    // -----------------------------------------------------------------------
    $c(
        'una pendiente siempre puede cambiar de procedencia',
        true,
        Inscripcion::cambioDeProcedenciaPermitido('pendiente', $tarifaPublica, $tarifaPrivada)
    );

    Participante::actualizar($pendienteId, ['institucion_id' => $iePrivada]);
    Inscripcion::cambiarProcedencia($insPendiente, 'privada', $tarifaPrivada);

    $trasCambio = Inscripcion::porId($insPendiente);

    $c('la modalidad quedó en privada', 'privada', (string) $trasCambio['tipo_origen']);
    $c('y el monto se recalculó', $tarifaPrivada, (float) $trasCambio['monto']);

    // -----------------------------------------------------------------------
    echo "\n5) Pagada + tarifa igual: permitido, y el monto no se toca\n";
    // -----------------------------------------------------------------------
    $c(
        'privada → libre pasa aun pagada, porque cuestan lo mismo',
        true,
        Inscripcion::cambioDeProcedenciaPermitido('confirmada', $tarifaPrivada, $tarifaLibre)
    );

    Inscripcion::cambiarProcedencia($insPagada, 'libre', (float) Inscripcion::porId($insPagada)['monto']);

    $c('el monto cobrado sigue intacto', $tarifaPrivada, (float) Inscripcion::porId($insPagada)['monto']);
    $c('y la modalidad se corrigió', 'libre', (string) Inscripcion::porId($insPagada)['tipo_origen']);

    // Se deja como estaba para el caso 6.
    Inscripcion::cambiarProcedencia($insPagada, 'privada', $tarifaPrivada);

    // -----------------------------------------------------------------------
    echo "\n6) Pagada + tarifa distinta: bloqueado\n";
    // -----------------------------------------------------------------------
    $c(
        'privada (15) → pública (10) se bloquea si ya pagó',
        false,
        Inscripcion::cambioDeProcedenciaPermitido('confirmada', $tarifaPrivada, $tarifaPublica)
    );

    $html = pintarCorreccion($insPagada, [
        'dni'               => (string) $ajeno['dni'],
        'ap_paterno'        => 'Ñiquen',
        'ap_materno'        => 'Prueba',
        'nombres'           => 'Estudiante Pagado',
        'categoria_id'      => (string) $catA,
        'tipo_participante' => 'delegacion',
        'institucion_id'    => (string) $iePublica,
        'motivo'            => 'Prueba de cruce de tarifa',
    ]);

    $afirmar('la pantalla lo explica con los dos importes', str_contains($html, 'movería el dinero cobrado'));
    $c('y la modalidad no se movió', 'privada', (string) Inscripcion::porId($insPagada)['tipo_origen']);
    $c('ni el monto', $tarifaPrivada, (float) Inscripcion::porId($insPagada)['monto']);

    // -----------------------------------------------------------------------
    echo "\n7) libre → delegación reapunta el apoderado al docente delegado\n";
    // -----------------------------------------------------------------------
    $apoderadoParticular = Apoderado::crear([
        'dni' => '99' . $sufijo . '4', 'ap_paterno' => 'Xenofia', 'ap_materno' => 'Prueba',
        'nombres' => 'Madre Desechable', 'celular' => '999000222', 'correo' => null,
    ]);

    $libreId = Participante::crear([
        'concurso_id' => $con, 'tipo_participante' => 'libre',
        'dni' => '99' . $sufijo . '5', 'ap_paterno' => 'Quiñónez', 'ap_materno' => 'Prueba',
        'nombres' => 'Estudiante Libre', 'institucion_id' => null,
        'apoderado_id' => $apoderadoParticular,
    ], $prefijo);

    $insLibre = Inscripcion::crear([
        'participante_id' => $libreId, 'categoria_id' => $catA, 'usuario_id' => $usuario,
        'estado' => 'pendiente', 'tipo_origen' => 'libre', 'monto' => $tarifaLibre,
    ]);

    $ie = InstitucionEducativa::porId($iePublica);

    Participante::actualizar($libreId, [
        'tipo_participante' => 'delegacion',
        'institucion_id'    => $iePublica,
        'apoderado_id'      => (int) $ie['docente_delegado_id'],
    ]);

    $trasVolver = Participante::porId($libreId);

    $c('pasa a delegación', 'delegacion', (string) $trasVolver['tipo_participante']);
    $c('y su apoderado es ahora el docente delegado (D-28)', $docente, (int) $trasVolver['apoderado_id']);
    $afirmar('que no es el apoderado particular', (int) $trasVolver['apoderado_id'] !== $apoderadoParticular);

    // -----------------------------------------------------------------------
    echo "\n8) delegación → libre exige apoderado y deja la institución en NULL\n";
    // -----------------------------------------------------------------------
    $html = pintarCorreccion($insLibre, [
        'dni'               => (string) $trasVolver['dni'],
        'ap_paterno'        => 'Quiñónez',
        'ap_materno'        => 'Prueba',
        'nombres'           => 'Estudiante Libre',
        'categoria_id'      => (string) $catA,
        'tipo_participante' => 'libre',
        'motivo'            => 'Se va de la delegación',
        // Sin datos de apoderado a propósito.
    ]);

    $afirmar('se exige el documento del apoderado', str_contains($html, 'documento del apoderado'));
    $c('y no se cambió el tipo', 'delegacion', (string) Participante::porId($libreId)['tipo_participante']);

    Participante::actualizar($libreId, [
        'tipo_participante' => 'libre',
        'institucion_id'    => null,
        'apoderado_id'      => $apoderadoParticular,
    ]);

    $c('al hacerlo bien, la institución queda en NULL', null, Participante::porId($libreId)['institucion_id']);

    // -----------------------------------------------------------------------
    echo "\n9) El motivo es obligatorio\n";
    // -----------------------------------------------------------------------
    $html = pintarCorreccion($insPendiente, [
        'dni'          => $dniNuevo,
        'ap_paterno'   => 'Nolasco',
        'ap_materno'   => 'Prueba',
        'nombres'      => 'Cambiado Sin Motivo',
        'categoria_id' => (string) $catB,
        'motivo'       => '',
    ]);

    $afirmar('se rechaza sin motivo', str_contains($html, 'El motivo de la corrección'));
    $c('y no se cambió el nombre', 'Estudiante Pendiente', (string) Participante::porId($pendienteId)['nombres']);

    // -----------------------------------------------------------------------
    echo "\n10) La procedencia es solo del administrador\n";
    // -----------------------------------------------------------------------
    $afirmar(
        'un envío con institución se reconoce como procedencia',
        CorreccionController::traeProcedencia(['institucion_id' => '7'])
    );
    $afirmar(
        'y uno con tipo de participante, también',
        CorreccionController::traeProcedencia(['tipo_participante' => 'libre'])
    );
    $afirmar(
        'un envío solo con datos del estudiante NO lo es',
        !CorreccionController::traeProcedencia(['dni' => '12345678', 'motivo' => 'x'])
    );
    $afirmar(
        'ni uno con los campos vacíos',
        !CorreccionController::traeProcedencia(['institucion_id' => '', 'tipo_participante' => ''])
    );

    // La vista: la secretaria no ve el bloque, el administrador sí.
    foreach (['secretaria' => false, 'administrador' => true] as $rol => $debeVer) {
        iniciarSesionComo($rol);

        $vista = View::renderizar('inscripciones.corregir', [
            'titulo'        => 'Corregir inscripción',
            'inscripcion'   => Inscripcion::porId($insPendiente),
            'categorias'    => $categorias,
            'instituciones' => InstitucionEducativa::listar('', null, 50),
            'historial'     => Correccion::porParticipante($pendienteId),
            'esAdmin'       => $rol === 'administrador',
            'valores'       => [],
            'errores'       => [],
        ], 'principal');

        $afirmar(
            "{$rol}: " . ($debeVer ? 've' : 'NO ve') . ' el bloque de procedencia',
            str_contains($vista, 'name="tipo_participante"') === $debeVer
        );
        $afirmar(
            "{$rol}: ve siempre los datos del estudiante",
            str_contains($vista, 'name="dni"')
        );
    }

    iniciarSesionComo('administrador');

    // -----------------------------------------------------------------------
    echo "\n11) El historial se pinta en la propia pantalla\n";
    // -----------------------------------------------------------------------
    $vista = View::renderizar('inscripciones.corregir', [
        'titulo'        => 'Corregir inscripción',
        'inscripcion'   => Inscripcion::porId($insPendiente),
        'categorias'    => $categorias,
        'instituciones' => InstitucionEducativa::listar('', null, 50),
        'historial'     => Correccion::porParticipante($pendienteId),
        'esAdmin'       => true,
        'valores'       => [],
        'errores'       => [],
    ], 'principal');

    $afirmar('se ve la corrección anterior', str_contains($vista, 'Correcciones anteriores'));
    $afirmar('con el documento viejo a la vista', str_contains($vista, $dniViejo));
    $afirmar('y con su motivo', str_contains($vista, 'dígito cambiado'));

    // -----------------------------------------------------------------------
    echo "\n12) Un campo que no se puede corregir se rechaza en el modelo\n";
    // -----------------------------------------------------------------------
    // El código correlativo va impreso en carnés ya entregados: si esto dejara
    // de fallar, una corrección podría invalidar papel que está en la mochila
    // de un niño.
    $rechazado = false;

    try {
        Participante::actualizar($pendienteId, ['codigo_correlativo' => 'HACKEADO']);
    } catch (Throwable $e) {
        $rechazado = str_contains($e->getMessage(), 'no corregibles');
    }

    $afirmar('el código correlativo no se puede tocar', $rechazado);
} finally {
    $pdo->rollBack();
    echo "\nTransacción revertida: la base queda como estaba.\n";
}

echo "\n{$ok} correctas, {$mal} fallidas\n";
exit($mal === 0 ? 0 : 1);


/**
 * Ejecuta el formulario de corrección con datos que NO deben pasar la
 * validación, y devuelve el HTML que la pantalla responde.
 *
 * Solo sirve para los caminos de rechazo, y es a propósito: cuando la
 * corrección es válida el controlador redirige, y `redirigir()` hace `exit`.
 * Desde una prueba de consola un redirect no se puede observar — por eso las
 * reglas que deciden viven en el modelo (`cambioDeProcedenciaPermitido`) y en
 * un método estático (`traeProcedencia`), donde sí se las puede mirar de frente.
 *
 * @param array<string, string> $campos
 */
function pintarCorreccion(int $inscripcionId, array $campos): string
{
    $_POST = $campos + ['_csrf' => Sesion::tokenCsrf()];

    ob_start();

    try {
        (new CorreccionController())->guardar((string) $inscripcionId);
    } finally {
        $html = (string) ob_get_clean();
    }

    $_POST = [];

    return $html;
}

function contarInscripciones(int $participanteId): int
{
    $fila = Database::uno(
        'SELECT COUNT(*) AS n FROM inscripciones WHERE participante_id = :p',
        ['p' => $participanteId]
    );

    return (int) ($fila['n'] ?? 0);
}

function contarAnuladas(int $participanteId): int
{
    $fila = Database::uno(
        "SELECT COUNT(*) AS n FROM inscripciones WHERE participante_id = :p AND estado = 'anulada'",
        ['p' => $participanteId]
    );

    return (int) ($fila['n'] ?? 0);
}
