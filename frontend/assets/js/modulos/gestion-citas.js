/* P4 Gestión de Citas · comportamiento propio del paquete (lo común está en vidasalud.js) */
(function () {
    'use strict';

    // Método de pago (CU-09 y CU-11): con tarjeta el botón dice que seguirá la página de pago de Stripe.
    document.querySelectorAll('[data-grupo-pago]').forEach(function (grupo) {
        var form = grupo.closest('form');
        var boton = form && form.querySelector('button[type="submit"]');
        if (!boton) { return; }

        var textoOriginal = boton.innerHTML;
        var textoTarjeta = '<span>Continuar al pago con tarjeta</span>';

        function actualizar() {
            var elegido = grupo.querySelector('input[name="metodo_pago"]:checked');
            boton.innerHTML = elegido && elegido.value === 'TARJETA' ? textoTarjeta : textoOriginal;
        }

        grupo.addEventListener('change', actualizar);
        actualizar();
    });
}());
