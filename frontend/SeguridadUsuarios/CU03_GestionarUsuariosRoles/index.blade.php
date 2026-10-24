{{-- @CU CU-03 @RF RF03 @CI CIUsuarios (mostrarListado, mostrarResultado) @CC CCUsuarios @CE CEUsuario, CERol --}}
@extends('layouts.app')

@section('titulo', 'Usuarios y roles')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/seguridad.css')">
@endpush

@section('contenido')
    <div class="encabezado-pagina">
        <h1>Usuarios y roles</h1>
        <a class="btn btn-primario" href="{{ route('usuarios.crear') }}"><x-icono nombre="mas" /> Nuevo usuario</a>
    </div>

    <div class="tarjeta">
        <div class="tarjeta__encabezado"><x-icono nombre="usuarios" /><h2>Listado de usuarios</h2></div>
        <div class="tarjeta__cuerpo">
            <form method="GET" action="{{ route('usuarios.index') }}" class="herramientas" role="search">
                <div class="campo">
                    <label for="buscar">Buscar por nombre de usuario o correo</label>
                    <input id="buscar" type="search" name="q" value="{{ $buscar }}" maxlength="120" placeholder="Ej.: recepcion o carla@">
                </div>
                <button type="submit" class="btn btn-secundario"><x-icono nombre="buscar" /> Buscar</button>
                @if ($buscar !== '')
                    <a class="btn btn-secundario" href="{{ route('usuarios.index') }}">Limpiar</a>
                @endif
            </form>

            <div class="tabla-responsive">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>Nombre de usuario</th>
                            <th>Correo</th>
                            <th>Roles</th>
                            <th>Estado</th>
                            <th>Último acceso</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($usuarios as $usuario)
                            <tr>
                                <td><strong>{{ $usuario->nombre_usuario }}</strong></td>
                                <td>{{ $usuario->correo }}</td>
                                <td>@foreach ($usuario->roles as $rol)<span class="chip">{{ $rol->nombre }}</span>@endforeach</td>
                                <td>
                                    <span class="etiqueta-estado {{ $usuario->estado ? 'estado-activo' : 'estado-inactivo' }}">{{ $usuario->estado ? 'Activo' : 'Inactivo' }}</span>
                                </td>
                                <td>{{ $usuario->ultimo_acceso ? $usuario->ultimo_acceso->format('d/m/Y H:i') : 'Nunca' }}</td>
                                <td>
                                    <div class="tabla__acciones">
                                        <a class="btn btn-secundario btn-sm" href="{{ route('usuarios.editar', $usuario) }}"><x-icono nombre="editar" /> Editar</a>
                                        @if ($usuario->estado)
                                            @unless ($usuario->is(auth()->user()))
                                                <form method="POST" action="{{ route('usuarios.desactivar', $usuario) }}"
                                                      data-confirmar="¿Desactivar la cuenta de {{ $usuario->nombre_usuario }}? Ya no podrá iniciar sesión, pero se conserva su registro.">
                                                    @csrf
                                                    <button type="submit" class="btn btn-peligro btn-sm"><x-icono nombre="bloquear" /> Desactivar</button>
                                                </form>
                                            @endunless
                                        @else
                                            <form method="POST" action="{{ route('usuarios.activar', $usuario) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-primario btn-sm"><x-icono nombre="check" /> Activar</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="vacio">No se encontraron usuarios{{ $buscar !== '' ? ' con «'.$buscar.'»' : '' }}.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        {{ $usuarios->links() }}
    </div>
@endsection
