<?php

declare(strict_types=1);

use Core\Auth;
use Core\View;

/** @var array<string, mixed>|null $concurso */
/** @var array<string, mixed> $resumen */

$hoy = new DateTimeImmutable('today');
?>
<h1 class="titulo">Panel</h1>

<?php if ($concurso === null): ?>

    <div class="aviso aviso--error">
        No hay ningún concurso registrado. Ejecuta el seed inicial
        (<code>database/seed.sql</code>) antes de continuar.
    </div>

<?php else: ?>

    <?php
    $evento     = new DateTimeImmutable((string) $concurso['fecha_evento']);
    $diasEvento = (int) $hoy->diff($evento)->format('%r%a');
    $finInsc    = new DateTimeImmutable((string) $concurso['fecha_fin_inscripcion']);
    $diasInsc   = (int) $hoy->diff($finInsc)->format('%r%a');
    ?>

    <section class="tarjeta tarjeta--ancha">
        <h2 class="tarjeta__titulo"><?= View::e($concurso['nombre']) ?></h2>
        <p class="tarjeta__meta">
            <?= View::e($concurso['organizacion']) ?>
            <?php if (!empty($concurso['sede'])): ?>
                · <?= View::e($concurso['sede']) ?>
            <?php endif; ?>
        </p>

        <dl class="datos">
            <div class="datos__par">
                <dt>Fecha del evento</dt>
                <dd>
                    <?= View::e($evento->format('d/m/Y')) ?>
                    <span class="etiqueta<?= $diasEvento <= 3 ? ' etiqueta--alerta' : '' ?>">
                        <?= $diasEvento > 0
                            ? 'faltan ' . $diasEvento . ' día' . ($diasEvento === 1 ? '' : 's')
                            : ($diasEvento === 0 ? 'es hoy' : 'ya pasó') ?>
                    </span>
                </dd>
            </div>
            <div class="datos__par">
                <dt>Cierre de inscripción</dt>
                <dd>
                    <?= View::e($finInsc->format('d/m/Y')) ?>
                    <span class="etiqueta<?= $diasInsc <= 3 ? ' etiqueta--alerta' : '' ?>">
                        <?= $diasInsc > 0
                            ? 'faltan ' . $diasInsc . ' día' . ($diasInsc === 1 ? '' : 's')
                            : ($diasInsc === 0 ? 'es hoy' : 'vencido') ?>
                    </span>
                </dd>
            </div>
        </dl>

        <p class="nota">
            El sistema no bloquea el registro por fecha: se puede inscribir
            incluso el día del evento. El cierre lo decide la secretaría.
        </p>
    </section>

    <section class="metricas">
        <div class="metrica">
            <span class="metrica__valor"><?= (int) $resumen['pendientes'] ?></span>
            <span class="metrica__nombre">Pendientes de pago</span>
        </div>
        <div class="metrica">
            <span class="metrica__valor"><?= (int) $resumen['confirmadas'] ?></span>
            <span class="metrica__nombre">Confirmadas</span>
        </div>
        <div class="metrica">
            <span class="metrica__valor"><?= (int) $resumen['anuladas'] ?></span>
            <span class="metrica__nombre">Anuladas</span>
        </div>
        <div class="metrica metrica--destacada">
            <span class="metrica__valor">S/ <?= number_format((float) $resumen['recaudado'], 2) ?></span>
            <span class="metrica__nombre">Recaudado</span>
        </div>
    </section>

<?php endif; ?>

<section class="tarjeta tarjeta--ancha">
    <h2 class="tarjeta__titulo">Módulos</h2>
    <ul class="lista-modulos">
        <li class="lista-modulos__item lista-modulos__item--listo">
            Acceso y sesiones <span class="etiqueta">Fase 1 · listo</span>
        </li>
        <li class="lista-modulos__item lista-modulos__item--listo">
            <?php /* El catálogo de colegios es administrativo (D-40); Apoderados no. */ ?>
            <?php if (Auth::esAdministrador()): ?>
                <a href="<?= View::e(View::url('/instituciones')) ?>">Instituciones Educativas</a> y
            <?php endif; ?>
            <a href="<?= View::e(View::url('/apoderados')) ?>">Apoderados</a>
            <span class="etiqueta">Fase 2 · listo</span>
        </li>
        <li class="lista-modulos__item lista-modulos__item--listo">
            <a href="<?= View::e(View::url('/inscripciones')) ?>">Inscripciones</a>
            <span class="etiqueta">Fase 3 · listo</span>
        </li>
        <li class="lista-modulos__item">
            Pagos, anulación y carné <span class="etiqueta">Fase 4</span>
        </li>
        <li class="lista-modulos__item">
            Reportes <span class="etiqueta">Fase 5</span>
        </li>
        <?php if (Auth::esAdministrador()): ?>
            <li class="lista-modulos__item">
                Administración: concurso, categorías, tarifas, usuarios
                <span class="etiqueta">solo administrador</span>
            </li>
        <?php endif; ?>
    </ul>
</section>
