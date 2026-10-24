/* P1 Seguridad y Usuarios · comportamiento propio del paquete (lo común está en vidasalud.js) */
(function () {
    'use strict';

    // CU-01 (recuperar contraseña): la confirmación debe ser igual a la contraseña nueva.
    document.querySelectorAll('form[data-confirmar-clave]').forEach(function (form) {
        var clave = form.querySelector('#campo-password');
        var confirmacion = form.querySelector('#campo-password_confirmation');

        function revisar() {
            confirmacion.setCustomValidity(confirmacion.value !== clave.value ? 'La confirmación no coincide con la contraseña.' : '');
        }

        clave.addEventListener('input', revisar);
        confirmacion.addEventListener('input', revisar);
        // Se revisa antes de que vidasalud.js valide y muestre los errores junto a cada campo.
        form.addEventListener('submit', revisar, true);
    });

    // CU-03: el formulario de usuarios exige al menos un rol marcado antes de enviarse.
    document.querySelectorAll('form[data-requiere-rol]').forEach(function (form) {
        var aviso = form.querySelector('#error-roles');
        var casillas = form.querySelectorAll('input[name="roles[]"]');
        var grupo = form.querySelector('.grupo-roles');

        function hayRol() {
            return Array.prototype.some.call(casillas, function (c) { return c.checked; });
        }

        form.addEventListener('submit', function (evento) {
            if (hayRol()) { return; }
            evento.preventDefault();
            aviso.textContent = 'Seleccione al menos un rol.';
            grupo.classList.add('campo--error');
            casillas[0].focus();
        });

        casillas.forEach(function (c) {
            c.addEventListener('change', function () {
                if (hayRol()) {
                    aviso.textContent = '';
                    grupo.classList.remove('campo--error');
                }
            });
        });
    });
})();
