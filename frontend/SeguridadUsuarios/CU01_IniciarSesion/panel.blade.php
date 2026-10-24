{{-- @CU CU-01 @RF RF01 @CI CILogin (mostrarPanel) @CC CCLogin @CE CEUsuario — paso 5 del flujo: PanelController, una tarjeta por paquete según los roles --}}
@extends('layouts.app')

@section('titulo', 'Panel principal')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/seguridad.css')">
@endpush

@section('contenido')
    <div class="encabezado-pagina">
        <div>
            <h1>Bienvenido, {{ $usuario->nombre_mostrado }}</h1>
            <p class="texto-suave sin-margen">Estas son las funciones que puede usar con sus roles:
                @foreach ($roles as $rol)<span class="chip">{{ $rol }}</span>@endforeach
            </p>
        </div>
    </div>

    @forelse ($paquetes as $paquete)
        @if ($loop->first)<div class="paquetes">@endif
        <section class="paquete" aria-labelledby="paquete-{{ $paquete['clave'] }}">
            <div class="paquete__cabecera">
                <x-icono :nombre="$paquete['icono']" />
                <h2 id="paquete-{{ $paquete['clave'] }}">{{ $paquete['nombre'] }}</h2>
            </div>
            <ul class="paquete__funciones">
                @foreach ($paquete['funciones'] as $funcion)
                    <li>
                        <a href="{{ $funcion['url'] }}">
                            <x-icono :nombre="$funcion['icono']" />
                            <span>{{ $funcion['nombre'] }}</span>
                            <span class="paquete__cu">{{ $funcion['cu'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
        @if ($loop->last)</div>@endif
    @empty
        <div class="aviso aviso-info"><x-icono nombre="info" /> <span>Por ahora no hay funciones disponibles para sus roles. Las funciones de atención médica (agenda e historia clínica) se habilitarán en la próxima entrega del sistema.</span></div>
    @endforelse
@endsection
