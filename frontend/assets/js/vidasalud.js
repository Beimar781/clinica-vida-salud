/* Clínica Vida Salud · validación en el cliente (la validación definitiva se repite en el servidor) */
(function () {
    'use strict';

    function mostrarError(campo, mensaje) {
        var contenedor = campo.closest('.campo');
        if (!contenedor) { return; }
        contenedor.classList.add('campo--error');
        var aviso = contenedor.querySelector('.campo__error[data-cliente]');
        if (!aviso) {
            aviso = document.createElement('span');
            aviso.className = 'campo__error';
            aviso.setAttribute('data-cliente', '');
            aviso.setAttribute('role', 'alert');
            contenedor.appendChild(aviso);
        }
        aviso.textContent = mensaje;
    }

    function limpiarError(campo) {
        var contenedor = campo.closest('.campo');
        if (!contenedor) { return; }
        contenedor.classList.remove('campo--error');
        // Quita el mensaje del cliente y también el que llegó del servidor al corregir el campo.
        contenedor.querySelectorAll('.campo__error:not([data-fijo])').forEach(function (aviso) { aviso.remove(); });
    }

    function mensajeDe(campo) {
        if (campo.validity.valueMissing) { return 'Este campo es obligatorio.'; }
        if (campo.validity.typeMismatch) { return 'El formato no es válido.'; }
        if (campo.validity.tooShort) { return 'Debe tener al menos ' + campo.minLength + ' caracteres.'; }
        if (campo.validity.patternMismatch) { return campo.getAttribute('data-mensaje') || 'El formato no es válido.'; }
        return campo.validationMessage;
    }

    // Formularios con data-validar: muestra los errores junto a cada campo antes de enviar.
    document.querySelectorAll('form[data-validar]').forEach(function (form) {
        form.noValidate = true;
        form.addEventListener('submit', function (evento) {
            var primero = null;
            form.querySelectorAll('input, select, textarea').forEach(function (campo) {
                limpiarError(campo);
                if (!campo.checkValidity()) {
                    mostrarError(campo, mensajeDe(campo));
                    primero = primero || campo;
                }
            });
            if (primero) {
                evento.preventDefault();
                primero.focus();
            }
        });
        form.addEventListener('input', function (evento) { limpiarError(evento.target); });
    });

    // Botones o formularios con data-confirmar: piden confirmación antes de continuar.
    document.querySelectorAll('[data-confirmar]').forEach(function (elemento) {
        var evento = elemento.tagName === 'FORM' ? 'submit' : 'click';
        elemento.addEventListener(evento, function (e) {
            if (!window.confirm(elemento.getAttribute('data-confirmar'))) { e.preventDefault(); }
        });
    });
})();
