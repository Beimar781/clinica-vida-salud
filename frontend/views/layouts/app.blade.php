{{-- Layout común. Las vistas de cada paquete agregan su CSS/JS con @push('estilos') y @push('scripts'). --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('titulo', 'Inicio') · Clínica Vida Salud</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 48 48'%3E%3Crect x='17' y='3' width='14' height='42' rx='5' fill='%230A7BC4'/%3E%3Crect x='3' y='17' width='42' height='14' rx='5' fill='%230A7BC4'/%3E%3Cpath d='M24 12.5c-4.6 5.3-7.2 8.8-7.2 12a7.2 7.2 0 0 0 14.4 0c0-3.2-2.6-6.7-7.2-12z' fill='%23fff'/%3E%3C/svg%3E">
    <link rel="stylesheet" href="@recurso('css/vidasalud.css')">
    @stack('estilos')
</head>
<body>
    <header class="barra">
        <a class="barra__logo" href="{{ auth()->check() ? url('/panel') : url('/') }}">
            <x-logo />
            <span class="barra__marca">Vida Salud<small>Clínica</small></span>
        </a>

        @auth
            <nav class="barra__nav" aria-label="Principal">
                <ul class="barra__menu">
                    <li><a href="{{ url('/panel') }}" @if (request()->is('panel')) aria-current="page" @endif>Panel</a></li>
                    @yield('menu')
                </ul>
            </nav>
            <div class="barra__usuario">
                <span><x-icono nombre="usuario" /> {{ auth()->user()->nombre_usuario }}</span>
                <form method="POST" action="{{ url('/logout') }}">
                    @csrf
                    <button type="submit" class="btn btn-secundario btn-sm"><x-icono nombre="salir" /> Cerrar sesión</button>
                </form>
            </div>
        @else
            <div class="barra__usuario">
                @unless (request()->is('login'))
                    <a class="btn btn-primario btn-sm" href="{{ url('/login') }}"><x-icono nombre="entrar" /> Iniciar sesión</a>
                @endunless
            </div>
        @endauth
    </header>

    <main class="contenedor">
        @if (session('exito'))
            <div class="aviso aviso-ok" role="status"><x-icono nombre="check" /> <span>{{ session('exito') }}</span></div>
        @endif
        @if (session('error'))
            <div class="aviso aviso-error" role="alert"><x-icono nombre="alerta" /> <span>{{ session('error') }}</span></div>
        @endif

        @yield('contenido')
    </main>

    <script src="@recurso('js/vidasalud.js')" defer></script>
    @stack('scripts')
</body>
</html>
