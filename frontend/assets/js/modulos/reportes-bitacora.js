/* P6 Reportes y Bitácora · comportamiento propio del paquete (lo común está en vidasalud.js) */
(function () {
    'use strict';

    // CU-18: el formulario muestra solo los filtros que sirven al tipo de reporte elegido.
    var form = document.getElementById('form-reporte');
    if (form) {
        var unDia = JSON.parse(form.getAttribute('data-un-dia') || '[]');
        var tipo = form.querySelector('#campo-tipo');
        var desde = form.querySelector('#campo-desde');
        var etiquetaDesde = form.querySelector('label[for="campo-desde"]');
        var medico = form.querySelector('#campo-medico');
        var campoHasta = form.querySelector('[data-campo-hasta]');
        var campoMedico = form.querySelector('[data-campo-medico]');
        var campoEspecialidad = form.querySelector('[data-campo-especialidad]');
        var campoEstado = form.querySelector('[data-campo-estado]');

        var mostrar = function (elemento, visible) {
            elemento.hidden = !visible;
            Array.prototype.forEach.call(elemento.querySelectorAll('input, select'), function (campo) { campo.disabled = !visible; });
        };

        var ajustar = function () {
            var t = tipo.value;
            var esUnDia = unDia.indexOf(t) !== -1;
            var esPacientes = t === 'pacientes';
            etiquetaDesde.textContent = esUnDia ? 'Fecha' : (t === 'pacientes' ? 'Registrados desde' : 'Desde');
            mostrar(campoHasta, !esUnDia);
            mostrar(campoMedico, !esPacientes);
            mostrar(campoEspecialidad, !esPacientes && t !== 'agenda_medico');
            mostrar(campoEstado, !esPacientes && t !== 'agenda_medico');
            // La agenda diaria exige elegir el médico.
            if (medico) {
                medico.required = t === 'agenda_medico';
                var vacio = medico.querySelector('option[value=""]');
                if (vacio) { vacio.textContent = t === 'agenda_medico' ? '— Seleccione un médico —' : 'Todos'; }
            }
        };

        tipo.addEventListener('change', ajustar);
        ajustar();

        // Antes de enviar se comprueba el rango (fin >= inicio), igual que en el servidor.
        var hasta = form.querySelector('#campo-hasta');
        var revisarRango = function () {
            if (!hasta || hasta.disabled) { return; }
            hasta.setCustomValidity(hasta.value && desde.value && hasta.value < desde.value ? 'La fecha final no puede ser anterior a la fecha inicial.' : '');
        };
        form.addEventListener('submit', revisarRango, true);
        hasta.addEventListener('input', revisarRango);
        desde.addEventListener('input', revisarRango);
    }

    // CU-18: imprimir el reporte (el CSS @media print oculta todo lo demás).
    document.querySelectorAll('[data-imprimir]').forEach(function (boton) {
        boton.addEventListener('click', function () { window.print(); });
    });
})();
