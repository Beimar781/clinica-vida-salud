{{-- @CU CU-15 @RF RF15 @CI CIHistorial (buscarPaciente, aplicarFiltros, mostrarHistorial) @CC CCHistorial @CE CEPaciente, CECita
     Pasos 1 a 4 del flujo: HistorialController::index() busca al paciente por CI o nombre (recepción y administrador) o toma el de la
     cuenta (paciente: «Mis citas») y muestra su historial con los filtros de estado y de fechas. Solo consulta. --}}
@extends('layouts.app')

@section('titulo', $esPersonal ? 'Historial de citas' : 'Mis citas')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/gestion-pacientes.css')">
    <link rel="stylesheet" href="@recurso('css/modulos/gestion-citas.css')">{{-- filtros (.buscador) y tablas de citas --}}
@endpush

@section('contenido')
    @php
        $ruta = fn (array $extra = []) => route('historial.index', array_filter(['paciente' => $paciente?->id, 'estado' => $filtro, 'desde' => $desde, 'hasta' => $hasta] + $extra, fn ($v) => filled($v)));
    @endphp

    <div class="encabezado-pagina">
        <h1>{{ $esPersonal ? 'Historial de citas' : 'Mis citas' }}</h1>
        @unless ($esPersonal)
            <a class="btn btn-primario" href="{{ route('reservas.crear') }}"><x-icono nombre="mas" /> Reservar cita</a>
        @endunless
    </div>

    <x-aviso-sin-ficha />

    {{-- Paso 2: la recepción busca al paciente por CI o nombre --}}
    @if ($esPersonal)
        <div class="tarjeta">
            <div class="tarjeta__encabezado"><x-icono nombre="paciente" /><h2>Paciente</h2></div>
            <div class="tarjeta__cuerpo">
                @if ($paciente)
                    <p class="sin-margen">
                        <strong>{{ $paciente->nombre_completo }}</strong> · CI {{ $paciente->ci }} · {{ $paciente->codigo }}
                        <span class="etiqueta-estado {{ $paciente->estado ? 'estado-activo' : 'estado-inactivo' }}">{{ $paciente->estado ? 'Activo' : 'Inactivo' }}</span>
                        <a class="btn btn-secundario btn-sm" href="{{ route('historial.index') }}">Cambiar paciente</a>
                    </p>
                @else
                    <form method="GET" action="{{ route('historial.index') }}" class="herramientas" role="search">
                        <div class="campo">
                            <label for="buscar">Buscar paciente por CI o nombre</label>
                            <input id="buscar" type="search" name="q" value="{{ $buscar }}" maxlength="80" placeholder="Ej.: 6543210 o Rojas" autofocus>
                        </div>
                        <button type="submit" class="btn btn-primario"><x-icono nombre="buscar" /> Buscar</button>
                    </form>

                    @if ($buscar !== '')
                        @if ($encontrados->isEmpty())
                            <p class="vacio">No se encontraron pacientes con «{{ $buscar }}».</p>
                        @else
                            <div class="tabla-responsive">
                                <table class="tabla">
                                    <thead><tr><th>Código</th><th>CI</th><th>Paciente</th><th>Estado</th><th></th></tr></thead>
                                    <tbody>
                                        @foreach ($encontrados as $encontrado)
                                            <tr>
                                                <td>{{ $encontrado->codigo }}</td>
                                                <td>{{ $encontrado->ci }}</td>
                                                <td><strong>{{ $encontrado->nombre_completo }}</strong></td>
                                                <td><span class="etiqueta-estado {{ $encontrado->estado ? 'estado-activo' : 'estado-inactivo' }}">{{ $encontrado->estado ? 'Activo' : 'Inactivo' }}</span></td>
                                                <td><a class="btn btn-primario btn-sm" href="{{ route('historial.index', ['paciente' => $encontrado->id]) }}"><x-icono nombre="historial" /> Ver historial</a></td>
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
    @endif

    {{-- Pasos 3 y 4: filtros y citas --}}
    @if ($paciente)
        <div class="buscador" style="margin:1.25rem 0">
            <h2>Filtros</h2>
            <form method="GET" action="{{ route('historial.index') }}" data-validar novalidate>
                @if ($esPersonal)<input type="hidden" name="paciente" value="{{ $paciente->id }}">@endif
                <div class="buscador__campos">
                    <x-seleccion nombre="estado" etiqueta="Estado" vacio="Todos" :opciones="\App\Modules\GestionPacientes\CU15_ConsultarHistorialCitasPaciente\HistorialCitasService::opcionesFiltro()" :valor="$filtro" />
                    <x-campo nombre="desde" etiqueta="Desde" tipo="date" :valor="$desde" />
                    <x-campo nombre="hasta" etiqueta="Hasta" tipo="date" :valor="$hasta" />
                    <div class="campo"><button type="submit" class="btn btn-primario"><x-icono nombre="buscar" /> Filtrar</button></div>
                </div>
            </form>
        </div>

        @if ($errorRango)
            <div class="aviso aviso-alerta" role="alert"><x-icono nombre="alerta" /> <span>{{ $errorRango }}</span></div>
        @elseif ($citas)
            <div class="tarjeta">
                <div class="tarjeta__encabezado"><x-icono nombre="historial" /><h2>{{ $citas->total() }} {{ $citas->total() === 1 ? 'cita' : 'citas' }}</h2></div>
                <div class="tarjeta__cuerpo">
                    <div class="tabla-responsive">
                        <table class="tabla">
                            <thead>
                                <tr><th>Fecha y hora</th><th>Código</th><th>Médico</th><th>Especialidad</th><th>Estado</th><th>Registrada</th><th>Pago</th><th>Acciones</th></tr>
                            </thead>
                            <tbody>
                                @forelse ($citas as $cita)
                                    <tr>
                                        <td class="precio">{{ $cita->fecha->format('d/m/Y') }}<br><small class="texto-suave">{{ $cita->hora_inicio_corta }} – {{ $cita->hora_fin_corta }}</small></td>
                                        <td class="precio"><strong>{{ $cita->codigo }}</strong></td>
                                        <td>{{ $cita->medico->nombre_completo }}</td>
                                        <td>{{ $cita->especialidad->nombre }}</td>
                                        <td><x-estado-cita :estado="$cita->estado" :vencida="$cita->vencida" /></td>
                                        <td class="precio">{{ $cita->created_at->format('d/m/Y') }}<br><small class="texto-suave">{{ $cita->created_at->format('H:i') }}</small></td>
                                        <td><x-estado-pago :estado="$cita->estado_pago" /><br><small class="texto-suave">{{ $cita->monto_texto }}</small></td>
                                        <td>
                                            @if ($cancelaciones->puedeCancelarAhora($cita, $usuario))
                                                <a class="btn btn-peligro btn-sm" href="{{ route('citas.cancelar', $cita) }}"><x-icono nombre="bloquear" /> Cancelar</a>
                                            @elseif ($cancelaciones->puedeCancelarse($cita) && $cita->ya_paso)
                                                <small class="texto-suave">Esta cita ya pasó. Si no pudo asistir, comuníquese con recepción.</small>
                                            @elseif ($cancelaciones->puedeCancelarse($cita))
                                                <small class="texto-suave">Faltan menos de 2 h: comunícate con recepción para cancelarla.</small>
                                            @else
                                                <span class="texto-suave">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="vacio">
                                        {{ $filtro !== '' || $desde || $hasta ? 'No hay citas con esos filtros.' : \App\Modules\GestionPacientes\CU15_ConsultarHistorialCitasPaciente\HistorialCitasService::MENSAJE_SIN_CITAS }}
                                    </td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                {{ $citas->links() }}
            </div>
        @endif
    @elseif ($esPersonal && $buscar === '')
        <p class="vacio">Busque a un paciente por CI o nombre para ver el historial de sus citas.</p>
    @endif
@endsection
