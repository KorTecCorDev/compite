<?php

declare(strict_types=1);

use App\Models\Concurso;
use Core\Auth;
use Core\Sesion;
use Core\View;

/** @var array<string, mixed> $concurso */
/** @var array<int, array<string, mixed>> $inscripciones */
/** @var array<int, array<string, mixed>> $instituciones */
/** @var array<string, mixed> $filtros */
/** @var array<string, mixed> $resumen */
/** @var int $total */
/** @var int $tope */

$sel = static fn (string $clave, string $valor): string
    => (string) ($filtros[$clave] ?? '') === $valor ? 'selected' : '';

$hayPendientes = false;
foreach ($inscripciones as $ins) {
    if ($ins['estado'] === 'pendiente') { $hayPendientes = true; break; }
}
?>
<div class="encabezado">
    <div>
        <h1 class="titulo">Inscripciones</h1>
        <p class="subtitulo"><?= View::e($concurso['nombre']) ?></p>
    </div>
    <div class="acciones">
        <a class="boton boton--principal" href="<?= View::e(View::url('/inscripciones/delegacion')) ?>">
            Nueva delegación
        </a>
        <a class="boton boton--tenue" href="<?= View::e(View::url('/inscripciones/libre')) ?>">
            Estudiante libre
        </a>
        <?php if (Auth::esAdministrador()): ?>
            <?php
            /*
             * Las actas van aquí y no en la barra de navegación: es una
             * descarga, no una sección, y esta es la pantalla donde el
             * administrador ya está mirando quién compite. Solo para él.
             *
             * Baja un ZIP con un libro por bolsa de competencia, así que el
             * rótulo va en plural: quien lo pulsa tiene que saber que recibe
             * varios archivos y no uno.
             */
            ?>
            <a class="boton boton--tenue" href="<?= View::e(View::url('/reportes/actas.zip')) ?>">
                Descargar actas (ZIP)
            </a>
        <?php endif; ?>
    </div>
</div>

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
        <span class="metrica__nombre">
            Recaudado · por cobrar S/ <?= number_format((float) $resumen['por_cobrar'], 2) ?>
        </span>
    </div>
</section>

