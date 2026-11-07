/* P5 Atención Clínica · comportamiento propio del paquete (lo común está en vidasalud.js) */
(function () {
    'use strict';

    // Contador de caracteres de los campos de la consulta (diagnóstico, tratamiento y observaciones).
    document.querySelectorAll('form[data-validar] textarea[maxlength]').forEach(function (campo) {
        var contador = document.createElement('span');
        contador.className = 'campo__ayuda';
        contador.setAttribute('aria-live', 'polite');
        campo.insertAdjacentElement('afterend', contador);

        function actualizar() {
            contador.textContent = campo.value.length + ' de ' + campo.maxLength + ' caracteres';
        }

        campo.addEventListener('input', actualizar);
        actualizar();
    });
}());
