{{-- @CU CU-10 @RF RF10 @CI CIReprogramarCita (buscarCita, mostrarHorarios, confirmarCambio) @CC CCReprogramarCita @CE CECita, CEMedico
     Pasos 2 a 5 del flujo: ReprogramarCitaController::reprogramar() muestra la cita, verifica que pueda reprogramarse y ofrece
     los horarios libres del mismo médico en la nueva fecha; actualizar() confirma. Si el horario ya fue ocupado, vuelve aquí. --}}
@extends('layouts.app')

@section('titulo', 'Reprogramar cita')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/gestion-citas.css')">
@endpush

@push('scripts')
    <script src="@recurso('js/modulos/gestion-citas.js')" defer></script>
@endpush

@section('contenido')
    <div class="encabezado-pagina">
        <h1>Reprogramar cita <small class="texto-suave">{{ $cita->codigo }}</small></h1>
        <a class="btn btn-secundario" href="{{ route('citas.index') }}">Volver a las citas</a>
    </div>

    <div class="tarjeta">
        <div class="tarjeta__encabezado"><x-icono nombre="documento" /><h2>Datos de la cita</h2></div>
        <div class="tarjeta__cuerpo">
            @include('gestioncitas::Compartido.comprobante-datos')

            @unless ($puede)
                <div class="aviso aviso-alerta" role="alert">
                    <x-icono nombre="alerta" />
                    <span>{{ $motivoNo }}</span>
                </div>
                <div class="acciones">
                    <a class="btn btn-primario" href="{{ route('citas.registrar', ['paciente' => $cita->paciente_id]) }}"><x-icono nombre="mas" /> Registrar una cita nueva para {{ $cita->paciente->nombres }}</a>
                </div>
            @endunless
        </div>
    </div>

    @if ($puede)
        <div class="buscador" style="margin-bottom:1.25rem">
            <h2>Nueva fecha con {{ $cita->medico->nombre_completo }}</h2>
            <form method="GET" action="{{ route('citas.reprogramar', $cita) }}" data-validar novalidate>
                <div class="buscador__campos">
                    <x-campo nombre="fecha" etiqueta="Nueva fecha" tipo="date" :valor="$fecha" required />
                    <div class="campo"><button type="submit" class="btn btn-primario"><x-icono nombre="buscar" /> Ver horarios libres</button></div>
                </div>
            </form>
        </div>

        @if ($resultado)
            <div class="tarjeta">
                <div class="tarjeta__encabezado">
                    <x-icono nombre="reloj" />
                    <h2>Horarios libres · {{ \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $fecha)->format('d/m/Y') }}</h2>
                </div>
                <div class="tarjeta__cuerpo">
                    @if ($resultado->hayLibres())
                        <form method="POST" action="{{ route('citas.actualizar', $cita) }}" data-validar novalidate>
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="fecha" value="{{ $fecha }}">

                            @include('gestioncitas::Compartido.horarios')

                            <div class="formulario" style="margin-top:1rem">
                                <x-areatexto nombre="motivo_cambio" etiqueta="Motivo del cambio" required maxlength="200" rows="2"
                                             ayuda="Queda registrado en la bitácora junto con el horario anterior." />
                            </div>

                            <div class="acciones">
                                <button type="submit" class="btn btn-primario"><x-icono nombre="check" /> Confirmar reprogramación</button>
                                <a class="btn btn-secundario" href="{{ route('citas.index') }}">Cancelar</a>
                            </div>
                        </form>
                    @else
                        <div class="aviso aviso-alerta" role="status"><x-icono nombre="alerta" /> <span>{{ $resultado->mensaje }}</span></div>
                    @endif
                </div>
            </div>
        @endif
    @endif
@endsection
