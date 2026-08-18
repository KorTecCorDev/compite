/**
 * Avisos: que ninguno se quede fuera de la pantalla.
 *
 * Dos comportamientos, y los dos existen por lo mismo (D-30): un mensaje que el
 * usuario no ve es un mensaje que no se dio.
 *
 * 1. La franja de avisos del layout es pegajosa y se cierra a mano. No se
 *    desvanece sola: aquí se confirman pagos, y «se confirmaron 3 pagos por
 *    S/ 30.00» tiene que poder releerse mientras se comprueba la caja.
 *
 * 2. Cuando el servidor devuelve un formulario con errores de campo, el foco va
 *    al primero. En la ficha de institución, con veinte campos repartidos en
 *    cuatro grupos, el error podía estar a dos pantallas de distancia del sitio
 *    donde quedó la vista.
 */
(function () {
    document.querySelectorAll('.aviso__cerrar').forEach(function (boton) {
        boton.addEventListener('click', function () {
            const aviso = boton.closest('.aviso');
            if (!aviso) return;

            aviso.remove();

            /* Sin avisos dentro, la franja seguiría ocupando su espacio pegajoso. */
            const franja = document.getElementById('avisos-flash');
            if (franja && franja.querySelectorAll('.aviso').length === 0) franja.remove();
        });
    });

    const primerError = document.querySelector('.campo--error');
    if (!primerError) return;

    const control = primerError.querySelector('input, select, textarea');

    /*
     * `center` y no `start`: la franja de avisos está pegada arriba y taparía
     * el campo justo después de desplazarse hasta él.
     */
    primerError.scrollIntoView({ block: 'center', behavior: 'smooth' });

    if (control && !control.readOnly && !control.disabled) {
        control.focus({ preventScroll: true });
    }
})();
