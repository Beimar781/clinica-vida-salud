{{-- @CU CU-07 @RF RF07 @CI CIPacientes (mostrarFormulario, enviarDatos) @CC CCPacientes (crear, guardar) @CE CEPaciente, CEHistoriaClinica, CEAntecedente
     Pasos 3 a 6 del flujo (alta): PacientesController::crear() muestra el formulario y guardar() lo procesa (crea también la historia clínica). --}}
@extends('layouts.app')

@section('titulo', 'Nuevo paciente')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/gestion-pacientes.css')">
@endpush

@push('scripts')
    <script src="@recurso('js/modulos/gestion-pacientes.js')" defer></script>
@endpush

@section('contenido')
    <div class="encabezado-pagina">
        <h1>Nuevo paciente</h1>
        <a class="btn btn-secundario" href="{{ route('pacientes.index') }}">Volver al listado</a>
    </div>

    <div class="tarjeta">
        <div class="tarjeta__encabezado"><x-icono nombre="mas" /><h2>Datos del paciente</h2></div>
        <div class="tarjeta__cuerpo">
            <form method="POST" action="{{ route('pacientes.guardar') }}" data-validar novalidate>
                @csrf
                @include('gestionpacientes::CU07_GestionarPacientes.campos')

                <p class="texto-suave">Al registrar al paciente se genera su código y se crea su historia clínica.</p>
                <div class="acciones">
                    <button type="submit" class="btn btn-primario"><x-icono nombre="check" /> Registrar paciente</button>
                    <a class="btn btn-secundario" href="{{ route('pacientes.index') }}">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
@endsection
