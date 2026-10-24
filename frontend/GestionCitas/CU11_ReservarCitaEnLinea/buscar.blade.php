{{-- @CU CU-11 @RF RF11 @CI CIReservarCita (buscarMedicos) @CC CCReservarCita (buscar) @CE CEMedico
     Pasos 1 y 2 del flujo: ReservarCitaController::buscar() muestra «Encuentre su especialidad» (especialidad y ubicación) y los
     médicos activos que coinciden, con su próximo horario libre. --}}
@extends('layouts.app')

@section('titulo', 'Reservar cita')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/gestion-citas.css')">
@endpush

@section('contenido')
    <div class="encabezado-pagina">
        <h1>Reservar cita en línea</h1>
    </div>

    @if ($impedimento)
        <div class="aviso aviso-alerta" role="alert"><x-icono nombre="alerta" /> <span>{{ $impedimento }}</span></div>
    @endif

    <div class="buscador">
        <h2>Encuentre su especialidad</h2>
        <form method="GET" action="{{ route('reservas.crear') }}" data-autoenviar>
            <div class="buscador__campos">
                <x-seleccion nombre="especialidad" etiqueta="Especialidad" vacio="— Seleccione la especialidad —" :opciones="$especialidades->pluck('nombre', 'id')->all()" :valor="$especialidad?->id" />
                <x-seleccion nombre="ubicacion" etiqueta="Ubicación (opcional)" vacio="Todas las ubicaciones" :opciones="array_combine($ubicaciones, $ubicaciones)" :valor="$ubicacion" />
                <div class="campo"><button type="submit" class="btn btn-primario"><x-icono nombre="buscar" /> Buscar médicos</button></div>
            </div>
        </form>
    </div>

    @if ($resultados !== null)
        <div class="tarjeta" style="margin-top:1.25rem">
            <div class="tarjeta__encabezado"><x-icono nombre="medico" /><h2>Médicos de {{ $especialidad->nombre }}{{ $ubicacion ? ' en '.$ubicacion : '' }}</h2></div>
            <div class="tarjeta__cuerpo">
                @forelse ($resultados as $resultado)
                    @php $medico = $resultado['medico']; $proximo = $resultado['proximo']; @endphp
                    <article class="medico-tarjeta">
                        <div>
                            <h3>{{ $medico->nombre_completo }}</h3>
                            <p class="sin-margen">@foreach ($medico->especialidades as $e)<span class="chip">{{ $e->nombre }}</span>@endforeach</p>
                            <p class="texto-suave sin-margen"><x-icono nombre="ubicacion" /> {{ $medico->ubicacion ?? 'Sin ubicación indicada' }}</p>
                            <p class="sin-margen">
                                @if ($proximo)
                                    Próximo horario libre: <strong>{{ ucfirst(\Illuminate\Support\Carbon::parse($proximo['fecha'])->isoFormat('dddd')) }} {{ \Illuminate\Support\Carbon::parse($proximo['fecha'])->format('d/m/Y') }} · {{ $proximo['inicio'] }}</strong>
                                @else
                                    <span class="texto-suave">Sin horarios libres en los próximos 14 días.</span>
                                @endif
                            </p>
                        </div>
                        @unless ($impedimento)
                            <a class="btn btn-primario" href="{{ route('reservas.reservar', array_filter(['especialidad' => $especialidad->id, 'medico' => $medico->id, 'fecha' => $proximo['fecha'] ?? null])) }}"><x-icono nombre="calendario" /> Ver horarios y reservar</a>
                        @endunless
                    </article>
                @empty
                    <div class="aviso aviso-alerta" role="status"><x-icono nombre="alerta" /> <span>{{ \App\Modules\GestionCitas\CU11_ReservarCitaEnLinea\ReservarCitaService::MENSAJE_SIN_MEDICOS }}</span></div>
                @endforelse
            </div>
        </div>
    @endif
@endsection
