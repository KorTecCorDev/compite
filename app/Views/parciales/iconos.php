<?php

declare(strict_types=1);

/*
 * Sprite de íconos del sistema (D-48).
 *
 * Por qué así y no de otra forma:
 *
 *   · Sin librería. Font Awesome y compañía traen entre 30 y 70 KB de fuentes
 *     que `gulpfile.js` no sabe copiar —el pipeline solo compila `scss` y `js`—,
 *     así que habría que añadir un paso o copiarlas a mano, y las copias a mano
 *     se pudren en el despliegue. Los lectores de pantalla, además, leen los
 *     glifos de una icon font como caracteres sueltos.
 *   · Sin JavaScript. lucide/feather reescriben el DOM al cargar: los íconos
 *     entrarían con un parpadeo, y `node_modules/` nunca sube a Hostinger.
 *   · Sin <img src="...svg">. Un <img> no hereda `currentColor`, y entonces el
 *     ícono de anular no podría ser rojo ni cambiar en :hover.
 *
 * Queda un <symbol> por ícono, impreso UNA vez al final del layout, y cada uso
 * cuesta unos 55 bytes: <svg class="icono"><use href="#i-lapiz"></use></svg>.
 *
 * Los trazos van sin `fill` ni `stroke` propios: los pone `.icono` en el CSS,
 * con `stroke: currentColor`, que es lo que permite que cada ícono tome el color
 * del enlace que lo contiene.
 */
?>
<svg xmlns="http://www.w3.org/2000/svg" class="sprite-iconos" aria-hidden="true" focusable="false">
    <!-- Corregir categoría: lápiz. -->
    <symbol id="i-lapiz" viewBox="0 0 24 24">
        <path d="M12 20h9"/>
        <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>
    </symbol>

    <!--
        Anular: círculo tachado, NO un tacho de basura. Anular no borra nada —la
        fila anulada se queda en el listado, con su motivo y su firma—, y un
        tacho invitaría a leerlo como un borrado.
    -->
    <symbol id="i-prohibido" viewBox="0 0 24 24">
        <circle cx="12" cy="12" r="9"/>
        <path d="M5.6 5.6 18.4 18.4"/>
    </symbol>

    <!-- Descargar el carné: hoja con flecha hacia abajo. -->
    <symbol id="i-descargar" viewBox="0 0 24 24">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/>
        <path d="M14 2v6h6"/>
        <path d="M12 12v5"/>
        <path d="m9.5 14.5 2.5 2.5 2.5-2.5"/>
    </symbol>

    <!-- Regenerar el carné: flecha circular. -->
    <symbol id="i-recargar" viewBox="0 0 24 24">
        <path d="M21 12a9 9 0 1 1-2.64-6.36"/>
        <path d="M21 3v5h-5"/>
    </symbol>

    <!-- Reinscribir: persona con un más. -->
    <symbol id="i-persona-mas" viewBox="0 0 24 24">
        <path d="M15 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
        <circle cx="8.5" cy="7" r="4"/>
        <path d="M19 8v6"/>
        <path d="M22 11h-6"/>
    </symbol>

    <!-- Ver el carné público (el mismo que abre el QR). -->
    <symbol id="i-ojo" viewBox="0 0 24 24">
        <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/>
        <circle cx="12" cy="12" r="3"/>
    </symbol>
</svg>
