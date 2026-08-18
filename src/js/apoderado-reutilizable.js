/**
 * Reconocer y reutilizar a un apoderado por su documento.
 *
 * Lo usan los dos formularios que registran un adulto responsable (D-28):
 * la inscripción libre —apoderado del estudiante— y la ficha de institución
 * educativa —docente delegado, que es el encargado de la delegación—. Es la
 * misma persona en la misma tabla, así que tiene que ser el mismo comportamiento.
 *
 * Vive en un archivo aparte y se expone en `window` porque el pipeline de
 * assets copia cada `src/js/*.js` por separado, sin empaquetador: no hay forma
 * de importarlo, y duplicarlo en los dos formularios garantizaba que algún día
 * divergieran.
 *
 * Al reconocer a alguien, sus datos se rellenan y quedan EN SOLO LECTURA. No es
 * cosmética: el formulario actualiza la ficha del apoderado al guardar, y esa
 * ficha la comparten todos sus participantes. Sin el bloqueo, un tipeo mientras
 * se inscribe al tercer hijo reescribía en silencio el apoderado de los otros
 * dos —y en el caso del docente delegado, el de su delegación entera—.
 */
window.apoderadoReutilizable = function (config) {
    const campoDni = document.getElementById(config.dni);
    const estado = document.getElementById(config.estado);
    const aviso = document.getElementById(config.aviso);
    const boton = document.getElementById(config.boton);

    if (!campoDni) return;

    const urlBuscar = campoDni.dataset.urlBuscar;
    const ayudaInicial = estado ? estado.textContent : '';

    const campos = {};
    Object.keys(config.campos).forEach(function (clave) {
        const el = document.getElementById(config.campos[clave]);
        if (el) campos[clave] = el;
    });

    let temporizador = null;
    let reutilizando = false;

    function mensaje(texto) {
        if (estado) estado.textContent = texto;
    }

    function bloquear(apoderado) {
        Object.keys(campos).forEach(function (clave) {
            if (apoderado[clave]) campos[clave].value = apoderado[clave];
            campos[clave].readOnly = true;
        });

        reutilizando = true;
        mensaje(ayudaInicial);

        if (aviso) {
            aviso.querySelector('.reutilizado__texto').textContent =
                'Ya registrado: se reutilizará esta persona. Sus datos están bloqueados.';
            aviso.hidden = false;
        }
    }

    /**
     * @param {boolean} vaciar  Si además hay que borrar lo que se autorrellenó.
     *   Se vacía cuando el documento deja de reconocer a nadie: conservar los
     *   datos de la persona anterior bajo un documento distinto crearía un
     *   registro nuevo con el nombre de otro.
     */
    function desbloquear(vaciar) {
        Object.keys(campos).forEach(function (clave) {
            campos[clave].readOnly = false;
            if (vaciar) campos[clave].value = '';
        });

        reutilizando = false;
        if (aviso) aviso.hidden = true;
    }

    if (boton) {
        boton.addEventListener('click', function () {
            desbloquear(false);
            mensaje('Editando: al guardar se actualizarán los datos de esta persona.');

            const primero = Object.keys(campos)[0];
            if (primero) campos[primero].focus();
        });
    }

    campoDni.addEventListener('input', function () {
        clearTimeout(temporizador);
        const documento = campoDni.value.trim();

        if (documento.length < 8) {
            if (reutilizando) desbloquear(true);
            mensaje(ayudaInicial);
            return;
        }

        temporizador = setTimeout(async function () {
            try {
                const url = urlBuscar + '?dni=' + encodeURIComponent(documento);
                const r = await fetch(url, { headers: { 'Accept': 'application/json' } });

                if (!r.ok) {
                    if (reutilizando) desbloquear(true);
                    mensaje(ayudaInicial);
                    return;
                }

                const datos = await r.json();

                if (!datos.encontrado) {
                    if (reutilizando) desbloquear(true);
                    mensaje('No está registrado: completa sus datos.');
                    return;
                }

                bloquear(datos.apoderado);
            } catch (e) {
                if (reutilizando) desbloquear(true);
                mensaje(ayudaInicial);
            }
        }, 400);
    });
};
