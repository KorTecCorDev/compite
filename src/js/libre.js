/**
 * Formulario de inscripción libre (individual).
 *
 * Al escribir el DNI del apoderado se consulta si ya existe para reutilizar el
 * registro en lugar de duplicarlo. La URL del buscador llega en un data-*.
 */
(function () {
    const campoDni = document.getElementById('ap-dni');
    const estado = document.getElementById('ap-estado');

    if (!campoDni || !estado) return;

    const urlBuscar = campoDni.dataset.urlBuscar;
    const campos = {
        ap_paterno: document.getElementById('ap-paterno'),
        ap_materno: document.getElementById('ap-materno'),
        nombres: document.getElementById('ap-nombres'),
        celular: document.getElementById('ap-celular')
    };

    let temporizador = null;

    campoDni.addEventListener('input', function () {
        clearTimeout(temporizador);
        const dni = campoDni.value.trim();

        if (dni.length < 8) { estado.textContent = ''; return; }

        temporizador = setTimeout(async function () {
            try {
                const url = urlBuscar + '?dni=' + encodeURIComponent(dni);
                const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
                if (!r.ok) { estado.textContent = ''; return; }

                const datos = await r.json();

                if (!datos.encontrado) {
                    estado.textContent = 'Apoderado nuevo: completa sus datos.';
                    return;
                }

                /* Se autocompleta para reutilizar el registro existente. */
                Object.keys(campos).forEach(function (clave) {
                    if (campos[clave] && datos.apoderado[clave]) {
                        campos[clave].value = datos.apoderado[clave];
                    }
                });

                estado.textContent = 'Ya registrado: se reutilizará el mismo apoderado.';
            } catch (e) { estado.textContent = ''; }
        }, 400);
    });
})();
