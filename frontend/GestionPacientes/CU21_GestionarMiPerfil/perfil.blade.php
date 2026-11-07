{{-- @CU CU-21 @RF RF21 @CI CIPerfil (mostrarPerfil, enviarDatos, mostrarMensaje) @CC CCPerfil @CE CEPaciente, CEHistoriaClinica, CEUsuario
     Pasos 1 a 6 del flujo: PerfilController::mostrar(), actualizar() y cambiarContrasena() --}}
@extends('layouts.app')

@section('titulo', 'Mi perfil')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/seguridad.css')">
    <link rel="stylesheet" href="@recurso('css/modulos/gestion-pacientes.css')">
@endpush

@push('scripts')
    <script src="@recurso('js/modulos/seguridad.js')" defer></script>
@endpush

@section('contenido')
    <div class="encabezado-pagina">
        <div>
            <h1>Mi perfil</h1>
            @if ($paciente)
                <p class="texto-suave sin-margen">Ficha <strong>{{ $paciente->codigo }}</strong> · {{ $paciente->nombre_completo }}</p>
            @endif
        </div>
    </div>

    <x-aviso-sin-ficha />

    @if ($paciente)
        <div class="perfil">
            <form method="POST" action="{{ route('perfil.actualizar') }}" data-validar novalidate class="tarjeta">
                @csrf
                @method('PUT')
                <div class="tarjeta__cuerpo">
                    <fieldset class="grupo-datos">
                        <legend class="etiqueta">Datos personales</legend>
                        <div class="formulario">
                            <div class="campo">
                                <label for="ci-solo-lectura">CI</label>
                                <input id="ci-solo-lectura" type="text" value="{{ $paciente->ci }}" readonly aria-describedby="ci-ayuda">
                                <span class="campo__ayuda" id="ci-ayuda">No se puede cambiar. Si es incorrecto, solicítalo en recepción.</span>
                                @error('ci')<span class="campo__error" role="alert">{{ $message }}</span>@enderror
                            </div>
                            <x-campo nombre="fecha_nacimiento" etiqueta="Fecha de nacimiento" tipo="date" :valor="$paciente->fecha_nacimiento->format('Y-m-d')"
                                     required min="1900-01-01" :max="now()->toDateString()" />
                            <x-campo nombre="nombres" etiqueta="Nombres" :valor="$paciente->nombres" required maxlength="80" autocomplete="given-name" />
                            <x-campo nombre="apellidos" etiqueta="Apellidos" :valor="$paciente->apellidos" required maxlength="80" autocomplete="family-name" />
                            <x-seleccion nombre="sexo" etiqueta="Sexo (opcional)" vacio="— Sin indicar —" :opciones="['M' => 'Masculino', 'F' => 'Femenino']" :valor="$paciente->sexo" />
                        </div>
                    </fieldset>

                    <fieldset class="grupo-datos">
                        <legend class="etiqueta">Contacto</legend>
                        <div class="formulario">
                            <x-campo nombre="telefono" etiqueta="Teléfono" tipo="tel" :valor="$paciente->telefono" required maxlength="20" autocomplete="tel"
                                     pattern="[0-9+\s\-]{7,20}" data-mensaje="El teléfono solo puede tener números (de 7 a 20 dígitos)." />
                            <x-campo nombre="correo" etiqueta="Correo de contacto (opcional)" tipo="email" :valor="$paciente->correo" maxlength="120" autocomplete="email"
                                     ayuda="Aquí te llegan los recordatorios. No cambia el correo con el que ingresas." />
                            <div class="campo--ancho">
                                <x-campo nombre="direccion" etiqueta="Dirección (opcional)" :valor="$paciente->direccion" maxlength="200" autocomplete="street-address" />
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="grupo-datos">
                        <legend class="etiqueta">Información médica</legend>
                        <p class="texto-suave sin-margen">Se guarda en tu historia clínica y la ve el médico que te atiende. Tus consultas anteriores no cambian.</p>
                        <div class="formulario">
                            <x-seleccion nombre="tipo_sangre" etiqueta="Tipo de sangre (opcional)" vacio="— No lo sé —"
                                         :opciones="array_combine($tiposSangre, $tiposSangre)" :valor="$historia->tipo_sangre" />
                            <div class="campo--ancho">
                                <x-areatexto nombre="alergias" etiqueta="Alergias (opcional)" :valor="$historia->alergias" rows="2" maxlength="200" />
                            </div>
                            <div class="campo--ancho">
                                <x-areatexto nombre="enfermedades_cronicas" etiqueta="Enfermedades crónicas (opcional)" :valor="$historia->enfermedades_cronicas" rows="2" maxlength="300" />
                            </div>
                            <div class="campo--ancho">
                                <x-areatexto nombre="medicamentos" etiqueta="Medicamentos que toma (opcional)" :valor="$historia->medicamentos" rows="2" maxlength="300" />
                            </div>
                            <div class="campo--ancho">
                                <x-areatexto nombre="antecedentes_personales" etiqueta="Antecedentes personales (opcional)" :valor="$historia->antecedentes_personales" rows="3" maxlength="400" />
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="grupo-datos">
                        <legend class="etiqueta">Contacto de emergencia (opcional)</legend>
                        <div class="formulario">
                            <x-campo nombre="emergencia_nombre" etiqueta="Nombre" :valor="$paciente->emergencia_nombre" maxlength="100" autocomplete="off" />
                            <x-campo nombre="emergencia_parentesco" etiqueta="Parentesco" :valor="$paciente->emergencia_parentesco" maxlength="40" autocomplete="off" />
                            <x-campo nombre="emergencia_telefono" etiqueta="Teléfono" tipo="tel" :valor="$paciente->emergencia_telefono" maxlength="20"
                                     pattern="[0-9+\s\-]{7,20}" data-mensaje="El teléfono solo puede tener números (de 7 a 20 dígitos)." autocomplete="off" />
                        </div>
                    </fieldset>

                    <fieldset class="grupo-datos">
                        <legend class="etiqueta">Cuenta: notificaciones y privacidad</legend>
                        <p class="texto-suave sin-margen">Cuenta de acceso: <strong>{{ $usuario->correo }}</strong> (usuario <strong>{{ $usuario->nombre_usuario }}</strong>).</p>
                        <div class="campo campo--check">
                            <input type="hidden" name="recibe_recordatorios" value="0">
                            <input type="checkbox" id="campo-recibe_recordatorios" name="recibe_recordatorios" value="1"
                                   @checked(old('recibe_recordatorios', $paciente->recibe_recordatorios))>
                            <label for="campo-recibe_recordatorios">Quiero recibir recordatorios de mis citas por correo</label>
                        </div>
                        <div class="campo campo--check">
                            <input type="hidden" name="mostrar_contacto_medico" value="0">
                            <input type="checkbox" id="campo-mostrar_contacto_medico" name="mostrar_contacto_medico" value="1"
                                   @checked(old('mostrar_contacto_medico', $paciente->mostrar_contacto_medico))>
                            <label for="campo-mostrar_contacto_medico">Permito que mi médico vea mi teléfono y mi correo</label>
                        </div>
                    </fieldset>

                    <div class="acciones">
                        <button type="submit" class="btn btn-primario"><x-icono nombre="check" /> Guardar cambios</button>
                        <a class="btn btn-secundario" href="{{ route('panel') }}">Volver al panel</a>
                    </div>
                </div>
            </form>

            <form method="POST" action="{{ route('perfil.contrasena') }}" data-validar data-confirmar-clave novalidate class="tarjeta" id="cambiar-contrasena">
                @csrf
                @method('PUT')
                <div class="tarjeta__encabezado">
                    <x-icono nombre="llave" />
                    <h2>Cambiar contraseña</h2>
                </div>
                <div class="tarjeta__cuerpo">
                    <div class="formulario">
                        <div class="campo--ancho">
                            <x-campo-clave nombre="password_actual" etiqueta="Contraseña actual" required maxlength="255" autocomplete="current-password" />
                        </div>
                        <x-campo-clave nombre="password" etiqueta="Contraseña nueva" required minlength="8" maxlength="72" autocomplete="new-password"
                                       ayuda="Al menos 8 caracteres." />
                        <x-campo-clave nombre="password_confirmation" etiqueta="Confirmar contraseña nueva" required minlength="8" maxlength="72" autocomplete="new-password" />
                    </div>
                    <div class="acciones">
                        <button type="submit" class="btn btn-primario"><x-icono nombre="check" /> Cambiar contraseña</button>
                    </div>
                </div>
            </form>
        </div>
    @endif
@endsection
