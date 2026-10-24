{{-- @CU CU-03 @RF RF03 @CI CIUsuarios (mostrarFormulario, enviarDatos) @CC CCUsuarios (crear, guardar) @CE CEUsuario, CERol
     Pasos 3 a 5 del flujo (alta): UsuariosController::crear() muestra el formulario y guardar() lo procesa. --}}
@extends('layouts.app')

@section('titulo', 'Nuevo usuario')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/seguridad.css')">
@endpush

@push('scripts')
    <script src="@recurso('js/modulos/seguridad.js')" defer></script>
@endpush

@section('contenido')
    <div class="encabezado-pagina">
        <h1>Nuevo usuario</h1>
        <a class="btn btn-secundario" href="{{ route('usuarios.index') }}">Volver al listado</a>
    </div>

    <div class="tarjeta">
        <div class="tarjeta__encabezado"><x-icono nombre="mas" /><h2>Datos de la cuenta</h2></div>
        <div class="tarjeta__cuerpo">
            <form method="POST" action="{{ route('usuarios.guardar') }}" data-validar data-requiere-rol novalidate>
                @csrf
                @include('seguridadusuarios::CU03_GestionarUsuariosRoles.campos')

                <div class="acciones">
                    <button type="submit" class="btn btn-primario"><x-icono nombre="check" /> Registrar usuario</button>
                    <a class="btn btn-secundario" href="{{ route('usuarios.index') }}">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
@endsection
