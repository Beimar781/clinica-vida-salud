{{-- @CU CU-10 @RF RF10 @CI CIReprogramarCita (buscarCita) @CC CCReprogramarCita (buscar) @CE CECita
     Paso 1 del flujo: ReprogramarCitaController::index() busca la cita por código, CI o nombre del paciente. --}}
@extends('layouts.app')

@section('titulo', 'Citas')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/gestion-citas.css')">
@endpush

@section('contenido')
    <div class="encabezado-pagina">
        <h1>Citas</h1>
        <a class="btn btn-primario" href="{{ route('citas.registrar') }}"><x-icono nombre="mas" /> Registrar cita</a>
    </div>

    <div class="tarjeta">
        <div class="tarjeta__encabezado"><x-icono nombre="calendario" /><h2>{{ $buscar === '' ? 'Citas vigentes (programadas y confirmadas)' : 'Resultado de la búsqueda' }}</h2></div>
        <div class="tarjeta__cuerpo">
            <form method="GET" action="{{ route('citas.index') }}" class="herramientas" role="search">
                <div class="campo">
                    <label for="buscar">Buscar por código de la cita, CI o nombre del paciente</label>
                    <input id="buscar" type="search" name="q" value="{{ $buscar }}" maxlength="80" placeholder="Ej.: CM-0005, 6543210 o Rojas">
                </div>
                <button type="submit" class="btn btn-secundario"><x-icono nombre="buscar" /> Buscar</button>
                @if ($buscar !== '')
                    <a class="btn btn-secundario" href="{{ route('citas.index') }}">Limpiar</a>
                @endif
            </form>

            <div class="tabla-responsive">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Paciente</th>
                            <th>Médico</th>
                            <th>Fecha</th>
                            <th>Horario</th>
                            <th>Modalidad</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($citas as $cita)
                            <tr>
                                <td><strong>{{ $cita->codigo }}</strong></td>
                                <td>{{ $cita->paciente->nombre_completo }}<br><small class="texto-suave">CI {{ $cita->paciente->ci }}</small></td>
                                <td>{{ $cita->medico->nombre_completo }}<br><small class="texto-suave">{{ $cita->especialidad->nombre }}</small></td>
                                <td>{{ $cita->fecha->format('d/m/Y') }}</td>
                                <td>{{ $cita->hora_inicio_corta }} – {{ $cita->hora_fin_corta }}</td>
                                <td>{{ $cita->modalidad === 'VIRTUAL' ? 'Virtual' : 'Presencial' }}</td>
                                <td><x-estado-cita :estado="$cita->estado" /></td>
                                <td>
                                    @if ($servicio->puedeReprogramarse($cita))
                                        <a class="btn btn-primario btn-sm" href="{{ route('citas.reprogramar', $cita) }}"><x-icono nombre="calendario" /> Reprogramar</a>
                                    @else
                                        <a class="btn btn-secundario btn-sm" href="{{ route('citas.reprogramar', $cita) }}" title="Ver los datos de la cita">Ver</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="vacio">{{ $buscar === '' ? 'No hay citas vigentes.' : 'No se encontraron citas con «'.$buscar.'».' }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        {{ $citas->links() }}
    </div>
@endsection
