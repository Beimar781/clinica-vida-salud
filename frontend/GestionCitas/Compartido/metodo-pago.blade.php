{{-- @CU CU-09, CU-11 @RF RF09, RF11 @CI CIRegistrarCita, CIReservarCita (confirmarCita, confirmarReserva) @CE CECita, CEEspecialidad
     Paso 6 del flujo: método de pago (efectivo en la clínica o tarjeta mediante Stripe) y monto según la especialidad o el servicio.
     Variables: $especialidad (con su precio) y $tarjetaDisponible (hay STRIPE_SECRET). El sistema nunca pide ni guarda datos de
     tarjeta: la persona los escribe en la página de Stripe. --}}
@php
    $conCosto = (float) $especialidad->precio > 0;
    $tarjetaActiva = $tarjetaDisponible && $conCosto;
    $elegido = old('metodo_pago', 'EFECTIVO');
    if ($elegido === 'TARJETA' && ! $tarjetaActiva) { $elegido = 'EFECTIVO'; }
@endphp
<fieldset @class(['campo', 'grupo-pago', 'campo--error' => $errors->has('metodo_pago')]) data-grupo-pago>
    <legend class="etiqueta">Método de pago</legend>
    <p class="monto-pagar">Monto a pagar: <strong>{{ \App\Support\Dinero::formato($especialidad->precio) }}</strong> <small class="texto-suave">({{ $especialidad->nombre }})</small></p>
    <div class="pago-opciones">
        <label class="pago-opcion" for="pago-efectivo">
            <input type="radio" id="pago-efectivo" name="metodo_pago" value="EFECTIVO" @checked($elegido === 'EFECTIVO') required>
            <span><strong>Efectivo en la clínica</strong><small>La cita queda con el pago <em>Pendiente</em> hasta el cobro en la clínica.</small></span>
        </label>
        <label @class(['pago-opcion', 'pago-opcion--deshabilitada' => ! $tarjetaActiva]) for="pago-tarjeta">
            <input type="radio" id="pago-tarjeta" name="metodo_pago" value="TARJETA" @checked($elegido === 'TARJETA') @disabled(! $tarjetaActiva)>
            <span><strong>Tarjeta (Stripe)</strong><small>Pagará en la página segura de Stripe. El sistema no guarda datos de tarjeta.</small></span>
        </label>
    </div>
    @if (! $tarjetaDisponible)
        <span class="campo__ayuda">El pago con tarjeta no está disponible por ahora; elija pagar en efectivo en la clínica.</span>
    @elseif (! $conCosto)
        <span class="campo__ayuda">Esta especialidad o servicio no tiene costo: no se puede cobrar con tarjeta.</span>
    @endif
    @error('metodo_pago')<span class="campo__error" role="alert">{{ $message }}</span>@enderror
</fieldset>
