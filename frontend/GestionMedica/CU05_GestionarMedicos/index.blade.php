{{-- @CU CU-05 @RF RF05 @CI CIMedicos (mostrarListado, mostrarResultado) @CC CCMedicos @CE CEMedico
     Pasos 1 y 2 del flujo: MedicosController::index(). --}}
@extends('layouts.app')

@section('titulo', 'Médicos')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/gestion-medica.css')">
@endpush

@section('contenido')
    <div class="encabezado-pagina">
        <h1>Médicos</h1>
        <a class="btn btn-primario" href="{{ route('medicos.crear') }}"><x-icono nombre="mas" /> Nuevo médico</a>
    </div>

    <div class="tarjeta">
        <div class="tarjeta__encabezado"><x-icono nombre="medico" /><h2>Listado de médicos</h2></div>
        <div class="tarjeta__cuerpo">
            <form method="GET" action="{{ route('medicos.index') }}" class="herramientas" role="search">
                <div class="campo">
                    <label for="buscar">Buscar por nombre, código, registro o especialidad</label>
                    <input id="buscar" type="search" name="q" value="{{ $buscar }}" maxlength="80" placeholder="Ej.: pediatria o MED-0001">
                </div>
                <button type="submit" class="btn btn-secundario"><x-icono nombre="buscar" /> Buscar</button>
                @if ($buscar !== '')
                    <a class="btn btn-secundario" href="{{ route('medicos.index') }}">Limpiar</a>
                @endif
            </form>

            <div class="tabla-responsive">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Médico</th>
                            <th>N.º de registro</th>
                            <th>Especialidades</th>
                            <th>Ubicación</th>
                            <th>Marca atendidas</th>
                            <th>Cuenta</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($medicos as $medico)
                            <tr>
                                <td>{{ $medico->codigo }}</td>
                                <td><strong>{{ $medico->nombre_completo }}</strong>@if ($medico->telefono)<br><small class="texto-suave">{{ $medico->telefono }}</small>@endif</td>
                                <td>{{ $medico->nro_registro }}</td>
                                <td>@foreach ($medico->especialidades as $especialidad)<span class="chip">{{ $especialidad->nombre }}</span>@endforeach</td>
                                <td>{{ $medico->ubicacion ?? '—' }}</td>
                                <td>{{ $medico->puede_marcar_atendida ? 'Sí' : 'No' }}</td>
                                <td>{{ $medico->usuario?->nombre_usuario ?? 'Sin cuenta' }}</td>
                                <td><span class="etiqueta-estado {{ $medico->estado ? 'estado-activo' : 'estado-inactivo' }}">{{ $medico->estado ? 'Activo' : 'Inactivo' }}</span></td>
                                <td>
                                    <div class="tabla__acciones">
                                        <a class="btn btn-secundario btn-sm" href="{{ route('medicos.editar', $medico) }}"><x-icono nombre="editar" /> Editar</a>
                                        @if ($medico->estado)
                                            <form method="POST" action="{{ route('medicos.desactivar', $medico) }}"
                                                  data-confirmar="¿Desactivar a {{ $medico->nombre_completo }}? No recibirá nuevas citas; su historial se conserva.">
                                                @csrf
                                                <button type="submit" class="btn btn-peligro btn-sm"><x-icono nombre="bloquear" /> Desactivar</button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('medicos.activar', $medico) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-primario btn-sm"><x-icono nombre="check" /> Activar</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="vacio">No se encontraron médicos{{ $buscar !== '' ? ' con «'.$buscar.'»' : '' }}.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        {{ $medicos->links() }}
    </div>
@endsection