<form method="get" action="<?= View::e(View::url('/inscripciones')) ?>" class="filtros">
    <input type="search" name="q" placeholder="Código, documento o nombre…"
           value="<?= View::e((string) $filtros['q']) ?>">

    <select name="institucion_id">
        <option value="">Todas las delegaciones</option>
        <?php foreach ($instituciones as $ie): ?>
            <option value="<?= (int) $ie['id'] ?>"
                <?= (string) $filtros['institucion_id'] === (string) $ie['id'] ? 'selected' : '' ?>>
                <?= View::e($ie['nombre']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select name="tipo_origen">
        <option value="">Todo origen</option>
        <option value="publica"      <?= $sel('tipo_origen', 'publica') ?>>I.E. pública</option>
        <option value="privada"      <?= $sel('tipo_origen', 'privada') ?>>I.E. privada</option>
        <option value="libre"        <?= $sel('tipo_origen', 'libre') ?>>Estudiante libre</option>
        <option value="organizadora" <?= $sel('tipo_origen', 'organizadora') ?>>COCIAP</option>
    </select>

    <select name="nivel">
        <option value="">Todo nivel</option>
        <option value="primaria"   <?= $sel('nivel', 'primaria') ?>>Primaria</option>
        <option value="secundaria" <?= $sel('nivel', 'secundaria') ?>>Secundaria</option>
    </select>

    <select name="grado">
        <option value="">Todo grado</option>
        <?php for ($g = 1; $g <= 6; $g++): ?>
            <option value="<?= $g ?>" <?= $sel('grado', (string) $g) ?>><?= $g ?>°</option>
        <?php endfor; ?>
    </select>

    <select name="estado">
        <option value="">Todo estado</option>
        <option value="pendiente"  <?= $sel('estado', 'pendiente') ?>>Pendiente</option>
        <option value="confirmada" <?= $sel('estado', 'confirmada') ?>>Confirmada</option>
        <option value="anulada"    <?= $sel('estado', 'anulada') ?>>Anulada</option>
    </select>

    <button type="submit" class="boton boton--tenue">Filtrar</button>
    <a class="enlace-tenue" href="<?= View::e(View::url('/inscripciones')) ?>">Limpiar</a>
</form>

<?php if (!empty($filtros['institucion_id'])): ?>
    <?php
    /*
     * Solo aparece con una delegación elegida, porque solo entonces la hoja
     * tiene un destinatario claro. Imprimir «todos los carnés del concurso»
     * sería otra cosa: cientos de páginas y un PDF que en hosting compartido
     * se queda sin tiempo de ejecución a medio generar.
     */
    $confirmadas = 0;
    foreach ($inscripciones as $ins) {
        if ($ins['estado'] === 'confirmada') { $confirmadas++; }
    }
    ?>
    <?php if ($confirmadas > 0): ?>
        <p class="acciones-delegacion">
            <a class="boton boton--principal"
               href="<?= View::e(View::url('/delegaciones/' . (int) $filtros['institucion_id'] . '/carnes.pdf')) ?>">
                Imprimir carnés de esta delegación
            </a>
            <span class="acciones-delegacion__nota">
                <?= (int) $confirmadas ?> confirmada(s) · hoja A4, 10 carnés por página, con guías de corte
            </span>
        </p>
    <?php endif; ?>
<?php endif; ?>

<?php if (count($inscripciones) < $total): ?>
    <?php /* El corte por tope deja de ser silencioso (D-40): la misma consulta
             alimenta la hoja de carnés por delegación, y una hoja incompleta no
             se nota hasta que faltan carnés en la puerta. */ ?>
    <div class="aviso aviso--aviso">
        <strong>Se están mostrando <?= count($inscripciones) ?> de <?= (int) $total ?> inscripciones.</strong>
        El listado se corta en <?= (int) $tope ?> filas. Afina los filtros —por delegación,
        modalidad o grado— para verlas todas; <strong>la hoja de carnés de una delegación
        también se corta ahí</strong>.
    </div>
<?php endif; ?>

<?php if ($inscripciones === []): ?>

    <div class="vacio">
        No hay inscripciones que coincidan.
        <a href="<?= View::e(View::url('/inscripciones/delegacion')) ?>">Registrar una delegación</a>
        o <a href="<?= View::e(View::url('/inscripciones/libre')) ?>">un estudiante libre</a>.
    </div>

<?php else: ?>

<form method="post" action="<?= View::e(View::url('/pagos/confirmar')) ?>" id="form-cobro">
    <input type="hidden" name="_csrf" value="<?= View::e(Sesion::tokenCsrf()) ?>">

    <?php
    /*
     * Los filtros que el usuario eligió, para poder devolvérselos si el cobro no
     * pasa la validación (D-48).
     *
     * Antes ese error redirigía a `?estado=pendiente`: imponía un filtro que
     * nadie pidió y, de paso, tiraba el que sí se había elegido. Se corregía el
     * medio de pago y se volvía mirando un conjunto de filas distinto del que se
     * estaba cobrando.
     *
     * Va como campo del formulario y no leyendo `HTTP_REFERER`, que lo pone el
     * cliente y no se puede usar como destino sin comprobarlo. Al volver, la
     * cadena se pasa por `Inscripcion::urlListado()`, que solo deja pasar las
     * seis claves conocidas.
     */
    $filtrosActivos = array_filter($filtros, static fn ($v): bool => trim((string) $v) !== '');
    ?>
    <input type="hidden" name="volver" value="<?= View::e(http_build_query($filtrosActivos)) ?>">

    <div class="tabla-contenedor">
        <table class="tabla">
            <thead>
                <tr>
                    <th style="width:2.2rem">
                        <?php if ($hayPendientes): ?>
                            <input type="checkbox" id="marcar-todas" title="Seleccionar todas las pendientes">
                        <?php endif; ?>
                    </th>
                    <th>Código</th>
                    <th>Apellidos y nombres</th>
                    <th>Origen</th>
                    <th>Categoría</th>
                    <th>Monto</th>
                    <th>Estado</th>
                    <th>Responsable</th>
                    <th class="tabla__acciones">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($inscripciones as $ins): ?>
                <?php $esPendiente = $ins['estado'] === 'pendiente'; ?>
                <?php
                /*
                 * ¿Es MÍA esta fila? (D-52) Corregir y reinscribir son de quien
                 * registró la inscripción; el administrador puede con todas.
                 *
                 * Cobrar NO pasa por aquí a propósito: una delegación de treinta
                 * puede haberla registrado entre dos secretarias y paga con un
                 * solo Yape, así que partir el cobro en dos por quién tecleó cada
                 * nombre inventaría un descuadre de caja que la realidad no tiene.
                 * Quién cobró queda firmado igual, en `confirmado_por`.
                 */
                $puedeOperar = Auth::puedeOperar(isset($ins['usuario_id']) ? (int) $ins['usuario_id'] : null);
                ?>
                <?php
                /*
                 * Ancla por inscripción (D-48). Sustituye a los redirects que
                 * volvían con `?q=CÓDIGO` puesto: aquellos mostraban el listado
                 * FILTRADO a una sola fila, escondiendo todo lo demás. Ahora la
                 * acción devuelve a la lista completa con `#ins-N`, el navegador
                 * baja hasta la fila y `:target` la resalta, sin filtrar nada.
                 *
                 * El listado se ordena por apellido, no por fecha, así que sin
                 * el ancla la fila recién tocada queda enterrada a media tabla.
                 */
                ?>
                <tr id="ins-<?= (int) $ins['id'] ?>">
                    <td data-etiqueta="Cobrar">
                        <?php if ($esPendiente): ?>
                            <input type="checkbox" name="ids[]" value="<?= (int) $ins['id'] ?>"
                                   class="casilla-pago" data-monto="<?= (float) $ins['monto'] ?>">
                        <?php endif; ?>
                    </td>
                    <td data-etiqueta="Código">
                        <code><?= View::e($ins['codigo_correlativo']) ?></code>
                        <?php if ($ins['estado'] === 'confirmada'): ?>
                            <br>
                            <a class="accion enlace-tenue" target="_blank"
                               title="Ver el carné (la misma página que abre el QR)"
                               href="<?= View::e(View::url('/carne/' . $ins['codigo_correlativo'])) ?>">
                                <svg class="icono" width="18" height="18" aria-hidden="true" focusable="false"><use href="#i-ojo"></use></svg>
                                <span class="accion__texto">Ver carné</span>
                            </a>
                        <?php endif; ?>
                    </td>
                    <td class="tabla__principal">
                        <strong><?= View::e($ins['ap_paterno'] . ' ' . $ins['ap_materno']) ?></strong>,
                        <?= View::e($ins['nombres']) ?>
                        <br><span class="tenue"><?= View::e($ins['dni']) ?></span>
                    </td>
                    <td class="tenue" data-etiqueta="Origen">
                        <?php
                        /* La píldora dice la MODALIDAD con la que se cobró, no el
                           tipo del colegio (D-37): el anfitrión es público y aun así
                           compite y paga como COCIAP. */
                        $modalidad = (string) $ins['tipo_origen'];
                        ?>
                        <?php if ($ins['tipo_participante'] === 'libre'): ?>
                            <span class="etiqueta etiqueta--neutra">libre</span>
                        <?php else: ?>
                            <?= View::e($ins['institucion'] ?? '—') ?>
                            <span class="etiqueta etiqueta--<?= View::e($modalidad) ?>">
                                <?= View::e(Concurso::etiquetaModalidad($modalidad)) ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td data-etiqueta="Categoría"><?= View::e(ucfirst((string) $ins['nivel'])) ?> <?= (int) $ins['grado'] ?>°</td>
                    <td data-etiqueta="Monto">
                        S/ <?= number_format((float) $ins['monto'], 2) ?>
                        <?php if (!empty($ins['medio_pago'])): ?>
                            <br><span class="tenue"><?= View::e((string) $ins['medio_pago']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-etiqueta="Estado">
                        <span class="etiqueta etiqueta--estado-<?= View::e((string) $ins['estado']) ?>">
                            <?= View::e((string) $ins['estado']) ?>
                        </span>
                        <?php if (!empty($ins['requiere_devolucion'])): ?>
                            <br><span class="etiqueta etiqueta--alerta">por devolver</span>
                        <?php endif; ?>
                    </td>
                    <?php
                    /* Quién registró la inscripción (D-39). Con varias secretarias
                       trabajando a la vez, un registro incorrecto tiene que poder
                       atribuirse sin salir del listado. Quién cobró y quién anuló
                       también quedan guardados, pero no se muestran aquí por
                       decisión del propietario: la columna es una sola. */
                    ?>
                    <td class="tenue" data-etiqueta="Responsable"><?= View::e((string) $ins['registrado_por']) ?></td>
                    <?php
                    /*
                     * Columna de solo íconos en escritorio (D-48). El rótulo NO
                     * desaparece del HTML: sigue en un <span class="accion__texto">
                     * que el CSS recorta con `clip-path`. Eso lo mantiene en el
                     * árbol de accesibilidad —es el nombre del enlace para un
                     * lector de pantalla— y lo devuelve a la vista en la ficha de
                     * teléfono, donde hay sitio de sobra y cuatro dibujos sueltos
                     * se identifican peor que en el escritorio.
                     *
                     * El `title` es lo que da el globo al pasar el ratón. Aquí no
                     * es un adorno: «anular» es irreversible y mueve dinero al
                     * fondo de devoluciones, y queda pegada a «corregir», que es
                     * la acción inofensiva.
                     */
                    ?>
                    <td class="tabla__acciones" data-etiqueta="Acciones">
                        <?php if ($ins['estado'] !== 'anulada'): ?>
                            <?php
                            /* Corregir es de quien registró la fila (D-52). La
                               condición va aquí y no en el `if` de arriba a
                               propósito: «Anular» cuelga del mismo bloque y
                               tiene SU propia regla —solo el administrador—.
                               Compartir una condición entre dos acciones con
                               permisos distintos funciona solo mientras un rol
                               cumpla las dos, y ese es el tipo de dependencia
                               que se rompe en silencio. */
                            ?>
                            <?php if ($puedeOperar): ?>
                                <a class="accion enlace-tenue" title="Corregir la inscripción"
                                   href="<?= View::e(View::url('/inscripciones/' . $ins['id'] . '/corregir')) ?>">
                                    <svg class="icono" width="18" height="18" aria-hidden="true" focusable="false"><use href="#i-lapiz"></use></svg>
                                    <span class="accion__texto">Corregir</span>
                                </a>
                            <?php endif; ?>
                            <?php
                            /* Anular es exclusivo del administrador (D-51): es la
                               única acción irreversible de la fila y la única que
                               mueve dinero al fondo de devoluciones. La secretaria
                               no ve el botón, y el controlador además rechaza el
                               POST — que no se dibuje no es la protección, es la
                               cortesía de no ofrecer lo que no se puede hacer. */
                            ?>
                            <?php if (Auth::esAdministrador()): ?>
                                <button type="button" class="accion enlace-peligro boton-anular"
                                        title="Anular definitivamente"
                                        data-id="<?= (int) $ins['id'] ?>"
                                        data-nombre="<?= View::e($ins['ap_paterno'] . ' ' . $ins['nombres']) ?>"
                                        data-pagada="<?= $ins['estado'] === 'confirmada' ? '1' : '0' ?>"
                                        data-monto="<?= number_format((float) $ins['monto'], 2) ?>">
                                    <svg class="icono" width="18" height="18" aria-hidden="true" focusable="false"><use href="#i-prohibido"></use></svg>
                                    <span class="accion__texto">Anular</span>
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php
                        /* Reinscribir solo cuando el participante se quedó SIN
                           ninguna inscripción viva, que es cuando de verdad está
                           fuera del concurso. Ofrecerlo en cualquier anulada
                           llevaría a duplicar la inscripción de alguien que ya
                           está dentro.

                           Hasta D-50 esa condición hacía además otro trabajo:
                           cada corrección de categoría dejaba una anulada
                           detrás, y sin el filtro el listado ofrecía reinscribir
                           en filas que no eran bajas de nadie. Ya no las deja
                           —corregir es un UPDATE en su sitio—, así que a partir
                           de ahora una anulada sin inscripción viva es lo que
                           parece: alguien que se quedó fuera. */
                        ?>
                        <?php if ($ins['estado'] === 'anulada' && empty($ins['participante_activo']) && $puedeOperar): ?>
                            <a class="accion enlace-tenue" title="Reinscribir"
                               href="<?= View::e(View::url('/inscripciones/' . $ins['id'] . '/reinscribir')) ?>">
                                <svg class="icono" width="18" height="18" aria-hidden="true" focusable="false"><use href="#i-persona-mas"></use></svg>
                                <span class="accion__texto">Reinscribir</span>
                            </a>
                        <?php endif; ?>

                        <?php if ($ins['estado'] === 'confirmada'): ?>
                            <a class="accion enlace-tenue" title="Descargar el carné en PDF"
                               href="<?= View::e(View::url('/inscripciones/' . $ins['id'] . '/carne.pdf')) ?>">
                                <svg class="icono" width="18" height="18" aria-hidden="true" focusable="false"><use href="#i-descargar"></use></svg>
                                <span class="accion__texto">PDF</span>
                            </a>

                            <!--
                                Regenerar sirve si el PDF se perdió del disco o si
                                se corrigió algún dato de la ficha después de emitirlo.

                                El botón está dentro de la tabla pero pertenece al
                                formulario `form-regenerar`, que vive fuera del de
                                cobro. Aquí había un formulario anidado, que no es
                                HTML válido: el navegador ignora su etiqueta de
                                apertura y su etiqueta de cierre cierra el
                                formulario de cobro en la primera fila confirmada. Todo lo que venía después
                                —el resto de casillas y el botón de confirmar—
                                quedaba fuera de cualquier formulario, y por eso no
                                se podía cobrar nada desde el listado completo.
                            -->
                            <button type="submit" class="accion enlace-tenue enlace-boton"
                                    title="Regenerar el carné"
                                    form="form-regenerar"
                                    formaction="<?= View::e(View::url('/inscripciones/' . $ins['id'] . '/carne/regenerar')) ?>">
                                <svg class="icono" width="18" height="18" aria-hidden="true" focusable="false"><use href="#i-recargar"></use></svg>
                                <span class="accion__texto">Regenerar</span>
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($hayPendientes): ?>
    <div class="barra-cobro" id="barra-cobro" hidden>
        <div class="barra-cobro__resumen">
            <strong id="cobro-cantidad">0</strong> seleccionada(s) ·
            total <strong id="cobro-total">S/ 0.00</strong>
        </div>

        <label class="barra-cobro__campo">
            <span class="campo__etiqueta">Medio de pago *</span>
            <select name="medio_pago" id="medio-pago" required>
                <option value="">Seleccionar…</option>
                <option value="yape">Yape</option>
                <option value="transferencia">Transferencia (BCP)</option>
                <option value="efectivo">Efectivo</option>
            </select>
        </label>

        <!--
            El `required` no está en el HTML: lo pone y lo quita el JS junto con
            la visibilidad. Un campo obligatorio dentro de un bloque oculto
            bloquea el envío sin decir por qué —el navegador intenta enfocar algo
            que no se ve— y dejaría la caja parada al cobrar en efectivo.
        -->
        <label class="barra-cobro__campo" id="campo-yape" hidden>
            <span class="campo__etiqueta">Código de seguridad *</span>
            <input type="text" name="yape_codigo" id="yape-codigo" maxlength="3"
                   inputmode="numeric" pattern="[0-9]{3}" placeholder="3 dígitos">
        </label>

        <button type="submit" class="boton boton--principal">
            Confirmar pago y emitir carnés
        </button>
    </div>
    <?php endif; ?>
</form>

<!--
    Regeneración del carné: un solo formulario para todas las filas. La acción
    concreta la pone cada botón con `formaction`, así no hace falta un formulario
    por fila dentro del de cobro.
-->
<form method="post" id="form-regenerar" class="oculto"
      onsubmit="return confirm('¿Volver a generar el PDF de este carné?');">
    <input type="hidden" name="_csrf" value="<?= View::e(Sesion::tokenCsrf()) ?>">
</form>

<!-- Anulación definitiva: formulario aparte, para que no viaje con el cobro.
     Solo se pinta para el administrador (D-51): sin botones que lo disparen no
     haría nada, pero dejarlo en el HTML de una secretaria sería ofrecerle el
     mecanismo de una acción que el servidor le va a rechazar. -->
<?php if (Auth::esAdministrador()): ?>
    <form method="post" id="form-anular" class="oculto"
          data-url-base="<?= View::e(View::url('/inscripciones/')) ?>">
        <input type="hidden" name="_csrf" value="<?= View::e(Sesion::tokenCsrf()) ?>">
        <input type="hidden" name="motivo" id="motivo-anulacion">
    </form>
<?php endif; ?>

<?php
/*
 * Leyenda de la columna de acciones (D-48).
 *
 * No es un adorno ni una concesión: en el escritorio el rótulo de cada acción
 * está recortado, así que esta lista es el ÚNICO sitio de la pantalla donde las
 * palabras aparecen. Sin ella, un ícono que no se reconoce no se puede
 * averiguar más que probándolo, y una de las seis acciones es irreversible.
 *
 * Aquí los rótulos van visibles siempre —sin `.accion__texto`—, que es
 * justamente lo que distingue una leyenda de un botón.
 */
?>
<ul class="leyenda">
    <li class="leyenda__item">
        <svg class="icono" width="18" height="18" aria-hidden="true" focusable="false"><use href="#i-ojo"></use></svg>
        Ver carné
    </li>
    <li class="leyenda__item">
        <svg class="icono" width="18" height="18" aria-hidden="true" focusable="false"><use href="#i-lapiz"></use></svg>
        Corregir
    </li>
    <?php /* La leyenda solo nombra lo que esa persona puede hacer: anunciarle a
             la secretaria un ícono que no va a encontrar en ninguna fila es
             mandarla a buscar algo que no existe (D-51). */ ?>
    <?php if (Auth::esAdministrador()): ?>
        <li class="leyenda__item leyenda__item--peligro">
            <svg class="icono" width="18" height="18" aria-hidden="true" focusable="false"><use href="#i-prohibido"></use></svg>
            Anular
        </li>
    <?php endif; ?>
    <li class="leyenda__item">
        <svg class="icono" width="18" height="18" aria-hidden="true" focusable="false"><use href="#i-descargar"></use></svg>
        Descargar PDF
    </li>
    <li class="leyenda__item">
        <svg class="icono" width="18" height="18" aria-hidden="true" focusable="false"><use href="#i-recargar"></use></svg>
        Regenerar carné
    </li>
    <li class="leyenda__item">
        <svg class="icono" width="18" height="18" aria-hidden="true" focusable="false"><use href="#i-persona-mas"></use></svg>
        Reinscribir
    </li>
</ul>

<p class="nota">
    Mostrando <?= count($inscripciones) ?> inscripción(es).
    «Corregir» arregla los datos en la misma inscripción, sin anular nada, y deja constancia de quién cambió qué.
    «Anular» es definitiva y, si ya estaba pagada, suma el monto al fondo de devoluciones.
    <?php
    /*
     * La regla de D-52, dicha una vez al pie y no repetida en cada fila.
     *
     * Las acciones ajenas se ocultan, y un ícono que falta sin explicación se
     * lee como un fallo del sistema. La columna «Responsable» dice de quién es
     * cada fila; esta frase dice qué significa que lo sea. Solo se muestra a
     * quien le afecta: al administrador no le falta ninguna acción en ninguna
     * fila, así que para él sería ruido.
     */
    ?>
    <?php if (!Auth::esAdministrador()): ?>
        «Corregir» y «Reinscribir» aparecen únicamente en las inscripciones que registraste tú
        —mira la columna «Responsable»—. Cobrar y descargar carnés funciona en todas,
        porque una delegación puede haberse registrado entre varias personas y paga junta.
    <?php endif; ?>
</p>

<script src="<?= View::e(View::asset('build/js/inscripciones.js')) ?>" defer></script>

<?php endif; ?>
