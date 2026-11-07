{{-- @CU CU-16 @RF RF16 @CI CIConsulta (mostrarCita, enviarConsulta, mostrarResultado) @CC CCConsulta @CE CECita, CEConsulta, CEHistoriaClinica
     Pasos 2 a 4 del flujo: ConsultaController::mostrar() muestra los datos del paciente y de la cita y el formulario del diagnóstico, el
     tratamiento y las observaciones (vacío, o con la consulta existente para corregirla); guardar() y actualizar() lo procesan. --}}
@extends('layouts.app')

@section('titulo', $consulta ? 'Consulta registrada' : 'Registrar consulta')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/atencion-clinica.css')">
@endpush

@push('scripts')
    <script src="@recurso('js/modulos/atencion-clinica.js')" defer></script>
@endpush

@section('contenido')
    @php
        $paciente = $cita->paciente;
        $historia = $paciente->historiaClinica;
        $corrigiendo = $consulta !== null;
    @endphp

    <div class="encabezado-pagina">
        <h1>{{ $corrigiendo ? 'Consulta registrada' : 'Registrar consulta' }} <small class="texto-suave">{{ $cita->codigo }}</small></h1>
        <a class="btn btn-secundario" href="{{ route('agenda.index', ['desde' => $cita->fecha->toDateString()]) }}">Volver a la agenda</a>
    </div>

    <div class="tarjeta">
        <div class="tarjeta__encabezado"><x-icono nombre="paciente" /><h2>Paciente y cita</h2></div>
        <div class="tarjeta__cuerpo">
            <div class="datos-consulta">
                <dl class="dato-clinico">
                    <div><dt>Paciente</dt><dd><strong>{{ $paciente->nombre_completo }}</strong> <small class="texto-suave">· CI {{ $paciente->ci }} · {{ $paciente->codigo }}</small></dd></div>
                    <div><dt>Edad y sexo</dt><dd>{{ (int) $paciente->fecha_nacimiento->age }} años · {{ ['M' => 'Masculino', 'F' => 'Femenino'][$paciente->sexo] ?? 'No indicado' }}</dd></div>
                    <div><dt>Tipo de sangre</dt><dd>{{ $historia?->tipo_sangre ?? 'No registrado' }}</dd></div>
                    <div class="dato-clinico--alerta"><dt>Alergias</dt><dd>{{ $historia?->alergias ?: 'Ninguna registrada' }}</dd></div>
                </dl>
                <dl class="dato-clinico">
                    <div><dt>Fecha</dt><dd>{{ ucfirst($cita->fecha->isoFormat('dddd')) }} {{ $cita->fecha->format('d/m/Y') }}</dd></div>
                    <div><dt>Horario</dt><dd>{{ $cita->hora_inicio_corta }} – {{ $cita->hora_fin_corta }} · {{ $cita->modalidad === 'VIRTUAL' ? 'Virtual' : 'Presencial' }}</dd></div>
                    <div><dt>Especialidad</dt><dd>{{ $cita->especialidad->nombre }}</dd></div>
                    <div><dt>Estado</dt><dd><x-estado-cita :estado="$cita->estado" /></dd></div>
                    <div><dt>Motivo de la cita</dt><dd>{{ $cita->motivo ?: 'No indicado' }}</dd></div>
                </dl>
            </div>
            @if (Route::has('historia.ver'))
                <p class="sin-margen"><a href="{{ route('historia.ver', $paciente) }}"><x-icono nombre="documento" /> Ver la historia clínica de {{ $paciente->nombres }}</a></p>
            @endif
        </div>
    </div>

    @if ($corrigiendo)
        <div class="aviso aviso-info" role="status">
            <x-icono nombre="info" />
            <span>Esta cita ya tiene su consulta registrada el {{ $consulta->created_at->format('d/m/Y H:i') }}. Puede corregirla; la cita seguirá Atendida.</span>
        </div>
    @endif

    <div class="tarjeta">
        <div class="tarjeta__encabezado"><x-icono nombre="corazon" /><h2>{{ $corrigiendo ? 'Corregir la consulta' : 'Consulta y tratamiento' }}</h2></div>
        <div class="tarjeta__cuerpo">
            <form method="POST" action="{{ $corrigiendo ? route('consultas.actualizar', $cita) : route('consultas.guardar', $cita) }}" data-validar novalidate>
                @csrf
                @if ($corrigiendo) @method('PUT') @endif
                <div class="formulario formulario--vertical">
                    <x-areatexto nombre="diagnostico" etiqueta="Diagnóstico" :valor="$consulta?->diagnostico" required maxlength="300" rows="3" autofocus
                                 ayuda="Obligatorio. Máximo 300 caracteres." />
                    <x-areatexto nombre="tratamiento" etiqueta="Tratamiento indicado (opcional)" :valor="$consulta?->tratamiento" maxlength="400" rows="4"
                                 ayuda="Medicamentos, dosis, indicaciones. Máximo 400 caracteres." />
                    <x-areatexto nombre="observaciones" etiqueta="Observaciones (opcional)" :valor="$consulta?->observaciones" maxlength="400" rows="3"
                                 ayuda="Máximo 400 caracteres." />
                </div>
                <div class="acciones">
                    <button type="submit" class="btn btn-primario"><x-icono nombre="check" /> {{ $corrigiendo ? 'Guardar la corrección' : 'Guardar consulta' }}</button>
                    <a class="btn btn-secundario" href="{{ route('agenda.index', ['desde' => $cita->fecha->toDateString()]) }}">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
@endsection
