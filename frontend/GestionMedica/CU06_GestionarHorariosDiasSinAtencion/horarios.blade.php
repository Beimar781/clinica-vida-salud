{{-- @CU CU-06 @RF RF06 @CI CIHorarios (mostrarAgenda, mostrarFormulario, enviarDatos, mostrarResultado) @CC CCHorarios @CE CEHorario, CEMedico
     Pasos 1 a 6 del flujo: HorariosController::index() muestra al médico elegido con su agenda semanal y el formulario del bloque;
     guardar() y actualizar() lo procesan (validan las horas, la superposición y la duración). --}}
@extends('layouts.app')

@section('titulo', 'Horarios de atención')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/gestion-medica.css')">
@endpush

@push('scripts')
    <script src="@recurso('js/modulos/gestion-medica.js')" defer></script>
@endpush

@section('contenido')
    <div class="encabezado-pagina">
        <h1>Horarios de atención</h1>
        <a class="btn btn-secundario" href="{{ route('dias-sin-atencion.index') }}"><x-icono nombre="calendario" /> Días sin atención</a>
    </div>

    @if ($errors->has('horario'))
        <div class="aviso aviso-error" role="alert"><x-icono nombre="alerta" /> <span>{{ $errors->first('horario') }}</span></div>
    @endif

    <div class="tarjeta">
        <div class="tarjeta__encabezado"><x-icono nombre="medico" /><h2>Médico</h2></div>
        <div class="tarjeta__cuerpo">
            @if ($medicos->isEmpty())
                <p class="vacio">No hay médicos activos. Registre o active un médico en la sección «Médicos» para definir sus horarios.</p>
            @else
                <form method="GET" action="{{ route('horarios.index') }}" class="herramientas" data-autoenviar>
                    <x-seleccion nombre="medico" etiqueta="Seleccione el médico" vacio="— Seleccione un médico —"
                                 :opciones="$medicos->mapWithKeys(fn ($m) => [$m->id => $m->codigo.' · '.$m->nombre_completo.' ('.$m->especialidades->pluck('nombre')->implode(', ').')'])->all()"
                                 :valor="$medico?->id" />
                    <button type="submit" class="btn btn-secundario"><x-icono nombre="buscar" /> Ver horarios</button>
                </form>
            @endif
        </div>
    </div>

    @if ($medico)
        <div class="tarjeta">
            <div class="tarjeta__encabezado"><x-icono nombre="calendario" /><h2>Agenda semanal de {{ $medico->nombre_completo }}</h2></div>
            <div class="tarjeta__cuerpo">
                <div class="tabla-responsive">
                    <table class="tabla agenda-semana">
                        <thead><tr><th>Día</th><th>Bloques de atención</th></tr></thead>
                        <tbody>
                            @foreach ($agenda as $dia => $bloques)
                                <tr>
                                    <th scope="row">{{ $dias[$dia] }}</th>
                                    <td>
                                        @forelse ($bloques as $bloque)
                                            <div @class(['bloque-atencion', 'bloque-atencion--inactivo' => ! $bloque->estado, 'bloque-atencion--edicion' => $editar?->id === $bloque->id])>
                                                <strong>{{ $bloque->hora_inicio_corta }} – {{ $bloque->hora_fin_corta }}</strong>
                                                <span>citas de {{ $bloque->duracion_min }} min</span>
                                                <span class="chip">{{ $bloque->cantidad_citas }} {{ $bloque->cantidad_citas === 1 ? 'cita' : 'citas' }}</span>
                                                <span class="etiqueta-estado {{ $bloque->estado ? 'estado-activo' : 'estado-inactivo' }}">{{ $bloque->estado ? 'Activo' : 'Inactivo' }}</span>
                                                <div class="tabla__acciones">
                                                    <a class="btn btn-secundario btn-sm" href="{{ route('horarios.index', ['medico' => $medico->id, 'editar' => $bloque->id]) }}#formulario-bloque"><x-icono nombre="editar" /> Editar</a>
                                                    @if ($bloque->estado)
                                                        <form method="POST" action="{{ route('horarios.desactivar', $bloque) }}"
                                                              data-confirmar="¿Desactivar este bloque? Dejará de ofrecerse para nuevas citas.">
                                                            @csrf
                                                            <button type="submit" class="btn btn-peligro btn-sm"><x-icono nombre="bloquear" /> Desactivar</button>
                                                        </form>
                                                    @else
                                                        <form method="POST" action="{{ route('horarios.activar', $bloque) }}">
                                                            @csrf
                                                            <button type="submit" class="btn btn-primario btn-sm"><x-icono nombre="check" /> Activar</button>
                                                        </form>
                                                    @endif
                                                </div>
                                            </div>
                                        @empty
                                            <span class="texto-suave">Sin atención</span>
                                        @endforelse
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="tarjeta" id="formulario-bloque">
            <div class="tarjeta__encabezado">
                <x-icono nombre="{{ $editar ? 'editar' : 'mas' }}" />
                <h2>{{ $editar ? 'Editar bloque de atención' : 'Agregar bloque de atención' }}</h2>
            </div>
            <div class="tarjeta__cuerpo">
                <form method="POST" action="{{ $editar ? route('horarios.actualizar', $editar) : route('horarios.guardar') }}" data-validar data-horario novalidate>
                    @csrf
                    @if ($editar) @method('PUT') @else <input type="hidden" name="medico_id" value="{{ $medico->id }}"> @endif
                    @error('medico_id')<div class="aviso aviso-error" role="alert"><x-icono nombre="alerta" /> <span>{{ $message }}</span></div>@enderror

                    <div class="formulario">
                        <x-seleccion nombre="dia_semana" etiqueta="Día de la semana" :opciones="$dias" :valor="$editar?->dia_semana" vacio="— Seleccione el día —" required />
                        <x-campo nombre="hora_inicio" etiqueta="Hora inicial" tipo="time" :valor="$editar?->hora_inicio_corta" required />
                        <x-campo nombre="hora_fin" etiqueta="Hora final" tipo="time" :valor="$editar?->hora_fin_corta" required />
                        <x-campo nombre="duracion_min" etiqueta="Duración de cada cita (minutos)" tipo="number" :valor="$editar?->duracion_min" required min="5" max="240" step="1"
                                 ayuda="Debe dividir exactamente el bloque (por ejemplo, 08:00 a 12:00 con 30 minutos son 8 citas)." />
                    </div>

                    <div id="resumen-bloque" class="aviso aviso-info" role="status" hidden></div>

                    <div class="acciones">
                        <button type="submit" class="btn btn-primario"><x-icono nombre="check" /> {{ $editar ? 'Guardar cambios' : 'Agregar bloque' }}</button>
                        @if ($editar)
                            <a class="btn btn-secundario" href="{{ route('horarios.index', ['medico' => $medico->id]) }}">Cancelar edición</a>
                        @endif
                    </div>
                </form>
            </div>
        </div>
    @endif
@endsection
