{{-- @CU CU-06 @RF RF06 @CI CIHorarios (mostrarAgenda, enviarDatos, mostrarResultado) @CC CCHorarios @CE CEHorario, CEMedico
     Paso 7 del flujo: DiasSinAtencionController::index() muestra el listado y el formulario; guardar() registra el día y
     la redirección lista las citas ya programadas que quedan afectadas (?afectadas=). --}}
@extends('layouts.app')

@section('titulo', 'Días sin atención')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/gestion-medica.css')">
@endpush

@section('contenido')
    <div class="encabezado-pagina">
        <h1>Días sin atención</h1>
        <a class="btn btn-secundario" href="{{ route('horarios.index') }}"><x-icono nombre="reloj" /> Horarios de atención</a>
    </div>

    @if ($errors->has('fecha') && ! old('fecha'))
        <div class="aviso aviso-error" role="alert"><x-icono nombre="alerta" /> <span>{{ $errors->first('fecha') }}</span></div>
    @endif

    <div class="tarjeta">
        <div class="tarjeta__encabezado"><x-icono nombre="mas" /><h2>Registrar feriado o día sin atención</h2></div>
        <div class="tarjeta__cuerpo">
            <form method="POST" action="{{ route('dias-sin-atencion.guardar') }}" data-validar novalidate>
                @csrf
                <div class="formulario">
                    <x-campo nombre="fecha" etiqueta="Fecha" tipo="date" required :min="now()->toDateString()"
                             ayuda="No puede ser una fecha pasada ni una ya registrada." />
                    <x-campo nombre="motivo" etiqueta="Motivo" required maxlength="120" placeholder="Ej.: Feriado nacional" autocomplete="off" />
                </div>
                <div class="acciones">
                    <button type="submit" class="btn btn-primario"><x-icono nombre="check" /> Registrar día sin atención</button>
                </div>
            </form>
        </div>
    </div>

    @if ($seleccionado)
        <div class="tarjeta" id="citas-afectadas">
            <div class="tarjeta__encabezado">
                <x-icono nombre="alerta" />
                <h2>Citas afectadas el {{ $seleccionado->fecha->format('d/m/Y') }} ({{ $seleccionado->motivo }})</h2>
            </div>
            <div class="tarjeta__cuerpo">
                @if ($citasAfectadas->isEmpty())
                    <p class="vacio">No hay citas programadas ni confirmadas ese día.</p>
                @else
                    <div class="aviso aviso-alerta"><x-icono nombre="alerta" /> <span>Estas citas ya estaban programadas: deben reprogramarse (el sistema no las modifica).</span></div>
                    <div class="tabla-responsive">
                        <table class="tabla">
                            <thead><tr><th>Código</th><th>Hora</th><th>Paciente</th><th>Médico</th><th>Especialidad</th><th>Estado</th></tr></thead>
                            <tbody>
                                @foreach ($citasAfectadas as $cita)
                                    <tr>
                                        <td>{{ $cita->codigo }}</td>
                                        <td>{{ substr($cita->hora_inicio, 0, 5) }} – {{ substr($cita->hora_fin, 0, 5) }}</td>
                                        <td>{{ $cita->paciente->nombre_completo }}</td>
                                        <td>{{ $cita->medico->nombre_completo }}</td>
                                        <td>{{ $cita->especialidad->nombre }}</td>
                                        <td><x-estado-cita :estado="$cita->estado" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <div class="tarjeta">
        <div class="tarjeta__encabezado"><x-icono nombre="calendario" /><h2>Listado de días sin atención</h2></div>
        <div class="tarjeta__cuerpo">
            <div class="tabla-responsive">
                <table class="tabla">
                    <thead><tr><th>Fecha</th><th>Día</th><th>Motivo</th><th>Citas afectadas</th><th>Estado</th><th>Acciones</th></tr></thead>
                    <tbody>
                        @forelse ($dias as $dia)
                            @php $afectadas = $afectadasPorFecha[$dia->fecha->toDateString()] ?? 0; @endphp
                            <tr>
                                <td><strong>{{ $dia->fecha->format('d/m/Y') }}</strong></td>
                                <td>{{ ucfirst($dia->fecha->isoFormat('dddd')) }}</td>
                                <td>{{ $dia->motivo }}</td>
                                <td>
                                    @if ($afectadas > 0)
                                        <a href="{{ route('dias-sin-atencion.index', ['afectadas' => $dia->id]) }}#citas-afectadas">{{ $afectadas }} {{ $afectadas === 1 ? 'cita' : 'citas' }}</a>
                                    @else
                                        0
                                    @endif
                                </td>
                                <td><span class="etiqueta-estado {{ $dia->estado ? 'estado-activo' : 'estado-inactivo' }}">{{ $dia->estado ? 'Sin atención' : 'Desactivado' }}</span></td>
                                <td>
                                    @if ($dia->estado)
                                        <form method="POST" action="{{ route('dias-sin-atencion.desactivar', $dia) }}"
                                              data-confirmar="¿Desactivar este día sin atención? La fecha volverá a estar disponible en la agenda.">
                                            @csrf
                                            <button type="submit" class="btn btn-peligro btn-sm"><x-icono nombre="bloquear" /> Desactivar</button>
                                        </form>
                                    @elseif ($dia->fecha->gte(today()))
                                        <form method="POST" action="{{ route('dias-sin-atencion.activar', $dia) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-primario btn-sm"><x-icono nombre="check" /> Activar</button>
                                        </form>
                                    @else
                                        <span class="texto-suave">Fecha pasada</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="vacio">Todavía no hay días sin atención registrados.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        {{ $dias->links() }}
    </div>
@endsection
