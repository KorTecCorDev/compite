<?php

declare(strict_types=1);

use Core\View;

/** @var array<string, mixed>|null $concurso */
/** @var array<string, mixed> $resumen */

$hoy = new DateTimeImmutable('today');

/**
 * La cuenta atrás, concordada.
 *
 * Antes se armaba a mano en cada `<dd>` con `'faltan ' . $n . ' día' . ($n === 1
 * ? '' : 's')`, que singulariza el sustantivo y se olvida del verbo: la víspera
 * del concurso la pantalla decía **«faltan 1 día»**. Vive aquí para que las dos
 * fechas digan lo mismo y para que ese acuerdo no dependa de acordarse.
 */
$cuentaAtras = static function (int $dias, string $vencido): string {
    if ($dias === 0) {
        return 'es hoy';
    }

    if ($dias < 0) {
        return $vencido;
    }

    return $dias === 1 ? 'falta 1 día' : "faltan {$dias} días";
};
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
                        <?= View::e($cuentaAtras($diasEvento, 'ya pasó')) ?>
                    </span>
                </dd>
            </div>
            <div class="datos__par">
                <dt>Cierre de inscripción</dt>
                <dd>
                    <?= View::e($finInsc->format('d/m/Y')) ?>
                    <span class="etiqueta<?= $diasInsc <= 3 ? ' etiqueta--alerta' : '' ?>">
                        <?= View::e($cuentaAtras($diasInsc, 'vencido')) ?>
                    </span>
                </dd>
            </div>
        </dl>
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
