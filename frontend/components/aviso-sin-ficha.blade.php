{{-- Aviso para una cuenta con rol Paciente que todavía no tiene ficha de paciente (se muestra en el panel y en las pantallas de citas).
     No muestra nada si la persona no es Paciente o ya tiene su ficha. --}}
@auth
    @if (auth()->user()->esPacienteSinFicha())
        <div class="aviso aviso-alerta" role="alert"><x-icono nombre="alerta" /> <span>{{ \App\Models\Paciente::MENSAJE_SIN_FICHA }}</span></div>
    @endif
@endauth
