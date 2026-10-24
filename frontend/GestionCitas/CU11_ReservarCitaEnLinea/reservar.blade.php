{{-- @CU CU-11 @RF RF11 @CI CIReservarCita (mostrarHorarios, confirmarReserva) @CC CCReservarCita @CE CECita, CEMedico
     Pasos 3 a 5 del flujo: ReservarCitaController::reservar() muestra al médico elegido, la fecha y los horarios libres (CU-08);
     guardar() confirma. Si el horario ya fue ocupado, vuelve aquí con el aviso y los horarios actualizados. --}}
@extends('layouts.app')

@section('titulo', 'Reservar cita')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/gestion-citas.css')">
@endpush

@push('scripts')
    <script src="@recurso('js/modulos/gestion-citas.js')" defer></script>
@endpush

@section('contenido')
    <div class="encabezado-pagina">
        <h1>Reservar cita en línea</h1>
        <a class="btn btn-secundario" href="{{ route('reservas.crear', ['especialidad' => $especialidad->id]) }}">Cambiar la búsqueda</a>
    </div>

    @if ($impedimento)
        <div class="aviso aviso-alerta" role="alert"><x-icono nombre="alerta" /> <span>{{ $impedimento }}</span></div>
    @endif

    <div class="tarjeta">
        <div class="tarjeta__encabezado"><x-icono nombre="medico" /><h2>{{ $medico->nombre_completo }} · {{ $especialidad->nombre }}</h2></div>
        <div class="tarjeta__cuerpo">
            <p class="sin-margen texto-suave"><x-icono nombre="ubicacion" /> {{ $medico->ubicacion ?? 'Sin ubicación indicada' }}</p>
        </div>
    </div>

    <div class="buscador" style="margin-bottom:1.25rem">
        <h2>Elija la fecha</h2>
        <form method="GET" action="{{ route('reservas.reservar') }}" data-validar novalidate>
            <input type="hidden" name="especialidad" value="{{ $especialidad->id }}">
            <input type="hidden" name="medico" value="{{ $medico->id }}">
            <div class="buscador__campos">
                <x-campo nombre="fecha" etiqueta="Fecha" tipo="date" :valor="$fecha" required />
                <div class="campo"><button type="submit" class="btn btn-primario"><x-icono nombre="buscar" /> Ver horarios libres</button></div>
            </div>
        </form>
    </div>

    @if ($resultado)
        <div class="tarjeta">
            <div class="tarjeta__encabezado">
                <x-icono nombre="reloj" />
                <h2>Horarios · {{ \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $fecha)->format('d/m/Y') }}</h2>
            </div>
            <div class="tarjeta__cuerpo">
                @if ($resultado->hayLibres())
                    <form method="POST" action="{{ route('reservas.guardar') }}" data-validar novalidate>
                        @csrf
                        <input type="hidden" name="especialidad_id" value="{{ $especialidad->id }}">
                        <input type="hidden" name="medico_id" value="{{ $medico->id }}">
                        <input type="hidden" name="fecha" value="{{ $fecha }}">

                        @include('gestioncitas::Compartido.horarios')

                        <div class="formulario" style="margin-top:1rem">
                            <x-seleccion nombre="modalidad" etiqueta="Modalidad" :opciones="['PRESENCIAL' => 'Presencial', 'VIRTUAL' => 'Virtual']" valor="PRESENCIAL" required />
                            <x-areatexto nombre="motivo" etiqueta="Motivo de la consulta (opcional)" maxlength="200" rows="2" />
                        </div>

                        <div class="acciones">
                            <button type="submit" class="btn btn-primario"><x-icono nombre="check" /> Confirmar reserva</button>
                        </div>
                    </form>
                @else
                    <div class="aviso aviso-alerta" role="status"><x-icono nombre="alerta" /> <span>{{ $resultado->mensaje }}</span></div>
                @endif
            </div>
        </div>
    @endif
@endsection
