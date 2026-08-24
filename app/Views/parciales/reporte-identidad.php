<?php

declare(strict_types=1);

use Core\Auth;
use Core\Fecha;
use Core\View;

/** @var string $rotulo    nombre del documento */
/** @var string $concurso  nombre del concurso */
/** @var string $corte     qué entra en el documento y qué queda fuera */

/*
 * La identidad del documento (D-59).
 *
 * Un reporte contable sin esto no se puede archivar: dos impresiones del mismo
 * día con cifras distintas son indistinguibles si ninguna dice a qué hora se
 * sacó. Y el criterio de corte va escrito, no supuesto — quien lo lee dentro de
 * seis meses no tiene por qué saber qué filas entraron.
 *
 * Se imprime, a diferencia del botón: es parte del papel.
 */
?>
<dl class="reporte-identidad">
    <div class="reporte-identidad__par">
        <dt>Documento</dt>
        <dd><?= View::e($rotulo) ?></dd>
    </div>
    <div class="reporte-identidad__par">
        <dt>Concurso</dt>
        <dd><?= View::e($concurso) ?></dd>
    </div>
    <div class="reporte-identidad__par">
        <dt>Generado</dt>
        <dd><?= View::e(Fecha::ahora()) ?></dd>
    </div>
    <div class="reporte-identidad__par">
        <dt>Por</dt>
        <dd class="mayus"><?= View::e(Auth::nombres()) ?></dd>
    </div>
    <div class="reporte-identidad__par reporte-identidad__par--ancho">
        <dt>Criterio</dt>
        <dd><?= View::e($corte) ?></dd>
    </div>
</dl>
