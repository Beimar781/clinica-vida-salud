{{-- @CU CU-04 @RF RF04 @CI CIEspecialidad (mostrarFormulario, enviarDatos) @CC CCEspecialidad (editar, actualizar) @CE CEEspecialidad
     Pasos 3 a 5 del flujo: EspecialidadController::editar() muestra el formulario y actualizar() lo procesa. --}}
@extends('layouts.app')

@section('titulo', 'Editar especialidad o servicio')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/gestion-medica.css')">
@endpush

@section('contenido')
    <div class="encabezado-pagina">
        <h1>Editar especialidad o servicio</h1>
        <a class="btn btn-secundario" href="{{ route('especialidades.index') }}">Volver al listado</a>
    </div>

    <div class="tarjeta">
        <div class="tarjeta__encabezado"><x-icono nombre="editar" /><h2>Datos de la especialidad o servicio</h2></div>
        <div class="tarjeta__cuerpo">
            <form method="POST" action="{{ route('especialidades.actualizar', $especialidad) }}" data-validar novalidate>
                @csrf
                @method('PUT')
                @include('gestionmedica::CU04_GestionarEspecialidadesServicios.campos')

                <div class="acciones">
                    <button type="submit" class="btn btn-primario"><x-icono nombre="check" /> Guardar cambios</button>
                    <a class="btn btn-secundario" href="{{ route('especialidades.index') }}">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
@endsection
