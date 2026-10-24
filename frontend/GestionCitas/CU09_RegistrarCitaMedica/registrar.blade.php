{{-- @CU CU-09 @RF RF09 @CI CIRegistrarCita (buscarPaciente, mostrarHorarios, confirmarCita) @CC CCRegistrarCita @CE CEPaciente, CECita, CEMedico
     Pasos 1 a 6 del flujo: RegistrarCitaController::index() busca al paciente, ofrece especialidad, médico y fecha y muestra los
     horarios libres (CU-08); guardar() confirma. Si el horario ya fue ocupado, vuelve aquí con el aviso y los horarios actualizados. --}}
@extends('layouts.app')

@section('titulo', 'Registrar cita')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/gestion-citas.css')">
@endpush

@push('scripts')
    <script src="@recurso('js/modulos/gestion-citas.js')" defer></script>
@endpush

@section('contenido')
    <div class="encabezado-pagina">
        <h1>Registrar cita médica</h1>
    </div>

    {{-- Paso 1: paciente --}}
    <div class="tarjeta">
        <div class="tarjeta__encabezado"><x-icono nombre="paciente" /><h2>1. Paciente</h2></div>
        <div class="tarjeta__cuerpo">
            @if ($paciente)
                <p class="sin-margen">
                    <strong>{{ $paciente->nombre_completo }}</strong> · CI {{ $paciente->ci }} · {{ $paciente->codigo }}
                    <span class="etiqueta-estado {{ $paciente->estado ? 'estado-activo' : 'estado-inactivo' }}">{{ $paciente->estado ? 'Activo' : 'Inactivo' }}</span>
                    <a class="btn btn-secundario btn-sm" href="{{ route('citas.registrar') }}">Cambiar paciente</a>
                </p>
                @unless ($paciente->estado)
                    <div class="aviso aviso-alerta" role="alert" style="margin-top:1rem"><x-icono nombre="alerta" /> <span>El paciente está inactivo: no se le pueden registrar citas. Actívelo en la sección «Pacientes».</span></div>
                @endunless
            @else
                <form method="GET" action="{{ route('citas.registrar') }}" class="herramientas" role="search">
                    <div class="campo">
                        <label for="buscar">Buscar paciente por CI o nombre</label>
                        <input id="buscar" type="search" name="q" value="{{ $busqueda }}" maxlength="80" placeholder="Ej.: 6543210 o Rojas" autofocus>
                    </div>
                    <button type="submit" class="btn btn-primario"><x-icono nombre="buscar" /> Buscar</button>
                </form>

                @if ($busqueda !== '')
                    @if ($pacientes->isEmpty())
                        <p class="vacio">No se encontraron pacientes con «{{ $busqueda }}». Regístrelo en la sección «Pacientes».</p>
                    @else
                        <div class="tabla-responsive">
                            <table class="tabla">
                                <thead><tr><th>Código</th><th>CI</th><th>Paciente</th><th>Estado</th><th></th></tr></thead>
                                <tbody>
                                    @foreach ($pacientes as $encontrado)
                                        <tr>
                                            <td>{{ $encontrado->codigo }}</td>
                                            <td>{{ $encontrado->ci }}</td>
                                            <td><strong>{{ $encontrado->nombre_completo }}</strong></td>
                                            <td><span class="etiqueta-estado {{ $encontrado->estado ? 'estado-activo' : 'estado-inactivo' }}">{{ $encontrado->estado ? 'Activo' : 'Inactivo' }}</span></td>
                                            <td>
                                                @if ($encontrado->estado)
                                                    <a class="btn btn-primario btn-sm" href="{{ route('citas.registrar', ['paciente' => $encontrado->id]) }}"><x-icono nombre="check" /> Seleccionar</a>
                                                @else
                                                    <span class="texto-suave">No se puede registrar cita</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @endif
            @endif
        </div>
    </div>

    {{-- Pasos 2 a 4: especialidad, médico y fecha --}}
    @if ($paciente?->estado)
        <div class="buscador" style="margin-bottom:1.25rem">
            <h2>2. Especialidad, médico y fecha</h2>
            <form method="GET" action="{{ route('citas.registrar') }}" data-autoenviar>
                <input type="hidden" name="paciente" value="{{ $paciente->id }}">
                <div class="buscador__campos">
                    <x-seleccion nombre="especialidad" etiqueta="Especialidad" vacio="— Seleccione —" :opciones="$especialidades->pluck('nombre', 'id')->all()" :valor="$especialidad?->id" />
                    <x-seleccion nombre="medico" etiqueta="Médico" vacio="{{ $especialidad ? '— Seleccione —' : 'Primero elija la especialidad' }}" :disabled="! $especialidad"
                                 :opciones="$medicos->mapWithKeys(fn ($m) => [$m->id => $m->nombre_completo.($m->ubicacion ? ' · '.$m->ubicacion : '')])->all()" :valor="$medico?->id" />
                    <x-campo nombre="fecha" etiqueta="Fecha" tipo="date" :valor="$fecha" />
                    <div class="campo"><button type="submit" class="btn btn-primario"><x-icono nombre="buscar" /> Ver horarios libres</button></div>
                </div>
            </form>
        </div>
    @endif

    {{-- Pasos 4 a 6: horarios libres (CU-08) y confirmación --}}
    @if ($resultado)
        <div class="tarjeta">
            <div class="tarjeta__encabezado">
                <x-icono nombre="reloj" />
                <h2>3. Horario · {{ $medico->nombre_completo }} · {{ \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $fecha)->format('d/m/Y') }}</h2>
            </div>
            <div class="tarjeta__cuerpo">
                @if ($resultado->hayLibres())
                    <form method="POST" action="{{ route('citas.guardar') }}" data-validar novalidate>
                        @csrf
                        <input type="hidden" name="paciente_id" value="{{ $paciente->id }}">
                        <input type="hidden" name="especialidad_id" value="{{ $especialidad->id }}">
                        <input type="hidden" name="medico_id" value="{{ $medico->id }}">
                        <input type="hidden" name="fecha" value="{{ $fecha }}">

                        @include('gestioncitas::Compartido.horarios')

                        <div class="formulario" style="margin-top:1rem">
                            <x-seleccion nombre="modalidad" etiqueta="Modalidad" :opciones="['PRESENCIAL' => 'Presencial', 'VIRTUAL' => 'Virtual']" valor="PRESENCIAL" required />
                            <x-areatexto nombre="motivo" etiqueta="Motivo breve de la consulta (opcional)" maxlength="200" rows="2" />
                        </div>

                        <div class="acciones">
                            <button type="submit" class="btn btn-primario"><x-icono nombre="check" /> Confirmar cita</button>
                        </div>
                    </form>
                @else
                    <div class="aviso aviso-alerta" role="status"><x-icono nombre="alerta" /> <span>{{ $resultado->mensaje }}</span></div>
                @endif
            </div>
        </div>
    @endif
@endsection
