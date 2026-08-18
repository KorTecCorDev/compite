/**
 * Listado de inscripciones: barra de cobro y anulación.
 *
 * La URL base de las inscripciones llega en un data-* del formulario oculto de
 * anulación, al que se le arma la acción concreta antes de enviarlo.
 */
(function () {
    const casillas = Array.from(document.querySelectorAll('.casilla-pago'));
    const barra = document.getElementById('barra-cobro');
    const cantidad = document.getElementById('cobro-cantidad');
    const total = document.getElementById('cobro-total');
    const medio = document.getElementById('medio-pago');
    const campoYape = document.getElementById('campo-yape');
    const inputYape = document.getElementById('yape-codigo');
    const marcarTodas = document.getElementById('marcar-todas');

    function actualizar() {
        const marcadas = casillas.filter(c => c.checked);
        const suma = marcadas.reduce((acc, c) => acc + parseFloat(c.dataset.monto || '0'), 0);

        if (cantidad) cantidad.textContent = String(marcadas.length);
        if (total) total.textContent = 'S/ ' + suma.toFixed(2);
        if (barra) barra.hidden = marcadas.length === 0;
    }

    casillas.forEach(c => c.addEventListener('change', actualizar));

    if (marcarTodas) {
        marcarTodas.addEventListener('change', function () {
            casillas.forEach(c => { c.checked = marcarTodas.checked; });
            actualizar();
        });
    }

    /*
     * El código de seguridad solo existe con Yape, y con Yape es obligatorio
     * (D-31). El `required` viaja con la visibilidad: exigirlo mientras el
     * campo está oculto bloquearía el cobro en efectivo sin explicar nada,
     * porque el navegador no puede enfocar un campo que no se ve.
     */
    function alternarYape() {
        const esYape = medio.value === 'yape';

        if (campoYape) campoYape.hidden = !esYape;

        if (inputYape) {
            inputYape.required = esYape;
            if (!esYape) inputYape.value = '';
        }
    }

    if (medio) {
        medio.addEventListener('change', alternarYape);
        alternarYape();
    }

    /* Anulación definitiva: pide motivo y confirma, avisando de la devolución. */
    const formAnular = document.getElementById('form-anular');
    const campoMotivo = document.getElementById('motivo-anulacion');
    const urlBase = formAnular ? formAnular.dataset.urlBase : '';

    document.querySelectorAll('.boton-anular').forEach(function (boton) {
        boton.addEventListener('click', function () {
            const pagada = boton.dataset.pagada === '1';
            let aviso = 'Anular definitivamente la inscripción de ' + boton.dataset.nombre + '.';

            if (pagada) {
                aviso += '\n\nTiene pago confirmado: S/ ' + boton.dataset.monto +
                         ' se sumará al fondo de devoluciones.';
            }

            aviso += '\n\nSi solo quieres cambiar la categoría, cancela y usa ' +
                     '«Corregir categoría»: así conserva su pago y su código.' +
                     '\n\nMotivo de la anulación:';

            const motivo = window.prompt(aviso, '');
            if (motivo === null || motivo.trim() === '') return;

            campoMotivo.value = motivo.trim();
            formAnular.action = urlBase + boton.dataset.id + '/anular';
            formAnular.submit();
        });
    });

    actualizar();
})();
