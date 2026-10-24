/* P3 Gestión de Pacientes · comportamiento propio del paquete (lo común está en vidasalud.js) */
(function () {
    'use strict';

    // CU-07: los datos del antecedente de otro hospital solo se muestran (y se exigen) si se marca la casilla.
    document.querySelectorAll('input[data-alterna]').forEach(function (casilla) {
        var panel = document.querySelector(casilla.getAttribute('data-alterna'));
        var obligatorios = panel.querySelectorAll('input[name="ant_hospital_origen"], input[name="ant_diagnostico"]');

        function aplicar() {
            panel.hidden = !casilla.checked;
            obligatorios.forEach(function (campo) { campo.required = casilla.checked; });
        }

        casilla.addEventListener('change', aplicar);
        aplicar();
    });
})();
