/* P2 Gestión Médica · comportamiento propio del paquete (lo común está en vidasalud.js) */
(function () {
    'use strict';

    // CU-05: el formulario de médicos exige al menos una especialidad marcada antes de enviarse.
    document.querySelectorAll('form[data-requiere-especialidad]').forEach(function (form) {
        var aviso = form.querySelector('#error-especialidades');
        var casillas = form.querySelectorAll('input[name="especialidades[]"]');
        var grupo = form.querySelector('.grupo-opciones');

        function hayUna() {
            return Array.prototype.some.call(casillas, function (c) { return c.checked; });
        }

        form.addEventListener('submit', function (evento) {
            if (hayUna()) { return; }
            evento.preventDefault();
            aviso.textContent = 'Seleccione al menos una especialidad.';
            grupo.classList.add('campo--error');
            casillas[0].focus();
        });

        casillas.forEach(function (c) {
            c.addEventListener('change', function () {
                if (hayUna()) {
                    aviso.textContent = '';
                    grupo.classList.remove('campo--error');
                }
            });
        });
    });

    // CU-05: los datos de la cuenta de acceso solo se piden (y se exigen) si se marca «Crear su cuenta».
    document.querySelectorAll('input[data-alterna]').forEach(function (casilla) {
        var panel = document.querySelector(casilla.getAttribute('data-alterna'));
        var campos = panel.querySelectorAll('input[name="nombre_usuario"], input[name="password"]');
        var correo = casilla.form.querySelector('input[name="correo"]');

        function aplicar() {
            panel.hidden = !casilla.checked;
            campos.forEach(function (campo) { campo.required = casilla.checked; });
            if (correo) { correo.required = casilla.checked; }
        }

        casilla.addEventListener('change', aplicar);
        aplicar();
    });
})();
