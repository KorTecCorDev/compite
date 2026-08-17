<?php

declare(strict_types=1);

use Core\Sesion;
use Core\View;

/** @var array<string, mixed> $inscripcion */
/** @var array<int, array<string, mixed>> $categorias */
/** @var array<string, string> $errores */

$actual = ucfirst((string) $inscripcion['nivel']) . ' ' . (int) $inscripcion['grado'] . '°';
?>
<div class="encabezado">
    <div>
        <h1 class="titulo">Corregir categoría</h1>
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
    <strong>Qué va a pasar exactamente:</strong>
    <ul class="lista-errores">
        <li>La inscripción actual (<?= View::e($actual) ?>) queda <strong>anulada</strong>,
            conservando el registro de la categoría equivocada.</li>
        <li>Se crea una <strong>inscripción nueva</strong> con la categoría corregida.</li>
        <li>El participante <strong>conserva su código</strong>
            <code><?= View::e($inscripcion['codigo_correlativo']) ?></code>.</li>
        <?php if ($inscripcion['estado'] === 'confirmada'): ?>
            <li>Como el pago ya estaba confirmado, la nueva inscripción nace
                <strong>pagada</strong>: nadie paga dos veces por un error de categoría.
                <strong>No</strong> genera devolución.</li>
        <?php else: ?>
            <li>La inscripción sigue pendiente de pago, igual que ahora.</li>
        <?php endif; ?>
    </ul>
</div>

<form method="post" action="<?= View::e(View::url('/inscripciones/' . $inscripcion['id'] . '/corregir')) ?>"
      class="formulario-largo">
    <input type="hidden" name="_csrf" value="<?= View::e(Sesion::tokenCsrf()) ?>">

    <fieldset class="grupo">
        <legend class="grupo__titulo">Nueva categoría</legend>

        <div class="rejilla">
            <label class="campo<?= isset($errores['categoria_id']) ? ' campo--error' : '' ?>">
                <span class="campo__etiqueta">Categoría correcta *</span>
                <select name="categoria_id" required>
                    <option value="">Seleccionar…</option>
                    <?php foreach ($categorias as $cat): ?>
                        <option value="<?= (int) $cat['id'] ?>"
                            <?= (int) $cat['id'] === (int) $inscripcion['categoria_id'] ? 'disabled' : '' ?>>
                            <?= View::e($cat['etiqueta']) ?>
                            <?= (int) $cat['id'] === (int) $inscripcion['categoria_id'] ? ' (actual)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="campo campo--ancho">
                <span class="campo__etiqueta">Motivo <span class="tenue">(opcional)</span></span>
                <input type="text" name="motivo" maxlength="250"
                       placeholder="Ej.: el colegio informó el grado equivocado">
            </label>
        </div>
    </fieldset>

    <div class="acciones">
        <button type="submit" class="boton boton--principal">Corregir y reinscribir</button>
        <a class="boton boton--tenue" href="<?= View::e(View::url('/inscripciones')) ?>">Cancelar</a>
    </div>
</form>
