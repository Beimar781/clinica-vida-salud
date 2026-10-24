{{-- @CU CU-05 @RF RF05 @CI CIMedicos (mostrarFormulario, enviarDatos) @CC CCMedicos (editar, actualizar) @CE CEMedico, CEEspecialidad
     Pasos 3 a 5 del flujo (edición): MedicosController::editar() muestra el formulario y actualizar() lo procesa. --}}
@extends('layouts.app')

@section('titulo', 'Editar médico')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/gestion-medica.css')">
@endpush

@push('scripts')
    <script src="@recurso('js/modulos/gestion-medica.js')" defer></script>
@endpush

@section('contenido')
    <div class="encabezado-pagina">
        <h1>Editar médico <small class="texto-suave">{{ $medico->codigo }}</small></h1>
        <a class="btn btn-secundario" href="{{ route('medicos.index') }}">Volver al listado</a>
    </div>

    <div class="tarjeta">
        <div class="tarjeta__encabezado"><x-icono nombre="editar" /><h2>Datos del médico</h2></div>
        <div class="tarjeta__cuerpo">
            <form method="POST" action="{{ route('medicos.actualizar', $medico) }}" data-validar data-requiere-especialidad novalidate>
                @csrf
                @method('PUT')
                @include('gestionmedica::CU05_GestionarMedicos.campos')

                <div class="acciones">
                    <button type="submit" class="btn btn-primario"><x-icono nombre="check" /> Guardar cambios</button>
                    <a class="btn btn-secundario" href="{{ route('medicos.index') }}">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
@endsection
