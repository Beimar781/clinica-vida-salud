{{-- @CU CU-08 @RF RF08 @CI CIDisponibilidad (elegirMedico, elegirFecha, mostrarHorarios, mostrarMensaje) @CC CCDisponibilidad @CE CEMedico, CECita
     Pasos 1 y 6 del flujo: DisponibilidadController::index() recibe el médico y la fecha y muestra los bloques libres o el mensaje. --}}
@extends('layouts.app')

@section('titulo', 'Consultar disponibilidad')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/gestion-citas.css')">
@endpush

@section('contenido')
    <div class="encabezado-pagina">
        <h1>Consultar disponibilidad de horarios</h1>
    </div>

    <div class="buscador">
        <h2>Elija el médico y la fecha</h2>
        @if ($medicos->isEmpty())
            <p class="vacio">No hay médicos activos con horarios de atención.</p>
        @else
            <form method="GET" action="{{ route('disponibilidad.index') }}" data-validar novalidate>
                <div class="buscador__campos">
                    <x-seleccion nombre="medico" etiqueta="Médico" vacio="— Seleccione un médico —" required
                                 :opciones="$medicos->mapWithKeys(fn ($m) => [$m->id => $m->nombre_completo.' ('.$m->especialidades->pluck('nombre')->implode(', ').')'])->all()"
                                 :valor="$medico?->id" />
                    <x-campo nombre="fecha" etiqueta="Fecha" tipo="date" :valor="$fecha" required />
                    <div class="campo"><button type="submit" class="btn btn-primario"><x-icono nombre="buscar" /> Ver horarios libres</button></div>
                </div>
            </form>
        @endif
    </div>

    @if ($resultado)
        <div class="tarjeta" style="margin-top:1.25rem">
            <div class="tarjeta__encabezado">
                <x-icono nombre="reloj" />
                <h2>{{ $medico->nombre_completo }} · {{ \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $fecha)->format('d/m/Y') }}</h2>
            </div>
            <div class="tarjeta__cuerpo">
                @if ($resultado->hayLibres())
                    <p>Horarios libres ({{ count($resultado->libres) }}):</p>
                    <div class="bloques">
                        @foreach ($resultado->libres as $bloque)
                            <span class="bloque bloque--fijo"><span>{{ $bloque['inicio'] }} – {{ $bloque['fin'] }}</span></span>
                        @endforeach
                    </div>
                    <p class="texto-suave" style="margin-top:1rem">Para agendar una cita en uno de estos horarios use la opción
                        {{ auth()->user()->tieneRol('Recepcionista') ? '«Registrar cita»' : '«Reservar cita»' }} del panel.</p>
                @else
                    <div class="aviso aviso-alerta" role="status"><x-icono nombre="alerta" /> <span>{{ $resultado->mensaje }}</span></div>
                @endif
            </div>
        </div>
    @endif
@endsection
