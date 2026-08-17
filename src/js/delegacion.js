/**
 * Formulario de inscripción por delegación.
 *
 * Los datos que antes venían incrustados por PHP (los montos de tarifa y la URL
 * del verificador de documentos) llegan ahora en atributos data-* del marcado,
 * que es lo que permite que este archivo sea estático y se pueda cachear.
 */
(function () {
    const selector = document.getElementById('selector-ie');
    const caja = document.getElementById('resumen-tarifa');
    const montoTexto = document.getElementById('tarifa-monto');
    const cuerpo = document.getElementById('filas-participantes');
    const contador = document.getElementById('contador-filas');

    if (!selector || !caja || !cuerpo) return;

    const montos = JSON.parse(caja.dataset.montos || '{}');

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
    const urlVerificar = cuerpo.dataset.urlVerificar;
    let temporizador = null;

    cuerpo.addEventListener('input', function (evento) {
        if (!evento.target.classList.contains('entrada-documento')) return;

        clearTimeout(temporizador);
        const doc = evento.target.value.trim();
        if (doc.length < 8) return;

        temporizador = setTimeout(async function () {
            try {
                const url = urlVerificar + '?dni=' + encodeURIComponent(doc);
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
