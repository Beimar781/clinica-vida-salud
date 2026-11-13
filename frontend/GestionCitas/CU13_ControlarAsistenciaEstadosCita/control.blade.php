{{-- @CU CU-13 @RF RF13 @CI CIEstadoCita (mostrarAgenda, elegirAccion, mostrarResultado) @CC CCEstadoCita @CE CECita
     Pasos 1 a 3 y 6 del flujo: EstadoCitaController::index() muestra la agenda del día con el estado de cada cita y las acciones que el
     usuario puede hacer (confirmar, cobrar, atendida, inasistencia y, solo el administrador, corregir); cambiar() aplica la acción. --}}
@extends('layouts.app')

@section('titulo', 'Control de citas')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/gestion-citas.css')">
@endpush

@push('scripts')
    <script src="@recurso('js/modulos/gestion-citas.js')" defer></script>
@endpush

@php
    use App\Modules\GestionCitas\Compartido\TransicionesCita;
    $fechaTexto = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $fecha);
    $filtros = ['volver_fecha' => $fecha, 'volver_estado' => $estado, 'volver_medico' => $medicoId];
    $esAdmin = $usuario->nombresRoles()->contains(\App\Models\Rol::ADMINISTRADOR);
@endphp

@section('contenido')
    <div class="encabezado-pagina">
        <h1>Control de citas</h1>
    </div>

    @if (session('reprogramar') && $veTodas)
        <div class="aviso aviso-info" role="status">
            <x-icono nombre="info" />
            <span>La cita tiene el pago registrado y se conserva. <a href="{{ route('citas.reprogramar', session('reprogramar')) }}"><strong>Reprogramarla ahora (CU-10)</strong></a></span>
        </div>
    @endif

    @if ($sinFichaMedico)
        <div class="aviso aviso-alerta" role="alert"><x-icono nombre="alerta" /> <span>{{ \App\Modules\GestionCitas\CU13_ControlarAsistenciaEstadosCita\EstadoCitaService::MENSAJE_SIN_FICHA_MEDICO }}</span></div>
    @endif

    <div class="buscador" style="margin-bottom:1.25rem">
        <h2>Agenda del día</h2>
        <form method="GET" action="{{ route('control.index') }}" data-validar novalidate>
            <div class="buscador__campos">
                <x-campo nombre="fecha" etiqueta="Fecha" tipo="date" :valor="$fecha" required />
                <x-seleccion nombre="estado" etiqueta="Estado" vacio="Todos" :opciones="\App\Modules\GestionCitas\CU13_ControlarAsistenciaEstadosCita\EstadoCitaService::ESTADOS" :valor="$estado" />
                @if ($veTodas)
                    <x-seleccion nombre="medico" etiqueta="Médico" vacio="Todos los médicos" :opciones="$medicos->mapWithKeys(fn ($m) => [$m->id => $m->nombre_completo])->all()" :valor="$medicoId" />
                @endif
                <div class="campo"><button type="submit" class="btn btn-primario"><x-icono nombre="buscar" /> Ver citas</button></div>
            </div>
        </form>
    </div>

    <div class="tarjeta">
        <div class="tarjeta__encabezado">
            <x-icono nombre="calendario" />
            <h2>{{ ucfirst($fechaTexto->isoFormat('dddd')) }} {{ $fechaTexto->format('d/m/Y') }} · {{ $citas->count() }} {{ $citas->count() === 1 ? 'cita' : 'citas' }}</h2>
        </div>
        <div class="tarjeta__cuerpo">
            <div class="tabla-responsive">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>Hora</th><th>Código</th><th>Paciente</th><th>Médico</th><th>Estado</th><th>Pago</th><th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($citas as $cita)
                            @php
                                $acciones = $servicio->acciones($cita, $usuario);
                                $esperaHora = $servicio->inasistenciaPendienteDeHora($cita, $usuario);
                            @endphp
                            <tr @class(['fila-alerta' => $cita->atendida_sin_cobro || $cita->vencida])>
                                <td class="precio">{{ $cita->hora_inicio_corta }} – {{ $cita->hora_fin_corta }}</td>
                                <td class="precio"><strong>{{ $cita->codigo }}</strong></td>
                                <td>{{ $cita->paciente->nombre_completo }}<br><small class="texto-suave">CI {{ $cita->paciente->ci }}</small></td>
                                <td>{{ $cita->medico->nombre_completo }}<br><small class="texto-suave">{{ $cita->especialidad->nombre }}</small></td>
                                <td><x-estado-cita :estado="$cita->estado" :vencida="$cita->vencida" /></td>
                                <td>
                                    <x-estado-pago :estado="$cita->estado_pago" /><br><small class="texto-suave">{{ $cita->monto_texto }} · {{ $cita->metodo_pago === 'TARJETA' ? 'Tarjeta' : 'Efectivo' }}</small>
                                    @if ($cita->atendida_sin_cobro)<small class="aviso-cobro"><strong>Atendida sin cobrar:</strong> registre el cobro de {{ $cita->monto_texto }}.</small>@endif
                                </td>
                                <td>
                                    <div class="tabla__acciones">
                                        @foreach ($acciones as $accion)
                                            @continue($accion === TransicionesCita::CORREGIR)
                                            <form method="POST" action="{{ route('citas.estado', $cita) }}"
                                                  @if ($accion === 'atendida' && $cita->cobro_pendiente) onsubmit="return confirm('El pago de esta cita está pendiente. ¿Marcarla como atendida de todos modos? Recepción deberá registrar el cobro.')" @endif>
                                                @csrf
                                                <input type="hidden" name="accion" value="{{ $accion }}">
                                                @foreach ($filtros as $nombre => $valor)<input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">@endforeach
                                                <button type="submit" @class(['btn', 'btn-sm', 'btn-primario' => in_array($accion, ['confirmar', 'cobrar', 'atendida']), 'btn-secundario' => $accion === 'inasistencia'])>
                                                    <x-icono :nombre="['confirmar' => 'check', 'cobrar' => 'documento', 'atendida' => 'corazon', 'inasistencia' => 'bloquear'][$accion]" /> {{ TransicionesCita::TEXTOS[$accion] }}
                                                </button>
                                            </form>
                                        @endforeach
                                        @if (in_array('atendida', $acciones, true) && $cita->cobro_pendiente)
                                            <small class="aviso-cobro">Pago pendiente: al marcarla atendida, recepción deberá cobrarla.</small>
                                        @endif
                                        @include('gestioncitas::CU22_EnviarRecordatoriosCitas.boton-recordatorio', ['cita' => $cita])
                                        @if ($esperaHora)
                                            <button type="button" class="btn btn-secundario btn-sm" disabled title="La inasistencia se registra a partir de la hora de la cita ({{ $cita->hora_inicio_corta }}).">
                                                <x-icono nombre="bloquear" /> Inasistencia (desde las {{ $cita->hora_inicio_corta }})
                                            </button>
                                        @endif
                                        @if (in_array(TransicionesCita::CORREGIR, $acciones, true))
                                            <details class="corregir-estado">
                                                <summary class="btn btn-secundario btn-sm"><x-icono nombre="editar" /> Corregir estado</summary>
                                                <form method="POST" action="{{ route('citas.estado', $cita) }}" class="corregir-estado__form" data-validar novalidate>
                                                    @csrf
                                                    <input type="hidden" name="accion" value="corregir">
                                                    @foreach ($filtros as $nombre => $valor)<input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">@endforeach
                                                    <label for="estado-{{ $cita->id }}">Nuevo estado</label>
                                                    <select id="estado-{{ $cita->id }}" name="estado_nuevo" required>
                                                        <option value="">— Elija —</option>
                                                        @foreach (\App\Modules\GestionCitas\CU13_ControlarAsistenciaEstadosCita\EstadoCitaService::ESTADOS as $valor => $texto)
                                                            @continue($valor === $cita->estado)
                                                            <option value="{{ $valor }}">{{ $texto }}</option>
                                                        @endforeach
                                                    </select>
                                                    <label for="just-{{ $cita->id }}">Justificación (obligatoria, queda en la bitácora)</label>
                                                    <textarea id="just-{{ $cita->id }}" name="justificacion" rows="2" maxlength="150" required></textarea>
                                                    <button type="submit" class="btn btn-peligro btn-sm"><x-icono nombre="check" /> Aplicar corrección</button>
                                                </form>
                                            </details>
                                        @endif
                                        @if ($acciones === [] && ! $esperaHora)
                                            <span class="texto-suave">—</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="vacio">No hay citas para la fecha seleccionada.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
