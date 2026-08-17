<?php

declare(strict_types=1);

use Core\View;
?>
<h1 class="titulo">404</h1>
<p class="subtitulo">La página que buscas no existe.</p>
<p><a class="boton boton--tenue" href="<?= View::e(View::url('/panel')) ?>">Volver al panel</a></p>
