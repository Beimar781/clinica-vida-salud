{{-- @CU CU-10 @RF RF10 @CI CIReprogramarCita (mostrarComprobante) @CC CCReprogramarCita @CE CECita
     Paso 7 del flujo: ReprogramarCitaController::comprobante() muestra el comprobante actualizado de la cita reprogramada. --}}
@extends('layouts.app')

@section('titulo', 'Comprobante de la cita reprogramada')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/gestion-citas.css')">
@endpush

@push('scripts')
    <script src="@recurso('js/modulos/gestion-citas.js')" defer></script>
@endpush

@section('contenido')
    <div class="encabezado-pagina">
        <h1>Comprobante de la cita reprogramada</h1>
    </div>

    <div class="tarjeta">
        <div class="tarjeta__encabezado"><x-icono nombre="documento" /><h2>Cita reprogramada</h2></div>
        <div class="tarjeta__cuerpo">
            @if ($reprogramacion)
                <div class="aviso aviso-info" role="status">
                    <x-icono nombre="info" />
                    <span>
                        Horario anterior: <strong>{{ $reprogramacion['anterior']['fecha'] }} · {{ $reprogramacion['anterior']['horario'] }}</strong>
                        (ahora libre). Motivo del cambio: {{ $reprogramacion['motivo'] }}
                    </span>
                </div>
            @endif

            @include('gestioncitas::Compartido.comprobante-datos')

            <div class="acciones sin-impresion">
                <button type="button" class="btn btn-primario" data-imprimir><x-icono nombre="documento" /> Imprimir comprobante</button>
                <a class="btn btn-secundario" href="{{ route('citas.index') }}">Volver a las citas</a>
                <a class="btn btn-secundario" href="{{ route('panel') }}">Volver al panel</a>
            </div>
        </div>
    </div>
@endsection
