/**
 * Reportes contables: el botón de imprimir (D-59).
 *
 * Las tres pantallas están hechas para salir en papel —un arqueo se firma y se
 * entrega con el dinero—, así que el botón está a la vista en vez de confiar en
 * que quien las use conozca Ctrl+P.
 *
 * Va en un archivo y no en un `onclick`: en este proyecto no hay ni un solo
 * manejador en línea, y no vale la pena estrenar la excepción aquí.
 *
 * El botón se marca con `data-imprimir` y no con una clase, porque una clase de
 * comportamiento acaba pareciendo una de estilo y alguien la reutiliza para
 * cambiarle el color a otra cosa.
 *
 * Si el navegador no ejecutara este archivo, la pantalla sigue imprimiéndose
 * desde el menú del navegador: el botón es un atajo, no la única puerta.
 */
(function () {
    document.querySelectorAll('[data-imprimir]').forEach(function (boton) {
        boton.addEventListener('click', function () {
            window.print();
        });
    });
})();
