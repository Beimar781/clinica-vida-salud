{{-- @CU CU-03 @RF RF03 @CI CIUsuarios (mostrarFormulario, enviarDatos) @CC CCUsuarios (crear, guardar, editar, actualizar) @CE CEUsuario, CERol --}}
@extends('layouts.app')

@php $editando = $usuario !== null; @endphp

@section('titulo', $editando ? 'Editar usuario' : 'Nuevo usuario')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/seguridad.css')">
@endpush

@push('scripts')
    <script src="@recurso('js/modulos/seguridad.js')" defer></script>
@endpush

@section('contenido')
    <div class="encabezado-pagina">
        <h1>{{ $editando ? 'Editar usuario' : 'Nuevo usuario' }}</h1>
        <a class="btn btn-secundario" href="{{ route('usuarios.index') }}">Volver al listado</a>
    </div>

    <div class="tarjeta">
        <div class="tarjeta__encabezado"><x-icono nombre="{{ $editando ? 'editar' : 'mas' }}" /><h2>Datos de la cuenta</h2></div>
        <div class="tarjeta__cuerpo">
            <form method="POST" action="{{ $editando ? route('usuarios.actualizar', $usuario) : route('usuarios.guardar') }}" data-validar data-requiere-rol novalidate>
                @csrf
                @if ($editando) @method('PUT') @endif

                <div class="formulario">
                    <x-campo nombre="nombre_usuario" etiqueta="Nombre de usuario" :valor="$usuario?->nombre_usuario"
                             required minlength="3" maxlength="50" pattern="[A-Za-z0-9._\-]+"
                             data-mensaje="Use solo letras, números, punto, guion y guion bajo (sin espacios ni @)." autocomplete="off" />
                    <x-campo nombre="correo" etiqueta="Correo" tipo="email" :valor="$usuario?->correo"
                             required maxlength="120" autocomplete="off" />
                    <x-campo nombre="password" etiqueta="{{ $editando ? 'Nueva contraseña (restablecer)' : 'Contraseña' }}" tipo="password"
                             :required="! $editando" minlength="8" maxlength="72" autocomplete="new-password"
                             :ayuda="$editando ? 'Déjela vacía para conservar la actual. Mínimo 8 caracteres.' : 'Mínimo 8 caracteres.'" />
                </div>

                <fieldset @class(['campo', 'campo--error' => $errors->has('roles') || $errors->has('roles.*'), 'grupo-roles'])>
                    <legend class="etiqueta">Roles</legend>
                    <div class="roles">
                        @foreach ($roles as $rol)
                            <label class="rol-opcion" for="rol-{{ $rol->id }}">
                                <input type="checkbox" id="rol-{{ $rol->id }}" name="roles[]" value="{{ $rol->id }}"
                                       @checked(in_array($rol->id, array_map('intval', old('roles', $rolesDelUsuario))))>
                                <span><strong>{{ $rol->nombre }}</strong><small>{{ $rol->descripcion }}</small></span>
                            </label>
                        @endforeach
                    </div>
                    <span class="campo__ayuda">Un usuario puede tener varios roles; sus permisos son la suma de todos.</span>
                    <span class="campo__error" id="error-roles" role="alert">{{ $errors->first('roles') ?: $errors->first('roles.*') }}</span>
                </fieldset>

                <div class="acciones">
                    <button type="submit" class="btn btn-primario"><x-icono nombre="check" /> {{ $editando ? 'Guardar cambios' : 'Registrar usuario' }}</button>
                    <a class="btn btn-secundario" href="{{ route('usuarios.index') }}">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
@endsection
