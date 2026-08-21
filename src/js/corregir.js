/**
 * Corregir una inscripción (D-50) — bloque de procedencia.
 *
 * Solo se carga para el administrador, que es el único que ve ese bloque.
 *
 * Hace dos cosas:
 *
 *   1. Enseña la institución o el apoderado según el tipo de participante. Los
 *      dos a la vez no tienen sentido: un estudiante de delegación hereda como
 *      apoderado al docente delegado de su colegio (D-28), y uno libre no tiene
 *      colegio del que heredarlo.
 *
 *   2. Mueve el atributo `required` con ellos. Es la razón por la que el HTML
 *      no lo trae puesto: un campo `required` escondido bloquea el envío del
 *      formulario sin decir dónde está el problema —el navegador no puede
 *      enfocar lo que no se ve— y la pantalla se queda muda al pulsar Guardar.
 *
 * El servidor valida lo mismo por su cuenta: esto es comodidad, no la regla.
 */
(function () {
    const tipo = document.getElementById('tipo-participante');
    const campoInstitucion = document.getElementById('campo-institucion');
    const bloqueApoderado = document.getElementById('bloque-apoderado');

    if (!tipo || !campoInstitucion || !bloqueApoderado) return;

    const institucion = document.getElementById('institucion-id');
    const delApoderado = ['ap-dni', 'ap-celular', 'ap-paterno', 'ap-materno', 'ap-nombres']
        .map((id) => document.getElementById(id))
        .filter(Boolean);

    function exigir(campo, obligatorio) {
        if (!campo) return;
        if (obligatorio) campo.setAttribute('required', 'required');
        else campo.removeAttribute('required');
    }

    function pintar() {
        const esLibre = tipo.value === 'libre';

        campoInstitucion.hidden = esLibre;
        bloqueApoderado.hidden = !esLibre;

        exigir(institucion, !esLibre);
        delApoderado.forEach((campo) => exigir(campo, esLibre));
    }

    tipo.addEventListener('change', pintar);
    pintar();

    // El buscador por documento es el mismo de la inscripción libre: mismo
    // archivo, mismos ids. Un apoderado ya registrado se reconoce en vez de
    // duplicarse, y `apoderados.dni` es UNIQUE global, así que crearlo en
    // limpio reventaría contra la base.
    if (typeof window.apoderadoReutilizable === 'function') {
        window.apoderadoReutilizable({
            dni: 'ap-dni',
            estado: 'ap-estado',
            aviso: 'ap-reutilizado',
            boton: 'ap-editar',
            campos: {
                celular: 'ap-celular',
                ap_paterno: 'ap-paterno',
                ap_materno: 'ap-materno',
                nombres: 'ap-nombres',
                correo: 'ap-correo'
            }
        });
    }
})();
