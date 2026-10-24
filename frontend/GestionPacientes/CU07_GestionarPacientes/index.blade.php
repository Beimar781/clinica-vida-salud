{{-- @CU CU-07 @RF RF07 @CI CIPacientes (mostrarListado, mostrarResultado) @CC CCPacientes (buscar) @CE CEPaciente
     Pasos 1 y 2 del flujo: PacientesController::index(). --}}
@extends('layouts.app')

@section('titulo', 'Pacientes')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/gestion-pacientes.css')">
@endpush

@section('contenido')
    <div class="encabezado-pagina">
        <h1>Pacientes</h1>
        <a class="btn btn-primario" href="{{ route('pacientes.crear') }}"><x-icono nombre="mas" /> Nuevo paciente</a>
    </div>

    <div class="tarjeta">
        <div class="tarjeta__encabezado"><x-icono nombre="paciente" /><h2>Listado de pacientes</h2></div>
        <div class="tarjeta__cuerpo">
            <form method="GET" action="{{ route('pacientes.index') }}" class="herramientas" role="search">
                <div class="campo">
                    <label for="buscar">Buscar por CI, nombre o apellido</label>
                    <input id="buscar" type="search" name="q" value="{{ $buscar }}" maxlength="80" placeholder="Ej.: 6543 o Rojas">
                </div>
                <button type="submit" class="btn btn-secundario"><x-icono nombre="buscar" /> Buscar</button>
                @if ($buscar !== '')
                    <a class="btn btn-secundario" href="{{ route('pacientes.index') }}">Limpiar</a>
                @endif
            </form>

            <div class="tabla-responsive">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>CI</th>
                            <th>Paciente</th>
                            <th>Nacimiento</th>
                            <th>Teléfono</th>
                            <th>Citas</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($pacientes as $paciente)
                            <tr>
                                <td>{{ $paciente->codigo }}</td>
                                <td>{{ $paciente->ci }}</td>
                                <td><strong>{{ $paciente->nombre_completo }}</strong>@if ($paciente->correo)<br><small class="texto-suave">{{ $paciente->correo }}</small>@endif</td>
                                <td>{{ $paciente->fecha_nacimiento->format('d/m/Y') }} <small class="texto-suave">({{ $paciente->fecha_nacimiento->age }} años)</small></td>
                                <td>{{ $paciente->telefono }}</td>
                                <td>{{ $paciente->citas_count }}</td>
                                <td><span class="etiqueta-estado {{ $paciente->estado ? 'estado-activo' : 'estado-inactivo' }}">{{ $paciente->estado ? 'Activo' : 'Inactivo' }}</span></td>
                                <td>
                                    <div class="tabla__acciones">
                                        <a class="btn btn-secundario btn-sm" href="{{ route('pacientes.editar', $paciente) }}"><x-icono nombre="editar" /> Editar</a>
                                        @if ($paciente->estado)
                                            <form method="POST" action="{{ route('pacientes.desactivar', $paciente) }}"
                                                  data-confirmar="¿Desactivar a {{ $paciente->nombre_completo }}? Se conservan su historial de citas y su historia clínica.">
                                                @csrf
                                                <button type="submit" class="btn btn-peligro btn-sm"><x-icono nombre="bloquear" /> Desactivar</button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('pacientes.activar', $paciente) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-primario btn-sm"><x-icono nombre="check" /> Activar</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="vacio">No se encontraron pacientes{{ $buscar !== '' ? ' con «'.$buscar.'»' : '' }}.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        {{ $pacientes->links() }}
    </div>
@endsection
