<?php

declare(strict_types=1);

use Core\Sesion;
use Core\View;
?>
<h1 class="titulo">COCIAP 2026</h1>
<p class="subtitulo">Sistema de inscripciones — acceso interno</p>

<form method="post" action="<?= View::e(View::url('/login')) ?>" class="formulario" autocomplete="on">
    <input type="hidden" name="_csrf" value="<?= View::e(Sesion::tokenCsrf()) ?>">

    <label class="campo">
        <span class="campo__etiqueta">Correo</span>
        <input type="email" name="correo" required autofocus autocomplete="username"
               value="<?= View::e($_POST['correo'] ?? '') ?>">
    </label>

    <label class="campo">
        <span class="campo__etiqueta">Contraseña</span>
        <input type="password" name="password" required autocomplete="current-password">
    </label>

    <button type="submit" class="boton boton--principal">Ingresar</button>
</form>
