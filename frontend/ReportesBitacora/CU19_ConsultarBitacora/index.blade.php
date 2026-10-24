{{-- @CU CU-19 @RF RF19 @CI CIBitacora (mostrarListado, filtrar) @CC CCBitacora (consultar) @CE CEBitacora
     Pasos 1 a 4 del flujo: BitacoraController::index(). Solo lectura. --}}
@extends('layouts.app')

@section('titulo', 'Bitácora')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/reportes-bitacora.css')">
@endpush

@section('contenido')
    <div class="encabezado-pagina">
        <h1>Bitácora</h1>
        <span class="chip">{{ $registros->total() }} {{ $registros->total() === 1 ? 'registro' : 'registros' }}</span>
    </div>

    <div class="tarjeta">
        <div class="tarjeta__encabezado"><x-icono nombre="historial" /><h2>Operaciones registradas en el sistema</h2></div>
        <div class="tarjeta__cuerpo">
            <form method="GET" action="{{ route('bitacora.index') }}" class="filtros-bitacora" role="search" data-validar novalidate>
                <div class="campo">
                    <label for="filtro-usuario">Usuario</label>
                    <select id="filtro-usuario" name="usuario">
                        <option value="">Todos</option>
                        <option value="sin" @selected(($filtros['usuario'] ?? '') === 'sin')>Visitante / sistema</option>
                        @foreach ($opciones['usuarios'] as $usuario)
                            <option value="{{ $usuario->id }}" @selected(($filtros['usuario'] ?? '') === (string) $usuario->id)>{{ $usuario->nombre_usuario }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="campo">
                    <label for="filtro-entidad">Entidad</label>
                    <select id="filtro-entidad" name="entidad">
                        <option value="">Todas</option>
                        @foreach ($opciones['entidades'] as $entidad)
                            <option value="{{ $entidad }}" @selected(($filtros['entidad'] ?? '') === $entidad)>{{ $entidad }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="campo">
                    <label for="filtro-accion">Acción</label>
                    <select id="filtro-accion" name="accion">
                        <option value="">Todas</option>
                        @foreach ($opciones['acciones'] as $codigo => $etiqueta)
                            <option value="{{ $codigo }}" @selected(($filtros['accion'] ?? '') === $codigo)>{{ $etiqueta }}</option>
                        @endforeach
                    </select>
                </div>
                <x-campo nombre="desde" etiqueta="Desde" tipo="date" :valor="$filtros['desde'] ?? null" />
                <x-campo nombre="hasta" etiqueta="Hasta (incluye todo el día)" tipo="date" :valor="$filtros['hasta'] ?? null" />
                <x-campo nombre="q" etiqueta="Buscar en el detalle o la IP" tipo="search" :valor="$filtros['q'] ?? null" maxlength="100" placeholder="Ej.: cita o 203.0.113" />
                <div class="filtros-bitacora__botones">
                    <button type="submit" class="btn btn-primario"><x-icono nombre="buscar" /> Filtrar</button>
                    <a class="btn btn-secundario" href="{{ route('bitacora.index') }}">Limpiar</a>
                </div>
            </form>

            <div class="tabla-responsive">
                <table class="tabla tabla-bitacora">
                    <thead>
                        <tr>
                            <th>Fecha y hora</th>
                            <th>Usuario</th>
                            <th>Acción</th>
                            <th>Entidad</th>
                            <th>Detalle</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($registros as $registro)
                            <tr>
                                <td class="no-parte">{{ $registro->fecha_hora->format('d/m/Y H:i:s') }}</td>
                                <td>{{ $registro->usuario?->nombre_usuario ?? 'Visitante / sistema' }}</td>
                                <td>
                                    <span class="etiqueta-estado accion-{{ \App\Modules\ReportesBitacora\CU19_ConsultarBitacora\AccionesBitacora::tono($registro->accion) }}" title="{{ $registro->accion }}">
                                        {{ \App\Modules\ReportesBitacora\CU19_ConsultarBitacora\AccionesBitacora::etiqueta($registro->accion) }}
                                    </span>
                                </td>
                                <td>{{ $registro->entidad ?? '—' }}</td>
                                <td class="detalle-bitacora">{{ $registro->detalle ?? '—' }}</td>
                                <td class="no-parte">{{ $registro->ip ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="vacio">No hay registros con esos filtros.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        {{ $registros->links() }}
    </div>
@endsection
