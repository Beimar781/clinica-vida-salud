@extends('layouts.app')

@section('titulo', 'Inicio')

@section('contenido')
    <section class="portada">
        <div class="portada__caja">
            <x-logo />
            <h1>Clínica Vida Salud</h1>
            <p>Gestión de citas médicas: consulte horarios, reserve su atención y revise su historial en un solo lugar.</p>
            <div class="portada__acciones">
                @auth
                    <a class="btn btn-primario" href="{{ url('/panel') }}"><x-icono nombre="panel" /> Ir al panel</a>
                @else
                    <a class="btn btn-primario" href="{{ url('/login') }}"><x-icono nombre="entrar" /> Iniciar sesión</a>
                @endauth
            </div>
        </div>
    </section>
@endsection
