<?php

declare(strict_types=1);

use Core\View;

/** @var array<string, mixed> $ficha */
/** @var string $estado */

$nombre = trim(
    ($ficha['ap_paterno'] ?? '') . ' ' . ($ficha['ap_materno'] ?? '')
    . ', ' . ($ficha['nombres'] ?? '')
);

$categoria = ucfirst((string) ($ficha['nivel'] ?? '')) . ' ' . (int) ($ficha['grado'] ?? 0) . '°';

$origen = ($ficha['tipo_participante'] ?? '') === 'libre'
    ? 'Estudiante libre'
    : (string) ($ficha['institucion'] ?? '—');

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
        <h1 class="carne-evento"><?= View::e($ficha['concurso']) ?></h1>
        <p class="carne-sede">
            <?= View::e($ficha['sede'] ?? '') ?>
            <?php if ($fecha !== ''): ?> · <?= View::e($fecha) ?><?php endif; ?>
        </p>
    </header>

    <dl class="carne-datos">
        <div>
            <dt>Participante</dt>
            <dd class="carne-nombre"><?= View::e($nombre) ?></dd>
        </div>
        <div>
            <dt>Documento</dt>
            <dd><?= View::e($ficha['dni']) ?></dd>
        </div>
        <?php if (!empty($ficha['nivel'])): ?>
        <div>
            <dt>Categoría</dt>
            <dd><?= View::e($categoria) ?></dd>
        </div>
        <?php endif; ?>
        <div>
            <dt>Procedencia</dt>
            <dd><?= View::e($origen) ?></dd>
        </div>
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
