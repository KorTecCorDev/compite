<?php

declare(strict_types=1);

use Core\View;

/** @var array<string, mixed>|null $concurso */
/** @var string $termino */
/** @var array<int, array<string, mixed>> $resultados */
/** @var int $total */
/** @var bool $recortado */

/*
 * El estado manda sobre todo lo demás en esta pantalla: quien atiende la puerta
 * necesita saber en medio segundo si el estudiante pasa o no pasa.
 */
$veredictos = [
    'confirmada' => ['Puede ingresar', 'valido'],
    'pendiente'  => ['Pago pendiente', 'pendiente'],
    'anulada'    => ['No puede ingresar', 'anulado'],
];
?>
<div class="encabezado">
    <div>
        <h1 class="titulo">Control de ingreso</h1>
        <p class="subtitulo">
            <?= View::e((string) ($concurso['nombre'] ?? '')) ?>
        </p>
    </div>
</div>

<form method="get" action="<?= View::e(View::url('/control')) ?>" class="control-busqueda">
    <input type="search"
           name="q"
           class="control-busqueda__campo"
           value="<?= View::e($termino) ?>"
           placeholder="Apellido, documento o código"
           autocomplete="off"
           autofocus>
    <button type="submit" class="boton boton--principal">Buscar</button>
</form>

<p class="control-ayuda">
    Busca por apellido, número de documento o código del carné. No hace falta
    que el estudiante traiga el carné impreso.
</p>

<?php if ($termino !== '' && mb_strlen($termino) < 2): ?>
    <div class="aviso aviso--error">Escribe al menos dos caracteres.</div>
<?php endif; ?>

<?php if ($termino !== '' && mb_strlen($termino) >= 2): ?>

    <?php if ($resultados === []): ?>
        <div class="aviso aviso--error">
            No se encontró a nadie con «<?= View::e($termino) ?>».
            Revisa cómo está escrito el apellido o prueba con el documento.
        </div>
    <?php else: ?>

        <?php if ($recortado): ?>
            <div class="aviso aviso--aviso">
                Se encontraron <?= (int) $total ?> coincidencias y se muestran las
                primeras <?= count($resultados) ?>. Escribe el apellido más completo
                para acotar la búsqueda.
            </div>
        <?php endif; ?>

        <ul class="control-lista">
            <?php foreach ($resultados as $r): ?>
                <?php
                $estado = (string) $r['estado'];
                [$veredicto, $clase] = $veredictos[$estado] ?? ['Sin inscripción', 'anulado'];

                $origen = $r['tipo_participante'] === 'libre'
                    ? 'Estudiante libre'
                    : (string) ($r['institucion'] ?? '—');
                ?>
                <li class="control-ficha control-ficha--<?= View::e($clase) ?>">

                    <p class="control-ficha__veredicto"><?= View::e($veredicto) ?></p>

                    <p class="control-ficha__nombre">
                        <?= View::e(trim($r['ap_paterno'] . ' ' . $r['ap_materno'])) ?>,
                        <?= View::e($r['nombres']) ?>
                    </p>

                    <dl class="control-ficha__datos">
                        <div>
                            <dt>Documento</dt>
                            <dd><?= View::e($r['dni']) ?></dd>
                        </div>
                        <div>
                            <dt>Categoría</dt>
                            <dd>
                                <?= View::e(ucfirst((string) $r['nivel'])) ?>
                                <?= (int) $r['grado'] ?>°
                            </dd>
                        </div>
                        <div>
                            <dt>Procedencia</dt>
                            <dd><?= View::e($origen) ?></dd>
                        </div>
                        <div>
                            <dt>Código</dt>
                            <dd class="control-ficha__codigo"><?= View::e($r['codigo_correlativo']) ?></dd>
                        </div>
                    </dl>

                    <?php if ($estado === 'anulada'): ?>
                        <p class="control-ficha__nota">
                            Esta inscripción fue anulada.
                            <?php if (!empty($r['requiere_devolucion'])): ?>
                                Tiene una devolución pendiente.
                            <?php endif; ?>
                            Si el estudiante trae un carné impreso, ese carné ya no es válido.
                        </p>
                    <?php elseif ($estado === 'pendiente'): ?>
                        <p class="control-ficha__nota">
                            La secretaría todavía no ha confirmado el pago. Derívalo a la
                            mesa de inscripciones antes de dejarlo pasar.
                        </p>
                    <?php endif; ?>

                </li>
            <?php endforeach; ?>
        </ul>

    <?php endif; ?>

<?php endif; ?>
