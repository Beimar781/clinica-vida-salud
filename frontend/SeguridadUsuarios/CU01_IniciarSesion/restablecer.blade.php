{{-- @CU CU-01 (flujo alternativo A3: recuperar contraseña) @RF RF01 @CI CILogin (mostrarRestablecer) @CC CCLogin @CE CEUsuario — RestablecerClaveController::mostrar() y guardar() --}}
@extends('layouts.app')

@section('titulo', 'Contraseña nueva')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/seguridad.css')">
@endpush

@push('scripts')
    <script src="@recurso('js/modulos/seguridad.js')" defer></script>
@endpush

@section('contenido')
    <section class="acceso">
        <div class="tarjeta">
            <div class="tarjeta__encabezado">
                <x-icono nombre="llave" />
                <h1>Contraseña nueva</h1>
            </div>
            <div class="tarjeta__cuerpo">
                <p class="texto-suave">Elige la contraseña nueva de la cuenta <strong>{{ $correo }}</strong>. Debe tener al menos 8 caracteres.</p>
                <form method="POST" action="{{ route('password.update') }}" data-validar data-confirmar-clave novalidate>
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <input type="hidden" name="correo" value="{{ $correo }}">
                    @error('token')<span class="campo__error" role="alert" data-fijo>{{ $message }}</span>@enderror
                    @error('correo')<span class="campo__error" role="alert" data-fijo>{{ $message }}</span>@enderror
                    <div class="formulario formulario--columna">
                        <x-campo nombre="password" etiqueta="Contraseña nueva" tipo="password"
                                 required minlength="8" maxlength="255" autocomplete="new-password" autofocus />
                        <x-campo nombre="password_confirmation" etiqueta="Confirmar contraseña" tipo="password"
                                 required minlength="8" maxlength="255" autocomplete="new-password" />
                    </div>
                    <div class="acciones">
                        <button type="submit" class="btn btn-primario"><x-icono nombre="check" /> Guardar contraseña</button>
                        <a class="btn btn-secundario" href="{{ route('login') }}">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </section>
@endsection
