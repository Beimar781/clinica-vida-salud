{{-- @CU CU-20 @RF RF20 @CI CIRegistro (mostrarFormulario, validarDatos, enviarRegistro) @CC CCRegistro @CE CEUsuario, CEPaciente, CEHistoriaClinica
     Pasos 1 a 3 del flujo: RegistroController::mostrar() y guardar() --}}
@extends('layouts.app')

@section('titulo', 'Crear cuenta')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/seguridad.css')">
    <link rel="stylesheet" href="@recurso('css/modulos/gestion-pacientes.css')">
@endpush

@push('scripts')
    <script src="@recurso('js/modulos/seguridad.js')" defer></script>
@endpush

@section('contenido')
    <section class="acceso acceso--ancho">
        <div class="tarjeta">
            <div class="tarjeta__encabezado">
                <x-icono nombre="paciente" />
                <h1>Crear cuenta de paciente</h1>
            </div>
            <div class="tarjeta__cuerpo">
                <p class="texto-suave">Regístrate para reservar tus citas y ver tu historial desde la web. Si ya estuviste en la clínica, usa el mismo CI y tu fecha de nacimiento: conservaremos tu historia clínica.</p>
                <form method="POST" action="{{ route('registro.guardar') }}" data-validar data-confirmar-clave novalidate>
                    @csrf
                    <fieldset class="grupo-datos">
                        <legend class="etiqueta">Tus datos</legend>
                        <div class="formulario">
                            <x-campo nombre="ci" etiqueta="CI" :valor="old('ci')" required maxlength="20" autocomplete="off"
                                     pattern="[0-9A-Za-z][0-9A-Za-z\- ]{3,19}" data-mensaje="El CI solo puede tener letras, números y guion (de 4 a 20 caracteres)." />
                            <x-campo nombre="fecha_nacimiento" etiqueta="Fecha de nacimiento" tipo="date" :valor="old('fecha_nacimiento')"
                                     required min="1900-01-01" :max="now()->toDateString()" autocomplete="bday" />
                            <x-campo nombre="nombres" etiqueta="Nombres" :valor="old('nombres')" required maxlength="80" autocomplete="given-name" />
                            <x-campo nombre="apellidos" etiqueta="Apellidos" :valor="old('apellidos')" required maxlength="80" autocomplete="family-name" />
                            <x-seleccion nombre="sexo" etiqueta="Sexo (opcional)" vacio="— Sin indicar —" :opciones="['M' => 'Masculino', 'F' => 'Femenino']" :valor="old('sexo')" />
                            <x-campo nombre="telefono" etiqueta="Teléfono" tipo="tel" :valor="old('telefono')" required maxlength="20" autocomplete="tel"
                                     pattern="[0-9+\s\-]{7,20}" data-mensaje="El teléfono solo puede tener números (de 7 a 20 dígitos)." />
                        </div>
                    </fieldset>
                    <fieldset class="grupo-datos">
                        <legend class="etiqueta">Tu cuenta</legend>
                        <div class="formulario">
                            <div class="campo--ancho">
                                <x-campo nombre="correo" etiqueta="Correo" tipo="email" :valor="old('correo')" required maxlength="120" autocomplete="email"
                                         ayuda="Con este correo ingresarás al sistema y recibirás tus recordatorios." />
                            </div>
                            <x-campo-clave nombre="password" etiqueta="Contraseña" required minlength="8" maxlength="72" autocomplete="new-password"
                                           ayuda="Al menos 8 caracteres." />
                            <x-campo-clave nombre="password_confirmation" etiqueta="Confirmar contraseña" required minlength="8" maxlength="72" autocomplete="new-password" />
                        </div>
                    </fieldset>
                    <div class="acciones">
                        <button type="submit" class="btn btn-primario"><x-icono nombre="check" /> Crear cuenta</button>
                        <a class="btn btn-secundario" href="{{ route('inicio') }}">Volver al inicio</a>
                    </div>
                    <p class="texto-suave enlace-olvido">¿Ya tienes cuenta? <a href="{{ route('login') }}">Inicia sesión</a></p>
                </form>
            </div>
        </div>
    </section>
@endsection
