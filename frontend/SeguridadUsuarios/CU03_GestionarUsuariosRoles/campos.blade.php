{{-- @CU CU-03 @RF RF03 @CI CIUsuarios (mostrarFormulario) @CC CCUsuarios @CE CEUsuario, CERol
     Campos que comparten crear.blade.php y editar.blade.php. Variables: $roles, $rolesDelUsuario y $usuario (solo al editar). --}}
@php $editando = isset($usuario); @endphp

<div class="formulario">
    <x-campo nombre="nombre_usuario" etiqueta="Nombre de usuario" :valor="$usuario->nombre_usuario ?? null"
             required minlength="3" maxlength="50" pattern="[A-Za-z0-9._\-]+"
             data-mensaje="Use solo letras, números, punto, guion y guion bajo (sin espacios ni @)." autocomplete="off" />
    <x-campo nombre="correo" etiqueta="Correo" tipo="email" :valor="$usuario->correo ?? null"
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
