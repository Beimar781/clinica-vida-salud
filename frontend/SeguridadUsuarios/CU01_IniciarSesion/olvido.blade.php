{{-- @CU CU-01 (flujo alternativo A3: recuperar contraseña) @RF RF01 @CI CILogin (mostrarOlvido) @CC CCLogin @CE CEUsuario — OlvidoClaveController::mostrar() y enviar() --}}
@extends('layouts.app')

@section('titulo', 'Recuperar contraseña')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/seguridad.css')">
@endpush

@section('contenido')
    <section class="acceso">
        <div class="tarjeta">
            <div class="tarjeta__encabezado">
                <x-icono nombre="llave" />
                <h1>Recuperar contraseña</h1>
            </div>
            <div class="tarjeta__cuerpo">
                <p class="texto-suave">Escribe el correo de tu cuenta y te enviaremos un enlace para elegir una contraseña nueva. El enlace vence a los 60 minutos y solo sirve una vez.</p>
                <form method="POST" action="{{ route('password.email') }}" data-validar novalidate>
                    @csrf
                    <div class="formulario formulario--columna">
                        <x-campo nombre="correo" etiqueta="Correo electrónico" tipo="email"
                                 required maxlength="120" autocomplete="email" autofocus />
                    </div>
                    <div class="acciones">
                        <button type="submit" class="btn btn-primario"><x-icono nombre="correo" /> Enviar enlace</button>
                        <a class="btn btn-secundario" href="{{ route('login') }}">Volver a iniciar sesión</a>
                    </div>
                </form>
            </div>
        </div>
    </section>
@endsection
