<?php

declare(strict_types=1);

use App\Models\Concurso;
use Core\View;

/** @var array<string, mixed> $ficha */
/** @var string $estado */

/*
 * Los campos son los mismos del carné impreso y en el mismo orden, a propósito:
 * esta vista es lo que abre el QR del papel, y si la mesa de la puerta ve aquí
 * una estructura distinta de la que tiene en la mano deja de poder contrastar
 * un dato con el otro. Cualquier cambio aquí va también en GeneradorCarne.
 */
$apellidos = trim(($ficha['ap_paterno'] ?? '') . ' ' . ($ficha['ap_materno'] ?? ''));
$nombres   = trim((string) ($ficha['nombres'] ?? ''));

$grado = (int) ($ficha['grado'] ?? 0) . '° ' . ucfirst((string) ($ficha['nivel'] ?? ''));

$esLibre = ($ficha['tipo_participante'] ?? '') === 'libre';

/*
 * La modalidad se lee de la inscripción, que es donde quedó congelada junto al
 * monto (D-37). Antes se rederivaba aquí del tipo del colegio, y por eso podía
 * acabar diciendo algo distinto de lo que se cobró.
 */
$modalidad = Concurso::etiquetaModalidad(
    isset($ficha['tipo_origen']) ? (string) $ficha['tipo_origen'] : null
);

$fecha = !empty($ficha['fecha_evento'])
    ? date('d/m/Y', strtotime((string) $ficha['fecha_evento']))
    : '';
?>
<div class="carne-tarjeta carne-tarjeta--<?= View::e($estado ?: 'sin-inscripcion') ?>">

    <?php if ($estado === 'anulada'): ?>
        <div class="carne-sello carne-sello--anulado">Anulado</div>
    <?php elseif ($estado === 'pendiente'): ?>
        <div class="carne-sello carne-sello--pendiente">Pago pendiente</div>
    <?php elseif ($estado === 'confirmada'): ?>
        <div class="carne-sello carne-sello--valido">Inscripción válida</div>
    <?php endif; ?>

    <header class="carne-cabecera">
        <!--
            El escudo acompaña al nombre del concurso igual que en el carné de
            papel (D-33). Aquí no compite por milímetros —esto es una página, no
            una tarjeta de 54 mm—, así que va más grande y sí se le distingue el
            texto del borde. alt vacío a propósito: es decorativo, y el nombre
            de la institución ya viaja como texto en el encabezado.
        -->
        <img class="carne-escudo" src="<?= View::e(View::url('img/logo-cociap.png')) ?>" alt="">

        <div class="carne-identidad">
            <h1 class="carne-evento"><?= View::e($ficha['concurso']) ?></h1>
            <p class="carne-sede">
                <?= View::e($ficha['sede'] ?? '') ?>
                <?php if ($fecha !== ''): ?> · <?= View::e($fecha) ?><?php endif; ?>
            </p>
        </div>
    </header>

    <dl class="carne-datos">
        <div>
            <dt>Apellidos</dt>
            <dd class="carne-nombre"><?= View::e($apellidos) ?></dd>
        </div>
        <div>
            <dt>Nombres</dt>
            <dd class="carne-nombre"><?= View::e($nombres) ?></dd>
        </div>
        <div>
            <dt>DNI</dt>
            <dd><?= View::e($ficha['dni']) ?></dd>
        </div>
        <?php if (!empty($ficha['nivel'])): ?>
        <div>
            <dt>Grado</dt>
            <dd><?= View::e($grado) ?></dd>
        </div>
        <?php endif; ?>
        <div>
            <dt>Modalidad</dt>
            <dd><?= View::e($modalidad) ?></dd>
        </div>
        <?php if (!$esLibre): ?>
        <div>
            <dt>Procedencia</dt>
            <dd><?= View::e($ficha['institucion'] ?? '—') ?></dd>
        </div>
        <?php endif; ?>
    </dl>

    <p class="carne-codigo"><?= View::e($ficha['codigo_correlativo']) ?></p>

    <?php if ($estado === 'anulada'): ?>
        <p class="carne-nota carne-nota--alerta">
            Esta inscripción fue anulada y no es válida para el ingreso al concurso.
            <?php if (!empty($ficha['motivo_anulacion'])): ?>
                <br><span class="tenue">Motivo: <?= View::e($ficha['motivo_anulacion']) ?></span>
            <?php endif; ?>
        </p>
    <?php elseif ($estado === 'pendiente'): ?>
        <p class="carne-nota">
            El pago de esta inscripción aún no ha sido confirmado por la secretaría.
        </p>
    <?php elseif ($estado === 'confirmada'): ?>
        <p class="carne-nota">
            Presenta este carné el día del concurso.
        </p>
    <?php else: ?>
        <p class="carne-nota">
            Este participante todavía no tiene una inscripción registrada.
        </p>
    <?php endif; ?>

</div>
