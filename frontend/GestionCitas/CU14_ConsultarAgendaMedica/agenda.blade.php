{{-- @CU CU-14 @RF RF14 @CI CIAgenda (elegirFiltros, mostrarAgenda) @CC CCAgenda @CE CECita, CEMedico
     Pasos 1 a 5 del flujo: AgendaController::index() muestra los filtros (médico, fecha o rango y estado), las citas ordenadas por hora con su
     estado y motivo (si el usuario tiene permiso), los horarios libres de un solo día y, al abrir una cita, los datos básicos del paciente. --}}
@extends('layouts.app')

@section('titulo', 'Agenda médica')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/gestion-citas.css')">
@endpush

@push('scripts')
    <script src="@recurso('js/modulos/gestion-citas.js')" defer></script>
@endpush

@section('contenido')
    @php
        use App\Modules\GestionCitas\CU14_ConsultarAgendaMedica\AgendaService;
    @endphp

    <div class="encabezado-pagina">
        <h1>Agenda médica</h1>
    </div>

    @if ($sinFichaMedico)
        <div class="aviso aviso-alerta" role="alert"><x-icono nombre="alerta" /> <span>{{ AgendaService::MENSAJE_SIN_FICHA_MEDICO }}</span></div>
    @endif

    <div class="buscador" style="margin-bottom:1.25rem">
        <h2>{{ $veTodos ? 'Médico, fechas y estado' : 'Mi agenda: fechas y estado' }}</h2>
        <form method="GET" action="{{ route('agenda.index') }}" data-validar novalidate>
            <div class="buscador__campos">
                @if ($veTodos)
                    <x-seleccion nombre="medico" etiqueta="Médico" vacio="— Seleccione un médico —" required
                                 :opciones="$medicos->mapWithKeys(fn ($m) => [$m->id => $m->nombre_completo.' ('.$m->especialidades->pluck('nombre')->implode(', ').')'.($m->estado ? '' : ' · inactivo')])->all()"
                                 :valor="$medico?->id" />
                @elseif ($medico)
                    <div class="campo">
                        <label>Médico</label>
                        <p class="sin-margen"><strong>{{ $medico->nombre_completo }}</strong><br><small class="texto-suave">{{ $medico->especialidades->pluck('nombre')->implode(', ') ?: 'Sin especialidad' }}</small></p>
                    </div>
                @endif
                <x-campo nombre="desde" etiqueta="Fecha (o desde)" tipo="date" :valor="$desde" required />
                <x-campo nombre="hasta" etiqueta="Hasta (opcional)" tipo="date" :valor="$hasta" ayuda="Rango de hasta 31 días." />
                <x-seleccion nombre="estado" etiqueta="Estado" vacio="Todos" :opciones="\App\Modules\GestionPacientes\CU15_ConsultarHistorialCitasPaciente\HistorialCitasService::opcionesFiltro()" :valor="$filtro" />
                <div class="campo"><button type="submit" class="btn btn-primario"><x-icono nombre="buscar" /> Ver agenda</button></div>
            </div>
        </form>
    </div>

    @if ($errorRango)
        <div class="aviso aviso-alerta" role="alert"><x-icono nombre="alerta" /> <span>{{ $errorRango }}</span></div>
    @elseif ($veTodos && ! $medico)
        <p class="vacio">Elija un médico para ver su agenda.</p>
    @elseif ($citas !== null)
        {{-- Resumen: pendientes, atendidas y canceladas --}}
        <div class="resumen-agenda" aria-label="Resumen de la agenda">
            <span class="resumen-agenda__dato"><strong>{{ $resumen['pendientes'] }}</strong> pendientes</span>
            <span class="resumen-agenda__dato"><strong>{{ $resumen['atendidas'] }}</strong> atendidas</span>
            <span class="resumen-agenda__dato"><strong>{{ $resumen['canceladas'] }}</strong> canceladas</span>
            <span class="resumen-agenda__dato"><strong>{{ $resumen['inasistencias'] }}</strong> con inasistencia</span>
        </div>

        {{-- Horarios libres (solo cuando se consulta un solo día) --}}
        @if ($libres)
            <div class="tarjeta">
                <div class="tarjeta__encabezado"><x-icono nombre="reloj" /><h2>Horarios libres · {{ \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $desde)->format('d/m/Y') }}</h2></div>
                <div class="tarjeta__cuerpo">
                    @if ($libres->hayLibres())
                        <div class="bloques">
                            @foreach ($libres->libres as $bloque)
                                <span class="bloque bloque--fijo"><span>{{ $bloque['inicio'] }} – {{ $bloque['fin'] }}</span></span>
                            @endforeach
                        </div>
                    @else
                        <p class="sin-margen texto-suave">{{ $libres->mensaje }}</p>
                    @endif
                </div>
            </div>
        @endif

        @forelse ($porDia as $dia => $delDia)
            @php $fechaDia = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $dia); @endphp
            <div class="tarjeta">
                <div class="tarjeta__encabezado">
                    <x-icono nombre="calendario" />
                    <h2>{{ ucfirst($fechaDia->isoFormat('dddd')) }} {{ $fechaDia->format('d/m/Y') }} · {{ $delDia->count() }} {{ $delDia->count() === 1 ? 'cita' : 'citas' }}</h2>
                </div>
                <div class="tarjeta__cuerpo">
                    <div class="tabla-responsive">
                        <table class="tabla">
                            <thead><tr><th>Hora</th><th>Código</th><th>Paciente</th><th>Especialidad</th><th>Estado</th><th>Motivo</th><th>Acciones</th></tr></thead>
                            <tbody>
                                @foreach ($delDia as $cita)
                                    @php $paciente = $cita->paciente; @endphp
                                    <tr class="cita-{{ strtolower($cita->estado) }}">
                                        <td class="precio">{{ $cita->hora_inicio_corta }} – {{ $cita->hora_fin_corta }}</td>
                                        <td class="precio"><strong>{{ $cita->codigo }}</strong></td>
                                        <td>
                                            <details class="paciente-agenda">
                                                <summary>{{ $paciente->nombre_completo }}</summary>
                                                <dl class="paciente-agenda__datos">
                                                    <div><dt>Edad</dt><dd>{{ AgendaService::edad($paciente) }} años</dd></div>
                                                    <div><dt>Sexo</dt><dd>{{ AgendaService::textoSexo($paciente->sexo) }}</dd></div>
                                                    @if ($servicio->puedeVerContacto($usuario, $paciente))
                                                        <div><dt>Teléfono</dt><dd>{{ $paciente->telefono }}</dd></div>
                                                        @if ($paciente->correo)<div><dt>Correo</dt><dd>{{ $paciente->correo }}</dd></div>@endif
                                                    @else
                                                        <div><dt>Contacto</dt><dd class="texto-suave">El paciente mantiene su teléfono y correo privados.</dd></div>
                                                    @endif
                                                </dl>
                                            </details>
                                        </td>
                                        <td>{{ $cita->especialidad->nombre }}</td>
                                        <td><x-estado-cita :estado="$cita->estado" /></td>
                                        <td>
                                            @if ($servicio->puedeVerMotivo($usuario, $cita))
                                                {{ $cita->motivo ?: '—' }}
                                            @else
                                                <span class="texto-suave">Sin permiso</span>
                                            @endif
                                        </td>
                                        <td>
                                            @php $accionConsulta = Route::has('consultas.mostrar') ? $consultas->accion($cita, $usuario) : null; @endphp
                                            @if ($accionConsulta === 'registrar')
                                                <a class="btn btn-primario btn-sm" href="{{ route('consultas.mostrar', $cita) }}"><x-icono nombre="corazon" /> Registrar consulta</a>
                                            @elseif ($accionConsulta === 'ver')
                                                <a class="btn btn-secundario btn-sm" href="{{ route('consultas.mostrar', $cita) }}"><x-icono nombre="documento" /> Ver consulta</a>
                                            @else
                                                <span class="texto-suave">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @empty
            <div class="tarjeta"><div class="tarjeta__cuerpo"><p class="vacio sin-margen">{{ AgendaService::MENSAJE_SIN_CITAS }}</p></div></div>
        @endforelse
    @endif
@endsection
