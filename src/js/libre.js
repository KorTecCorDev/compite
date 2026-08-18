/**
 * Formulario de inscripción libre (individual).
 *
 * Al escribir el documento del apoderado se consulta si ya existe, para
 * reutilizar el registro en lugar de duplicarlo. El comportamiento vive en
 * `apoderado-reutilizable.js`, compartido con la ficha de institución educativa:
 * son la misma persona en la misma tabla.
 */
(function () {
    if (typeof window.apoderadoReutilizable !== 'function') return;

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
})();
