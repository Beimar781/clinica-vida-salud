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

    // CU-06: valida el bloque en el navegador (la validación definitiva se repite en el servidor) y muestra
    // cuántas citas caben. La hora final debe ser posterior a la inicial y la duración debe dividir el bloque.
    document.querySelectorAll('form[data-horario]').forEach(function (form) {
        var inicio = form.querySelector('#campo-hora_inicio');
        var fin = form.querySelector('#campo-hora_fin');
        var duracion = form.querySelector('#campo-duracion_min');
        var resumen = form.querySelector('#resumen-bloque');

        function minutos(valor) {
            var partes = valor.split(':');
            return parseInt(partes[0], 10) * 60 + parseInt(partes[1], 10);
        }

        function poner(campo, mensaje) {
            var contenedor = campo.closest('.campo');
            var previo = contenedor.querySelector('.campo__error[data-horario]');
            if (previo) { previo.remove(); }
            contenedor.classList.toggle('campo--error', mensaje !== '');
            if (mensaje !== '') {
                var aviso = document.createElement('span');
                aviso.className = 'campo__error';
                aviso.setAttribute('data-horario', '');
                aviso.setAttribute('role', 'alert');
                aviso.textContent = mensaje;
                contenedor.appendChild(aviso);
            }
        }

        // Devuelve true si el bloque es válido; muestra el aviso junto al campo que corresponde.
        function revisar() {
            [fin, duracion].forEach(function (c) { poner(c, ''); });
            resumen.hidden = true;
            if (!inicio.value || !fin.value) { return true; }

            var total = minutos(fin.value) - minutos(inicio.value);
            if (total <= 0) {
                poner(fin, 'La hora final debe ser posterior a la hora inicial.');
                return false;
            }
            var minimo = parseInt(duracion.value, 10);
            if (!minimo || minimo < 1) { return true; }

            var sobrantes = total % minimo;
            if (sobrantes !== 0) {
                poner(duracion, 'La duración no divide exactamente el bloque: sobran ' + sobrantes + ' minutos. Corrija la duración o las horas.');
                return false;
            }
            var citas = total / minimo;
            resumen.textContent = 'El bloque tiene ' + citas + (citas === 1 ? ' cita' : ' citas') + ' de ' + minimo + ' minutos.';
            resumen.hidden = false;
            return true;
        }

        // En el formulario (no en cada campo) para correr después de la limpieza de errores de vidasalud.js.
        form.addEventListener('input', revisar);
        form.addEventListener('submit', function (evento) {
            if (!revisar()) { evento.preventDefault(); }
        });
        revisar();
    });
})();
