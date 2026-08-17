<?php

declare(strict_types=1);

use Core\View;
?>
<h1 class="titulo">Ocurrió un error</h1>
<p class="subtitulo">
    Algo falló al procesar la solicitud. El detalle quedó registrado en el log
    del servidor; nada de lo que hiciste se perdió a medias.
</p>
<p><a class="boton boton--tenue" href="<?= View::e(View::url('/panel')) ?>">Volver al panel</a></p>
