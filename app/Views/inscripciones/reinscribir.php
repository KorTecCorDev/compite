<?php

declare(strict_types=1);

use Core\Sesion;
use Core\View;

/** @var array<string, mixed> $inscripcion */
/** @var array<int, array<string, mixed>> $categorias */
/** @var array<string, string> $errores */

$anterior    = ucfirst((string) $inscripcion['nivel']) . ' ' . (int) $inscripcion['grado'] . '°';
$habiaPagado = $inscripcion['fecha_pago'] !== null;
?>
<div class="encabezado">
    <div>
        <h1 class="titulo">Reinscribir participante</h1>
        <p class="subtitulo">
            <strong><?= View::e($inscripcion['ap_paterno'] . ' ' . $inscripcion['ap_materno']) ?>,
            <?= View::e($inscripcion['nombres']) ?></strong>
            · <code><?= View::e($inscripcion['codigo_correlativo']) ?></code>
        </p>
    </div>
    <a class="boton boton--tenue" href="<?= View::e(View::url('/inscripciones')) ?>">Cancelar</a>
</div>

<?php if ($errores !== []): ?>
    <div class="aviso aviso--error">
        <ul class="lista-errores">
            <?php foreach ($errores as $mensaje): ?>
                <li><?= View::e($mensaje) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="aviso aviso--aviso">
    <strong>Este participante está hoy fuera del concurso.</strong>
    Su inscripción de <?= View::e($anterior) ?> fue anulada
    <?php if (!empty($inscripcion['motivo_anulacion'])): ?>
        —«<?= View::e((string) $inscripcion['motivo_anulacion']) ?>»—
    <?php endif; ?>
    y no le queda ninguna otra vigente.
</div>

<div class="aviso aviso--aviso">
    <strong>Qué va a pasar exactamente:</strong>
    <ul class="lista-errores">
        <li>Se crea una <strong>inscripción nueva</strong>. La anulada se queda como está,
            con su motivo: es el rastro de lo que pasó.</li>
        <li>El participante <strong>conserva su código</strong>
            <code><?= View::e($inscripcion['codigo_correlativo']) ?></code>,
            así que cualquier carné ya impreso con ese código sigue sirviendo.</li>
        <?php if ($habiaPagado): ?>
            <li>Como <strong>ya había pagado</strong>
                (S/ <?= number_format((float) $inscripcion['monto'], 2) ?>,
                <?= View::e((string) $inscripcion['medio_pago']) ?>),
                la nueva nace <strong>confirmada</strong> y con su carné emitido.
                <strong>No se cobra otra vez.</strong></li>
            <?php if (!empty($inscripcion['requiere_devolucion'])): ?>
                <li>Sus S/ <?= number_format((float) $inscripcion['monto'], 2) ?>
                    <strong>salen del fondo de devoluciones</strong>: ese dinero no se
                    devolvió, se vuelve a aplicar aquí.</li>
            <?php endif; ?>
        <?php else: ?>
            <li>No había pago confirmado, así que la nueva queda
                <strong>pendiente de cobro</strong>.</li>
        <?php endif; ?>
    </ul>
</div>

<form method="post" action="<?= View::e(View::url('/inscripciones/' . $inscripcion['id'] . '/reinscribir')) ?>"
      class="formulario-largo">
    <input type="hidden" name="_csrf" value="<?= View::e(Sesion::tokenCsrf()) ?>">

    <fieldset class="grupo">
        <legend class="grupo__titulo">Categoría</legend>

        <div class="rejilla">
            <label class="campo<?= isset($errores['categoria_id']) ? ' campo--error' : '' ?>">
                <span class="campo__etiqueta">Categoría *</span>
                <select name="categoria_id" required>
                    <?php foreach ($categorias as $cat): ?>
                        <option value="<?= (int) $cat['id'] ?>"
                            <?= (int) $cat['id'] === (int) $inscripcion['categoria_id'] ? 'selected' : '' ?>>
                            <?= View::e($cat['etiqueta']) ?>
                            <?= (int) $cat['id'] === (int) $inscripcion['categoria_id'] ? ' (la que tenía)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="campo__ayuda">Viene marcada la que tenía. Cámbiala si además estaba mal.</span>
            </label>

            <label class="campo campo--ancho">
                <span class="campo__etiqueta">Motivo <span class="tenue">(opcional)</span></span>
                <input type="text" name="motivo" maxlength="200"
                       placeholder="Ej.: se anuló por error, el colegio confirmó su participación">
                <span class="campo__ayuda">Se añade al historial de la inscripción anulada, sin borrar el motivo original.</span>
            </label>
        </div>
    </fieldset>

    <div class="acciones">
        <button type="submit" class="boton boton--principal">Reinscribir</button>
        <a class="boton boton--tenue" href="<?= View::e(View::url('/inscripciones')) ?>">Cancelar</a>
    </div>
</form>
