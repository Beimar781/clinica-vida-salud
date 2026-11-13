{{-- @CU CU-22 (extendido) @RF RF22 @CI CIRecordatorio — botón «Enviar recordatorio» de una cita (solo Recepcionista y Administrador, y solo si la cita es
     Programada o Confirmada y todavía no empezó). Variable: $cita. Si ya se envió antes, pide confirmar el reenvío. --}}
@if (auth()->user()?->tieneRol(\App\Models\Rol::RECEPCIONISTA) && $cita->recordable)
    <form method="POST" action="{{ route('citas.recordatorio', $cita) }}"
          @if ($cita->recordatorio_enviado) onsubmit="return confirm('A esta cita ya se le envió el recordatorio antes. ¿Enviarlo de nuevo?')" @endif>
        @csrf
        @if ($cita->recordatorio_enviado)<input type="hidden" name="reenviar" value="1">@endif
        <button type="submit" class="btn btn-secundario btn-sm" title="Envía ahora por correo el recordatorio de esta cita a su paciente">
            <x-icono nombre="correo" /> {{ $cita->recordatorio_enviado ? 'Reenviar recordatorio' : 'Enviar recordatorio' }}
        </button>
    </form>
@endif
