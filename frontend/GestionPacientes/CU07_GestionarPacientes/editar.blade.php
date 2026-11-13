{{-- @CU CU-07 @RF RF07 @CI CIPacientes (mostrarFormulario, enviarDatos) @CC CCPacientes (editar, actualizar) @CE CEPaciente, CEHistoriaClinica, CEAntecedente
     Pasos 3 a 6 del flujo (edición): PacientesController::editar() muestra el formulario y actualizar() lo procesa. --}}
@extends('layouts.app')

@section('titulo', 'Editar paciente')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/gestion-pacientes.css')">
@endpush

@push('scripts')
    <script src="@recurso('js/modulos/gestion-pacientes.js')" defer></script>
@endpush

@section('contenido')
    <div class="encabezado-pagina">
        <h1>Editar paciente <small class="texto-suave">{{ $paciente->codigo }}</small></h1>
        <a class="btn btn-secundario" href="{{ route('pacientes.index') }}">Volver al listado</a>
    </div>

    <div class="tarjeta">
        <div class="tarjeta__encabezado"><x-icono nombre="editar" /><h2>Datos del paciente</h2></div>
        <div class="tarjeta__cuerpo">
            @if ($paciente->historiaClinica)
                <p class="texto-suave">Historia clínica abierta el {{ $paciente->historiaClinica->fecha_apertura->format('d/m/Y') }}.</p>
            @endif
            <form method="POST" action="{{ route('pacientes.actualizar', $paciente) }}" data-validar novalidate>
                @csrf
                @method('PUT')
                @include('gestionpacientes::CU07_GestionarPacientes.campos')

                <div class="acciones">
                    <button type="submit" class="btn btn-primario"><x-icono nombre="check" /> Guardar cambios</button>
                    <a class="btn btn-secundario" href="{{ route('pacientes.index') }}">Cancelar</a>
                </div>
            </form>
        </div>
    </div>

    {{-- Cuenta de acceso del paciente (CU-03 ↔ CU-07): vincular una cuenta Paciente existente que todavía no tiene ficha --}}
    <div class="tarjeta" style="margin-top:1.25rem" id="cuenta-del-paciente">
        <div class="tarjeta__encabezado"><x-icono nombre="llave" /><h2>Cuenta de acceso</h2></div>
        <div class="tarjeta__cuerpo">
            @if ($cuentaVinculada)
                <p class="sin-margen">Esta ficha está vinculada con la cuenta <strong>{{ $cuentaVinculada->nombre_usuario }}</strong> ({{ $cuentaVinculada->correo }}).</p>
            @elseif ($cuentasSinFicha->isEmpty())
                <p class="texto-suave sin-margen">Esta ficha no tiene cuenta de usuario y no hay cuentas con rol Paciente sin ficha para vincular.</p>
            @else
                <p class="texto-suave">Esta ficha no tiene cuenta de usuario. Si la persona ya tiene una cuenta con el rol Paciente que todavía no tiene ficha (la creó el administrador), vincúlela aquí: conservará su historia clínica y sus citas.</p>
                <form method="POST" action="{{ route('pacientes.vincular-cuenta', $paciente) }}" data-validar novalidate
                      onsubmit="return confirm('¿Vincular esta ficha con la cuenta elegida? La persona verá desde esa cuenta su perfil y sus citas.')">
                    @csrf
                    <div class="formulario">
                        <x-seleccion nombre="usuario_id" etiqueta="Cuenta con rol Paciente sin ficha" vacio="— Seleccione una cuenta —" required
                                     :opciones="$cuentasSinFicha->mapWithKeys(fn ($c) => [$c->id => $c->nombre_usuario.' · '.$c->correo.($c->estado ? '' : ' (inactiva)')])->all()" />
                    </div>
                    <div class="acciones"><button type="submit" class="btn btn-primario"><x-icono nombre="check" /> Vincular cuenta</button></div>
                </form>
            @endif
        </div>
    </div>
@endsection
