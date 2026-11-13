{{-- @CU CU-03 @RF RF03 @CI CIUsuarios (mostrarFormulario) @CC CCUsuarios @CE CEUsuario, CERol
     Campos que comparten crear.blade.php y editar.blade.php. Variables: $roles, $rolesDelUsuario y $usuario (solo al editar). --}}
@php $editando = isset($usuario); @endphp

<div class="formulario">
    <x-campo nombre="nombre_usuario" etiqueta="Nombre de usuario" :valor="$usuario->nombre_usuario ?? null"
             required minlength="3" maxlength="50" pattern="[A-Za-z0-9._\-]+"
             data-mensaje="Use solo letras, números, punto, guion y guion bajo (sin espacios ni @)." autocomplete="off" />
    <x-campo nombre="correo" etiqueta="Correo" tipo="email" :valor="$usuario->correo ?? null"
             required maxlength="120" autocomplete="off" />
    <x-campo-clave nombre="password" etiqueta="{{ $editando ? 'Nueva contraseña (restablecer)' : 'Contraseña' }}"
                   :required="! $editando" minlength="8" maxlength="72" autocomplete="new-password"
                   :ayuda="$editando ? 'Déjela vacía para conservar la actual. Mínimo 8 caracteres.' : 'Mínimo 8 caracteres.'" />
</div>

<fieldset @class(['campo', 'campo--error' => $errors->has('roles') || $errors->has('roles.*'), 'grupo-roles'])>
    <legend class="etiqueta">Roles</legend>
    <div class="roles">
        @foreach ($roles as $rol)
            <label class="rol-opcion" for="rol-{{ $rol->id }}">
                <input type="checkbox" id="rol-{{ $rol->id }}" name="roles[]" value="{{ $rol->id }}" @if ($rol->nombre === \App\Models\Rol::PACIENTE) data-rol-paciente @endif
                       @checked(in_array($rol->id, array_map('intval', old('roles', $rolesDelUsuario))))>
                <span><strong>{{ $rol->nombre }}</strong><small>{{ $rol->descripcion }}</small></span>
            </label>
        @endforeach
    </div>
    <span class="campo__ayuda">Un usuario puede tener varios roles; sus permisos son la suma de todos.</span>
    <span class="campo__error" id="error-roles" data-fijo role="alert">{{ $errors->first('roles') ?: $errors->first('roles.*') }}</span>
</fieldset>

{{-- Ficha del paciente (CU-03 ↔ CU-07): toda cuenta con el rol Paciente necesita su ficha. Se muestra solo con el rol Paciente marcado
     (seguridad.js); si la cuenta ya tiene ficha se informa y no se pide nada. --}}
@php
    $idRolPaciente = $roles->firstWhere('nombre', \App\Models\Rol::PACIENTE)?->id;
    $fichaActual = $editando ? $usuario->paciente : null;
    $pacienteMarcado = $idRolPaciente && in_array($idRolPaciente, array_map('intval', old('roles', $rolesDelUsuario)), true);
@endphp
<fieldset class="grupo-datos" id="ficha-paciente" data-ficha-paciente @unless ($pacienteMarcado) hidden @endunless>
    <legend class="etiqueta">Ficha del paciente</legend>
    @if ($fichaActual)
        <p class="texto-suave sin-margen">La cuenta ya tiene su ficha <strong>{{ $fichaActual->codigo }}</strong> ({{ $fichaActual->nombre_completo }}, CI {{ $fichaActual->ci }}). Se edita en «Pacientes».</p>
    @else
        <p class="texto-suave">Una cuenta con el rol Paciente necesita su ficha. Si ya existe una ficha con ese CI y sin cuenta (por ejemplo, registrada en recepción), se vincula con esta cuenta y conserva su historia y sus citas; si no existe, se crea.</p>
        <div class="formulario">
            <x-campo nombre="ci" etiqueta="CI" :valor="old('ci')" maxlength="20" autocomplete="off" data-ficha-campo
                     pattern="[0-9A-Za-z][0-9A-Za-z\- ]{3,19}" data-mensaje="El CI solo puede tener letras, números y guion (de 4 a 20 caracteres)." />
            <x-campo nombre="fecha_nacimiento" etiqueta="Fecha de nacimiento" tipo="date" :valor="old('fecha_nacimiento')" min="1900-01-01" :max="now()->toDateString()" data-ficha-campo
                     ayuda="No hace falta si ya existe la ficha con ese CI." />
            <x-campo nombre="nombres" etiqueta="Nombres" :valor="old('nombres')" maxlength="80" autocomplete="off" data-ficha-campo />
            <x-campo nombre="apellidos" etiqueta="Apellidos" :valor="old('apellidos')" maxlength="80" autocomplete="off" data-ficha-campo />
            <x-campo nombre="telefono" etiqueta="Teléfono" tipo="tel" :valor="old('telefono')" maxlength="20" autocomplete="off" data-ficha-campo
                     pattern="[0-9+\s\-]{7,20}" data-mensaje="El teléfono solo puede tener números (de 7 a 20 dígitos)." />
        </div>
    @endif
</fieldset>
