{{-- @CU CU-07 @RF RF07 @CI CIPacientes (mostrarFormulario) @CC CCPacientes @CE CEPaciente, CEAntecedente
     Campos que comparten crear.blade.php y editar.blade.php. Variables opcionales: $paciente, $antecedentes y $existente
     (el paciente que ya tiene ese CI cuando se rechazó el registro). --}}
@php
    $editando = isset($paciente);
    $marcaAntecedente = (bool) old('con_antecedente', false);
@endphp

@isset($existente)
    <div class="aviso aviso-alerta" role="alert">
        <x-icono nombre="alerta" />
        <span>
            Ese CI ya pertenece al paciente
            <strong>{{ $existente->codigo }} · {{ $existente->nombre_completo }}</strong>
            ({{ $existente->estado ? 'activo' : 'inactivo' }}).
            <a href="{{ route('pacientes.editar', $existente) }}">Abrir su ficha</a>
        </span>
    </div>
@endisset

<fieldset class="grupo-datos">
    <legend class="etiqueta">Datos personales</legend>
    <div class="formulario">
        <x-campo nombre="ci" etiqueta="CI" :valor="$paciente->ci ?? null" required maxlength="20" autocomplete="off"
                 pattern="[0-9A-Za-z][0-9A-Za-z\- ]{3,19}" data-mensaje="El CI solo puede tener letras, números y guion (de 4 a 20 caracteres)."
                 ayuda="Es único: no puede repetirse entre pacientes." />
        <x-campo nombre="nombres" etiqueta="Nombres" :valor="$paciente->nombres ?? null" required maxlength="80" autocomplete="off" />
        <x-campo nombre="apellidos" etiqueta="Apellidos" :valor="$paciente->apellidos ?? null" required maxlength="80" autocomplete="off" />
        <x-campo nombre="fecha_nacimiento" etiqueta="Fecha de nacimiento" tipo="date" :valor="isset($paciente) ? $paciente->fecha_nacimiento->format('Y-m-d') : null"
                 required min="1900-01-01" :max="now()->toDateString()" />
        <x-seleccion nombre="sexo" etiqueta="Sexo (opcional)" vacio="— Sin indicar —" :opciones="['M' => 'Masculino', 'F' => 'Femenino']" :valor="$paciente->sexo ?? null" />
        <x-campo nombre="telefono" etiqueta="Teléfono" tipo="tel" :valor="$paciente->telefono ?? null" required maxlength="20"
                 pattern="[0-9+\s\-]{7,20}" data-mensaje="El teléfono solo puede tener números (de 7 a 20 dígitos)." autocomplete="off" />
        <x-campo nombre="correo" etiqueta="Correo (opcional)" tipo="email" :valor="$paciente->correo ?? null" maxlength="120" autocomplete="off"
                 ayuda="Correo de contacto del paciente." />
        <div class="campo--ancho">
            <x-campo nombre="direccion" etiqueta="Dirección (opcional)" :valor="$paciente->direccion ?? null" maxlength="200" autocomplete="off" />
        </div>
    </div>
</fieldset>

<fieldset class="grupo-datos">
    <legend class="etiqueta">Contacto de emergencia (opcional)</legend>
    <div class="formulario">
        <x-campo nombre="emergencia_nombre" etiqueta="Nombre" :valor="$paciente->emergencia_nombre ?? null" maxlength="100" autocomplete="off" />
        <x-campo nombre="emergencia_parentesco" etiqueta="Parentesco" :valor="$paciente->emergencia_parentesco ?? null" maxlength="40" autocomplete="off" />
        <x-campo nombre="emergencia_telefono" etiqueta="Teléfono" tipo="tel" :valor="$paciente->emergencia_telefono ?? null" maxlength="20"
                 pattern="[0-9+\s\-]{7,20}" data-mensaje="El teléfono solo puede tener números (de 7 a 20 dígitos)." autocomplete="off" />
    </div>
</fieldset>

<fieldset class="grupo-datos">
    <legend class="etiqueta">Notificaciones y privacidad</legend>
    <div class="campo campo--check">
        <input type="hidden" name="recibe_recordatorios" value="0">
        <input type="checkbox" id="campo-recibe_recordatorios" name="recibe_recordatorios" value="1"
               @checked(old('recibe_recordatorios', $paciente->recibe_recordatorios ?? true))>
        <label for="campo-recibe_recordatorios">Recibe recordatorios de sus citas por correo</label>
    </div>
    <div class="campo campo--check">
        <input type="hidden" name="mostrar_contacto_medico" value="0">
        <input type="checkbox" id="campo-mostrar_contacto_medico" name="mostrar_contacto_medico" value="1"
               @checked(old('mostrar_contacto_medico', $paciente->mostrar_contacto_medico ?? true))>
        <label for="campo-mostrar_contacto_medico">Permite que el médico vea sus datos de contacto</label>
    </div>
</fieldset>

<fieldset class="grupo-datos grupo-antecedente">
    <legend class="etiqueta">Antecedentes de otro hospital (opcional)</legend>

    @if ($editando && $antecedentes->isNotEmpty())
        <div class="tabla-responsive">
            <table class="tabla">
                <thead><tr><th>Hospital o clínica</th><th>Fecha</th><th>Especialidad</th><th>Diagnóstico</th><th>Tratamiento</th></tr></thead>
                <tbody>
                    @foreach ($antecedentes as $antecedente)
                        <tr>
                            <td>{{ $antecedente->hospital_origen }}</td>
                            <td>{{ $antecedente->fecha_atencion?->format('d/m/Y') ?? '—' }}</td>
                            <td>{{ $antecedente->especialidad ?? '—' }}</td>
                            <td>{{ $antecedente->diagnostico }}</td>
                            <td>{{ $antecedente->tratamiento ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="campo campo--check">
        <input type="hidden" name="con_antecedente" value="0">
        <input type="checkbox" id="campo-con_antecedente" name="con_antecedente" value="1" @checked($marcaAntecedente) data-alterna="#datos-antecedente">
        <label for="campo-con_antecedente">{{ $editando ? 'Agregar un antecedente de otro hospital o clínica' : 'El paciente viene de otro hospital o clínica' }}</label>
    </div>
    <div id="datos-antecedente" class="formulario" @unless ($marcaAntecedente) hidden @endunless>
        <x-campo nombre="ant_hospital_origen" etiqueta="Hospital o clínica de origen" maxlength="120" autocomplete="off" />
        <x-campo nombre="ant_fecha_atencion" etiqueta="Fecha de atención (opcional)" tipo="date" :max="now()->toDateString()" />
        <x-campo nombre="ant_especialidad" etiqueta="Especialidad (opcional)" maxlength="80" autocomplete="off" />
        <x-campo nombre="ant_diagnostico" etiqueta="Diagnóstico" maxlength="300" autocomplete="off" ayuda="Tal como consta en sus documentos." />
        <x-campo nombre="ant_tratamiento" etiqueta="Tratamiento (opcional)" maxlength="400" autocomplete="off" />
        <x-campo nombre="ant_observaciones" etiqueta="Observaciones (opcional)" maxlength="400" autocomplete="off" />
    </div>
</fieldset>
