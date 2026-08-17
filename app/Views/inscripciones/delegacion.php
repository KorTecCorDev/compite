<?php

declare(strict_types=1);

use Core\Sesion;
use Core\View;

/** @var array<string, mixed> $concurso */
/** @var array<int, array<string, mixed>> $instituciones */
/** @var array<string, mixed>|null $institucion */
/** @var array<int, array<string, mixed>> $categorias */
/** @var array<int, array<string, mixed>> $tarifas */
/** @var array<int, array<string, mixed>> $filas */
/** @var array<string, string> $errores */

$montos = [];
foreach ($tarifas as $t) {
    $montos[$t['tipo_origen']] = (float) $t['monto'];
}

// Se repintan las filas enviadas; si no hay, se ofrecen 5 en blanco.
$filasAPintar = $filas !== [] ? $filas : array_fill(0, 5, []);
?>
<div class="encabezado">
    <div>
        <h1 class="titulo">Inscripción por delegación</h1>
        <p class="subtitulo">
            Cada estudiante genera su propia inscripción, aunque los registres todos juntos.
        </p>
    </div>
    <a class="boton boton--tenue" href="<?= View::e(View::url('/inscripciones')) ?>">Ver inscripciones</a>
</div>

<?php if ($errores !== []): ?>
    <div class="aviso aviso--error">
        <strong>No se guardó nada. Revisa <?= count($errores) ?> punto<?= count($errores) === 1 ? '' : 's' ?>:</strong>
        <ul class="lista-errores">
            <?php foreach ($errores as $mensaje): ?>
                <li><?= View::e($mensaje) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="<?= View::e(View::url('/inscripciones/delegacion')) ?>" class="formulario-largo">
    <input type="hidden" name="_csrf" value="<?= View::e(Sesion::tokenCsrf()) ?>">

    <fieldset class="grupo">
        <legend class="grupo__titulo">Institución educativa</legend>

        <div class="rejilla">
            <label class="campo campo--ancho<?= isset($errores['institucion_id']) ? ' campo--error' : '' ?>">
                <span class="campo__etiqueta">Delegación *</span>
                <select name="institucion_id" id="selector-ie" required>
                    <option value="">Seleccionar institución…</option>
                    <?php foreach ($instituciones as $ie): ?>
                        <option value="<?= (int) $ie['id'] ?>"
                                data-tipo="<?= View::e($ie['tipo']) ?>"
                                <?= ($institucion['id'] ?? null) == $ie['id'] ? 'selected' : '' ?>>
                            <?= View::e($ie['nombre']) ?> — <?= View::e($ie['distrito']) ?>
                            (<?= $ie['tipo'] === 'publica' ? 'pública' : 'privada' ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="campo__ayuda">
                    ¿No está en la lista?
                    <a href="<?= View::e(View::url('/instituciones/nueva')) ?>">Regístrala primero</a>.
                </span>
            </label>
        </div>

        <div id="resumen-tarifa" class="caja-tarifa" hidden>
            <span class="caja-tarifa__texto">
                Tarifa aplicada: <strong id="tarifa-monto">—</strong> por estudiante
            </span>
            <span class="caja-tarifa__nota">
                Se calcula sola según el tipo de institución. No es editable.
            </span>
        </div>
    </fieldset>

    <fieldset class="grupo">
        <legend class="grupo__titulo">Participantes</legend>

        <p class="grupo__ayuda">
            Deja en blanco las filas que no uses. El documento acepta DNI de 8 dígitos
            o carné de extranjería.
        </p>

        <div class="tabla-contenedor">
            <table class="tabla tabla--formulario">
                <thead>
                    <tr>
                        <th style="width:2.5rem">#</th>
                        <th style="width:9rem">DNI o C.E.</th>
                        <th>Apellido paterno</th>
                        <th>Apellido materno</th>
                        <th>Nombres</th>
                        <th style="width:11rem">Categoría</th>
                    </tr>
                </thead>
                <tbody id="filas-participantes">
                <?php foreach ($filasAPintar as $i => $fila): ?>
                    <tr>
                        <td class="tenue"><?= $i + 1 ?></td>
                        <td>
                            <input type="text" name="p[<?= $i ?>][dni]" maxlength="12"
                                   class="entrada-documento"
                                   value="<?= View::e($fila['dni'] ?? '') ?>">
                        </td>
                        <td>
                            <input type="text" name="p[<?= $i ?>][ap_paterno]" maxlength="100"
                                   value="<?= View::e($fila['ap_paterno'] ?? '') ?>">
                        </td>
                        <td>
                            <input type="text" name="p[<?= $i ?>][ap_materno]" maxlength="100"
                                   value="<?= View::e($fila['ap_materno'] ?? '') ?>">
                        </td>
                        <td>
                            <input type="text" name="p[<?= $i ?>][nombres]" maxlength="150"
                                   value="<?= View::e($fila['nombres'] ?? '') ?>">
                        </td>
                        <td>
                            <select name="p[<?= $i ?>][categoria_id]">
                                <option value="">—</option>
                                <?php foreach ($categorias as $cat): ?>
                                    <option value="<?= (int) $cat['id'] ?>"
                                        <?= ($fila['categoria_id'] ?? null) == $cat['id'] ? 'selected' : '' ?>>
                                        <?= View::e($cat['etiqueta']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="acciones acciones--espaciado">
            <button type="button" class="boton boton--tenue" id="agregar-fila">+ Agregar 5 filas</button>
            <span class="tenue" id="contador-filas"></span>
        </div>
    </fieldset>

    <div id="avisos-documento"></div>

    <div class="acciones">
        <button type="submit" class="boton boton--principal">Registrar delegación</button>
        <a class="boton boton--tenue" href="<?= View::e(View::url('/inscripciones')) ?>">Cancelar</a>
    </div>
</form>

<script>
(function () {
    const montos = <?= json_encode($montos, JSON_UNESCAPED_UNICODE) ?>;
    const selector = document.getElementById('selector-ie');
    const caja = document.getElementById('resumen-tarifa');
    const montoTexto = document.getElementById('tarifa-monto');
    const cuerpo = document.getElementById('filas-participantes');
    const contador = document.getElementById('contador-filas');

    /* --- Tarifa: se muestra, nunca se edita (decisión D-11) --- */
    function pintarTarifa() {
        const opcion = selector.options[selector.selectedIndex];
        const tipo = opcion ? opcion.dataset.tipo : null;

        if (!tipo || montos[tipo] === undefined) {
            caja.hidden = true;
            return;
        }

        montoTexto.textContent = 'S/ ' + Number(montos[tipo]).toFixed(2);
        caja.hidden = false;
    }

    selector.addEventListener('change', pintarTarifa);
    pintarTarifa();

    /* --- Filas dinámicas --- */
    function contarLlenas() {
        let llenas = 0;
        cuerpo.querySelectorAll('tr').forEach(function (fila) {
            const campos = fila.querySelectorAll('input[type="text"]');
            for (const campo of campos) {
                if (campo.value.trim() !== '') { llenas++; return; }
            }
        });
        contador.textContent = llenas === 0
            ? 'Ninguna fila llenada todavía.'
            : llenas + ' participante(s) por registrar.';
    }

    document.getElementById('agregar-fila').addEventListener('click', function () {
        const plantilla = cuerpo.querySelector('tr');
        let indice = cuerpo.querySelectorAll('tr').length;

        for (let n = 0; n < 5; n++) {
            const nueva = plantilla.cloneNode(true);
            nueva.querySelector('td').textContent = String(indice + 1);

            nueva.querySelectorAll('input, select').forEach(function (campo) {
                campo.name = campo.name.replace(/p\[\d+\]/, 'p[' + indice + ']');
                if (campo.tagName === 'SELECT') { campo.selectedIndex = 0; }
                else { campo.value = ''; }
            });

            cuerpo.appendChild(nueva);
            indice++;
        }
        contarLlenas();
    });

    cuerpo.addEventListener('input', contarLlenas);
    contarLlenas();

    /* --- Aviso de documento repetido (D-05: avisa, no bloquea) --- */
    const avisos = document.getElementById('avisos-documento');
    let temporizador = null;

    cuerpo.addEventListener('input', function (evento) {
        if (!evento.target.classList.contains('entrada-documento')) return;

        clearTimeout(temporizador);
        const doc = evento.target.value.trim();
        if (doc.length < 8) return;

        temporizador = setTimeout(async function () {
            try {
                const url = <?= json_encode(View::url('/api/participantes/verificar')) ?> +
                            '?dni=' + encodeURIComponent(doc);
                const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
                if (!r.ok) return;

                const datos = await r.json();
                if (!datos.repetidos || datos.repetidos.length === 0) return;

                const caja = document.createElement('div');
                caja.className = 'aviso aviso--aviso';
                caja.textContent = 'El documento ' + doc + ' ya está registrado en este concurso (' +
                                   datos.repetidos.length + ' coincidencia(s)). ' +
                                   'Puedes continuar si es correcto.';
                avisos.innerHTML = '';
                avisos.appendChild(caja);
            } catch (e) { /* la ayuda falla en silencio, el formulario sigue */ }
        }, 400);
    });
})();
</script>
