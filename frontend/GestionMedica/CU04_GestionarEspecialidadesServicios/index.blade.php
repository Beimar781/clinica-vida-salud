{{-- @CU CU-04 @RF RF04 @CI CIEspecialidad (mostrarListado, mostrarResultado) @CC CCEspecialidad @CE CEEspecialidad
     Pasos 1 y 2 del flujo: EspecialidadController::index(). --}}
@extends('layouts.app')

@section('titulo', 'Especialidades y servicios')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/gestion-medica.css')">
@endpush

@section('contenido')
    <div class="encabezado-pagina">
        <h1>Especialidades y servicios</h1>
        <a class="btn btn-primario" href="{{ route('especialidades.crear') }}"><x-icono nombre="mas" /> Nueva especialidad o servicio</a>
    </div>

    <div class="tarjeta">
        <div class="tarjeta__encabezado"><x-icono nombre="cruz" /><h2>Catálogo con precios</h2></div>
        <div class="tarjeta__cuerpo">
            <form method="GET" action="{{ route('especialidades.index') }}" class="herramientas" role="search">
                <div class="campo">
                    <label for="buscar">Buscar por nombre</label>
                    <input id="buscar" type="search" name="q" value="{{ $buscar }}" maxlength="80" placeholder="Ej.: cardiologia">
                </div>
                <div class="campo">
                    <label for="filtro-tipo">Tipo</label>
                    <select id="filtro-tipo" name="tipo">
                        <option value="">Todos</option>
                        @foreach (\App\Models\Especialidad::TIPOS as $valor => $texto)
                            <option value="{{ $valor }}" @selected($tipo === $valor)>{{ $texto }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-secundario"><x-icono nombre="buscar" /> Buscar</button>
                @if ($buscar !== '' || $tipo !== '')
                    <a class="btn btn-secundario" href="{{ route('especialidades.index') }}">Limpiar</a>
                @endif
            </form>

            <div class="tabla-responsive">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Tipo</th>
                            <th>Precio</th>
                            <th>Descripción</th>
                            <th>Profesionales activos</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($especialidades as $especialidad)
                            <tr>
                                <td><strong>{{ $especialidad->nombre }}</strong></td>
                                <td><x-tipo-servicio :tipo="$especialidad->tipo" /></td>
                                <td class="precio">{{ \App\Support\Dinero::formato($especialidad->precio) }}</td>
                                <td>{{ $especialidad->descripcion ?? '—' }}</td>
                                <td>{{ $especialidad->medicos_count }}</td>
                                <td><span class="etiqueta-estado {{ $especialidad->estado ? 'estado-activo' : 'estado-inactivo' }}">{{ $especialidad->estado ? 'Activa' : 'Inactiva' }}</span></td>
                                <td>
                                    <div class="tabla__acciones">
                                        <a class="btn btn-secundario btn-sm" href="{{ route('especialidades.editar', $especialidad) }}"><x-icono nombre="editar" /> Editar</a>
                                        @if ($especialidad->estado)
                                            <form method="POST" action="{{ route('especialidades.desactivar', $especialidad) }}"
                                                  data-confirmar="¿Desactivar {{ $especialidad->nombre }}? Dejará de ofrecerse para nuevas citas; las citas históricas se conservan.">
                                                @csrf
                                                <button type="submit" class="btn btn-peligro btn-sm"><x-icono nombre="bloquear" /> Desactivar</button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('especialidades.activar', $especialidad) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-primario btn-sm"><x-icono nombre="check" /> Activar</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="vacio">No se encontraron especialidades ni servicios{{ $buscar !== '' ? ' con «'.$buscar.'»' : '' }}.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        {{ $especialidades->links() }}
    </div>
@endsection
