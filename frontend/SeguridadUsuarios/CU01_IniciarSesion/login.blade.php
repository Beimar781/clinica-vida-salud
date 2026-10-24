{{-- @CU CU-01 @RF RF01 @CI CILogin (mostrarFormulario, enviarCredenciales, mostrarError) @CC CCLogin @CE CEUsuario — pasos 1 y 2 del flujo: LoginController::mostrarFormulario() y autenticar() --}}
@extends('layouts.app')

@section('titulo', 'Iniciar sesión')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/seguridad.css')">
@endpush

@section('contenido')
    <section class="acceso">
        <div class="tarjeta">
            <div class="tarjeta__encabezado">
                <x-icono nombre="llave" />
                <h1>Iniciar sesión</h1>
            </div>
            <div class="tarjeta__cuerpo">
                <form method="POST" action="{{ route('login.autenticar') }}" data-validar novalidate>
                    @csrf
                    <div class="formulario formulario--columna">
                        <x-campo nombre="identificador" etiqueta="Correo o nombre de usuario"
                                 required maxlength="120" autocomplete="username" autofocus />
                        <x-campo-clave nombre="password" etiqueta="Contraseña"
                                       required autocomplete="current-password" />
                    </div>
                    <p class="texto-suave enlace-olvido"><a href="{{ route('password.request') }}">¿Olvidaste tu contraseña?</a></p>
                    <div class="acciones">
                        <button type="submit" class="btn btn-primario"><x-icono nombre="entrar" /> Ingresar</button>
                        <a class="btn btn-secundario" href="{{ route('inicio') }}">Volver al inicio</a>
                    </div>
                </form>
            </div>
        </div>
    </section>
@endsection
