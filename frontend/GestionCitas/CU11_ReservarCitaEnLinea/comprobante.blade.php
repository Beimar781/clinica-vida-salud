{{-- @CU CU-11 @RF RF11 @CI CIReservarCita (mostrarComprobante) @CC CCReservarCita @CE CECita
     Paso 6 del flujo: ReservarCitaController::comprobante() muestra el comprobante de la reserva a nombre del paciente autenticado. --}}
@extends('layouts.app')

@section('titulo', 'Comprobante de la reserva')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/gestion-citas.css')">
@endpush

@push('scripts')
    <script src="@recurso('js/modulos/gestion-citas.js')" defer></script>
@endpush

@section('contenido')
    <div class="encabezado-pagina">
        <h1>Comprobante de la reserva</h1>
    </div>

    <div class="tarjeta">
        <div class="tarjeta__encabezado"><x-icono nombre="documento" /><h2>Cita reservada</h2></div>
        <div class="tarjeta__cuerpo">
            @include('gestioncitas::Compartido.comprobante-datos')

            <p class="texto-suave">
                @if ($cita->paciente->recibe_recordatorios)
                    Su preferencia de recordatorios por correo está activada.
                @else
                    Su preferencia de recordatorios por correo está desactivada.
                @endif
            </p>

            <div class="acciones sin-impresion">
                <button type="button" class="btn btn-primario" data-imprimir><x-icono nombre="documento" /> Imprimir comprobante</button>
                <a class="btn btn-secundario" href="{{ route('reservas.crear') }}"><x-icono nombre="mas" /> Reservar otra cita</a>
                <a class="btn btn-secundario" href="{{ route('panel') }}">Volver al panel</a>
            </div>
        </div>
    </div>
@endsection
