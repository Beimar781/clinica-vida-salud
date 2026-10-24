{{-- @CU CU-07 @RF RF07 @CI CIPacientes (mostrarFormulario, enviarDatos) @CC CCPacientes (editar, actualizar) @CE CEPaciente, CEHistoriaClinica, CEAntecedente
     Pasos 3 a 6 del flujo (edición): PacientesController::editar() muestra el formulario y actualizar() lo procesa. --}}
@extends('layouts.app')

@section('titulo', 'Editar paciente')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/gestion-pacientes.css')">
@endpush

@push('scripts')
    <script src="@recurso('js/modulos/gestion-pacientes.js')" defer></script>
@endpush

@section('contenido')
    <div class="encabezado-pagina">
        <h1>Editar paciente <small class="texto-suave">{{ $paciente->codigo }}</small></h1>
        <a class="btn btn-secundario" href="{{ route('pacientes.index') }}">Volver al listado</a>
    </div>

    <div class="tarjeta">
        <div class="tarjeta__encabezado"><x-icono nombre="editar" /><h2>Datos del paciente</h2></div>
        <div class="tarjeta__cuerpo">
            @if ($paciente->historiaClinica)
                <p class="texto-suave">Historia clínica abierta el {{ $paciente->historiaClinica->fecha_apertura->format('d/m/Y') }}.</p>
            @endif
            <form method="POST" action="{{ route('pacientes.actualizar', $paciente) }}" data-validar novalidate>
                @csrf
                @method('PUT')
                @include('gestionpacientes::CU07_GestionarPacientes.campos')

                <div class="acciones">
                    <button type="submit" class="btn btn-primario"><x-icono nombre="check" /> Guardar cambios</button>
                    <a class="btn btn-secundario" href="{{ route('pacientes.index') }}">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
@endsection
