/*
 * Aviso de posible duplicado mientras se escribe el nombre.
 * Es solo una ayuda: no bloquea nada, porque dos colegios distintos pueden
 * llamarse igual en distritos distintos. La decisión la toma la secretaria.
 */
(function () {
    const campo = document.querySelector('input[name="nombre"]');
    const aviso = document.getElementById('aviso-duplicados');
    if (!campo || !aviso) return;

    const urlBuscar = campo.dataset.urlBuscar;
    const urlEditar = campo.dataset.urlEditar;

    let temporizador = null;

    campo.addEventListener('input', function () {
        clearTimeout(temporizador);
        const termino = campo.value.trim();

        if (termino.length < 3) {
            aviso.hidden = true;
            return;
        }

        temporizador = setTimeout(async function () {
            try {
                const url = urlBuscar + '?q=' + encodeURIComponent(termino);
                const respuesta = await fetch(url, { headers: { 'Accept': 'application/json' } });
                if (!respuesta.ok) return;

                const datos = await respuesta.json();
                const lista = datos.resultados || [];

                if (lista.length === 0) {
                    aviso.hidden = true;
                    return;
                }

                aviso.innerHTML =
                    '<strong>Ya existe' + (lista.length === 1 ? '' : 'n') + ' ' +
                    lista.length + ' institución' + (lista.length === 1 ? '' : 'es') +
                    ' con un nombre parecido:</strong><ul class="lista-errores">' +
                    lista.map(function (ie) {
                        const texto = ie.nombre + ' — ' + ie.distrito + ' (' + ie.tipo + ')';
                        return '<li><a href="' + urlEditar + ie.id + '/editar">' +
                               texto.replace(/[<>&]/g, '') + '</a></li>';
                    }).join('') +
                    '</ul>';
                aviso.hidden = false;
            } catch (e) {
                /* Si la búsqueda falla, el formulario sigue siendo usable. */
            }
        }, 350);
    });
})();

/*
 * Docente delegado: reconocer y reutilizar a la persona por su documento.
 *
 * Es el encargado de la delegación y, desde D-28, el apoderado de todos los
 * estudiantes que inscriba este colegio. Puede existir ya en el sistema —porque
 * encabezó la delegación el año pasado, o porque inscribió a su propio hijo como
 * estudiante libre—, y en ese caso hay que reutilizar su ficha, no duplicarla.
 */
(function () {
    if (typeof window.apoderadoReutilizable !== 'function') return;

    window.apoderadoReutilizable({
        dni: 'dd-dni',
        estado: 'dd-estado',
        aviso: 'dd-reutilizado',
        boton: 'dd-editar',
        campos: {
            celular: 'dd-celular',
            ap_paterno: 'dd-paterno',
            ap_materno: 'dd-materno',
            nombres: 'dd-nombres',
            correo: 'dd-correo'
        }
    });
})();
