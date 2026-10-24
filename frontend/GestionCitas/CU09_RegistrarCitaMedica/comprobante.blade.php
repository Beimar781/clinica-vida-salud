{{-- @CU CU-09 @RF RF09 @CI CIRegistrarCita (mostrarComprobante) @CC CCRegistrarCita @CE CECita, CEPaciente, CEMedico
     Paso 7 del flujo: RegistrarCitaController::comprobante() muestra el paciente, la especialidad, el médico, la fecha, el horario y el motivo. --}}
@extends('layouts.app')

@section('titulo', 'Comprobante de la cita')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/gestion-citas.css')">
@endpush

@push('scripts')
    <script src="@recurso('js/modulos/gestion-citas.js')" defer></script>
@endpush

@section('contenido')
    <div class="encabezado-pagina">
        <h1>Comprobante de la cita</h1>
    </div>

    <div class="tarjeta">
        <div class="tarjeta__encabezado"><x-icono nombre="documento" /><h2>Cita registrada</h2></div>
        <div class="tarjeta__cuerpo">
            @include('gestioncitas::Compartido.comprobante-datos')

            <div class="acciones sin-impresion">
                <button type="button" class="btn btn-primario" data-imprimir><x-icono nombre="documento" /> Imprimir comprobante</button>
                <a class="btn btn-secundario" href="{{ route('citas.registrar') }}"><x-icono nombre="mas" /> Registrar otra cita</a>
                <a class="btn btn-secundario" href="{{ route('panel') }}">Volver al panel</a>
            </div>
        </div>
    </div>
@endsection
