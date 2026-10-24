{{-- @CU CU-09, CU-10, CU-11 @RF RF09, RF10, RF11 @CI CIRegistrarCita, CIReprogramarCita, CIReservarCita (mostrarHorarios) @CC CCDisponibilidad (calcularLibres)
     Selector de horarios libres que comparten los tres CU. Variables: $resultado (Disponibilidad) y, opcional, $nombre del campo (hora_inicio). --}}
@php $nombre = $nombre ?? 'hora_inicio'; @endphp

<fieldset @class(['grupo-horarios', 'campo', 'campo--error' => $errors->has($nombre)])>
    <legend class="etiqueta">Horarios libres ({{ count($resultado->libres) }})</legend>
    <div class="bloques">
        @foreach ($resultado->libres as $indice => $bloque)
            <label class="bloque" for="hora-{{ str_replace(':', '', $bloque['inicio']) }}">
                <input type="radio" id="hora-{{ str_replace(':', '', $bloque['inicio']) }}" name="{{ $nombre }}" value="{{ $bloque['inicio'] }}"
                       @checked(old($nombre) === $bloque['inicio']) @required($indice === 0)>
                <span>{{ $bloque['inicio'] }} – {{ $bloque['fin'] }}</span>
            </label>
        @endforeach
    </div>
    @error($nombre)<span class="campo__error" role="alert">{{ $message }}</span>@enderror
</fieldset>
