{{-- @CU CU-05 @RF RF05 @CI CIMedicos (mostrarFormulario) @CC CCMedicos @CE CEMedico, CEEspecialidad
     Campos que comparten crear.blade.php y editar.blade.php.
     Variables: $especialidades, $especialidadesDelMedico y $medico (solo al editar). --}}
@php
    $editando = isset($medico);
    $tieneCuenta = $editando && $medico->usuario_id;
    $marcaCuenta = ! $tieneCuenta && old('crear_cuenta', false);
@endphp

<div class="formulario">
    <x-campo nombre="nombres" etiqueta="Nombres" :valor="$medico->nombres ?? null" required maxlength="80" autocomplete="off" autofocus />
    <x-campo nombre="apellidos" etiqueta="Apellidos" :valor="$medico->apellidos ?? null" required maxlength="80" autocomplete="off" />
    <x-campo nombre="nro_registro" etiqueta="N.º de registro profesional" :valor="$medico->nro_registro ?? null" required maxlength="30" autocomplete="off"
             ayuda="Es único: no puede repetirse entre médicos." />
    <x-campo nombre="telefono" etiqueta="Teléfono (opcional)" tipo="tel" :valor="$medico->telefono ?? null" maxlength="20"
             pattern="[0-9+\s\-]{7,20}" data-mensaje="El teléfono solo puede tener números (de 7 a 20 dígitos)." autocomplete="off" />
    <x-campo nombre="correo" etiqueta="Correo (opcional)" tipo="email" :valor="$medico->correo ?? null" maxlength="120" autocomplete="off"
             ayuda="Obligatorio si se crea su cuenta de acceso." />
    <x-campo nombre="ubicacion" etiqueta="Ubicación (consultorio o sede)" :valor="$medico->ubicacion ?? null" maxlength="100" autocomplete="off"
             ayuda="Se usa para que el paciente busque médicos por ubicación." />
</div>

<fieldset @class(['campo', 'campo--error' => $errors->has('especialidades') || $errors->has('especialidades.*'), 'grupo-opciones'])>
    <legend class="etiqueta">Especialidades</legend>
    <div class="opciones">
        @foreach ($especialidades as $especialidad)
            <label class="opcion" for="especialidad-{{ $especialidad->id }}">
                <input type="checkbox" id="especialidad-{{ $especialidad->id }}" name="especialidades[]" value="{{ $especialidad->id }}"
                       @checked(in_array($especialidad->id, array_map('intval', old('especialidades', $especialidadesDelMedico))))>
                <span><strong>{{ $especialidad->nombre }}</strong>@unless ($especialidad->estado)<small>Inactiva</small>@endunless</span>
            </label>
        @endforeach
    </div>
    <span class="campo__ayuda">Un médico puede ejercer una o más especialidades.</span>
    <span class="campo__error" id="error-especialidades" data-fijo role="alert">{{ $errors->first('especialidades') ?: $errors->first('especialidades.*') }}</span>
</fieldset>

<div class="campo campo--check bloque-permiso">
    <input type="hidden" name="puede_marcar_atendida" value="0">
    <input type="checkbox" id="campo-puede_marcar_atendida" name="puede_marcar_atendida" value="1"
           @checked(old('puede_marcar_atendida', $medico->puede_marcar_atendida ?? true))>
    <label for="campo-puede_marcar_atendida">Puede marcar citas como atendidas</label>
</div>

<fieldset class="grupo-cuenta">
    <legend class="etiqueta">Cuenta de acceso (opcional)</legend>
    @if ($tieneCuenta)
        <p class="texto-suave sin-margen">Este médico ya tiene la cuenta <strong>{{ $medico->usuario->nombre_usuario }}</strong> con el rol Médico. Sus roles y contraseña se gestionan en «Usuarios y roles».</p>
    @else
        <div class="campo campo--check">
            <input type="hidden" name="crear_cuenta" value="0">
            <input type="checkbox" id="campo-crear_cuenta" name="crear_cuenta" value="1" @checked($marcaCuenta) data-alterna="#datos-cuenta">
            <label for="campo-crear_cuenta">Crear su cuenta de usuario con el rol Médico (usa el correo del médico)</label>
        </div>
        <div id="datos-cuenta" class="formulario" @unless ($marcaCuenta) hidden @endunless>
            <x-campo nombre="nombre_usuario" etiqueta="Nombre de usuario" maxlength="50" minlength="3" autocomplete="off"
                     pattern="[A-Za-z0-9._\-]+" data-mensaje="Use solo letras, números, punto, guion y guion bajo (sin espacios ni @)." />
            <x-campo nombre="password" etiqueta="Contraseña" tipo="password" minlength="8" maxlength="72" autocomplete="new-password"
                     ayuda="Mínimo 8 caracteres." />
        </div>
    @endif
</fieldset>
