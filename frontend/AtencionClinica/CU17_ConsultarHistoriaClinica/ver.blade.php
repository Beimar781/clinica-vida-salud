{{-- @CU CU-17 @RF RF17 @CI CIHistoriaClinica (buscarPaciente, mostrarHistoria) @CC CCHistoriaClinica @CE CEHistoriaClinica, CEConsulta, CEAntecedente
     Pasos 2 a 4 del flujo: HistoriaClinicaController::index() busca al paciente por CI o nombre; ver() muestra la historia clínica en
     SOLO LECTURA (no hay formularios de edición): datos generales, consultas por fecha y antecedentes de otros hospitales. --}}
@extends('layouts.app')

@section('titulo', 'Historia clínica')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/atencion-clinica.css')">
@endpush

@section('contenido')
    @php use App\Modules\AtencionClinica\CU17_ConsultarHistoriaClinica\HistoriaClinicaService; @endphp

    <div class="encabezado-pagina">
        <h1>Historia clínica</h1>
        @if ($paciente)
            <a class="btn btn-secundario" href="{{ route('historia.index') }}"><x-icono nombre="buscar" /> Buscar otro paciente</a>
        @endif
    </div>

    {{-- Paso 2: búsqueda del paciente --}}
    @unless ($paciente)
        <div class="tarjeta">
            <div class="tarjeta__encabezado"><x-icono nombre="paciente" /><h2>Paciente</h2></div>
            <div class="tarjeta__cuerpo">
                <form method="GET" action="{{ route('historia.index') }}" class="herramientas" role="search">
                    <div class="campo">
                        <label for="buscar">Buscar paciente por CI o nombre</label>
                        <input id="buscar" type="search" name="q" value="{{ $buscar }}" maxlength="80" placeholder="Ej.: 6543210 o Rojas" autofocus>
                    </div>
                    <button type="submit" class="btn btn-primario"><x-icono nombre="buscar" /> Buscar</button>
                </form>

                @if ($buscar !== '')
                    @if ($encontrados->isEmpty())
                        <p class="vacio">No se encontraron pacientes con «{{ $buscar }}».</p>
                    @else
                        <div class="tabla-responsive">
                            <table class="tabla">
                                <thead><tr><th>Código</th><th>CI</th><th>Paciente</th><th>Estado</th><th></th></tr></thead>
                                <tbody>
                                    @foreach ($encontrados as $encontrado)
                                        <tr>
                                            <td>{{ $encontrado->codigo }}</td>
                                            <td>{{ $encontrado->ci }}</td>
                                            <td><strong>{{ $encontrado->nombre_completo }}</strong></td>
                                            <td><span class="etiqueta-estado {{ $encontrado->estado ? 'estado-activo' : 'estado-inactivo' }}">{{ $encontrado->estado ? 'Activo' : 'Inactivo' }}</span></td>
                                            <td><a class="btn btn-primario btn-sm" href="{{ route('historia.ver', $encontrado) }}"><x-icono nombre="documento" /> Ver historia</a></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @else
                    <p class="vacio sin-margen">Busque a un paciente por CI o nombre para ver su historia clínica.</p>
                @endif
            </div>
        </div>
    @endunless

    {{-- Pasos 3 y 4: la historia, en solo lectura --}}
    @if ($paciente && $datos)
        @php
            $historia = $datos['historia'];
            $consultas = $datos['consultas'];
            $antecedentes = $datos['antecedentes'];
        @endphp

        <div class="tarjeta">
            <div class="tarjeta__encabezado">
                <x-icono nombre="paciente" />
                <h2>{{ $paciente->nombre_completo }}</h2>
                <span class="etiqueta-estado estado-programada">Solo lectura</span>
            </div>
            <div class="tarjeta__cuerpo">
                <dl class="dato-clinico dato-clinico--fila">
                    <div><dt>CI</dt><dd>{{ $paciente->ci }}</dd></div>
                    <div><dt>Código</dt><dd>{{ $paciente->codigo }}</dd></div>
                    <div><dt>Edad</dt><dd>{{ (int) $paciente->fecha_nacimiento->age }} años</dd></div>
                    <div><dt>Sexo</dt><dd>{{ ['M' => 'Masculino', 'F' => 'Femenino'][$paciente->sexo] ?? 'No indicado' }}</dd></div>
                    <div><dt>Historia abierta el</dt><dd>{{ $historia?->fecha_apertura?->format('d/m/Y') ?? '—' }}</dd></div>
                </dl>
            </div>
        </div>

        <div class="tarjeta">
            <div class="tarjeta__encabezado"><x-icono nombre="cruz" /><h2>Datos generales</h2></div>
            <div class="tarjeta__cuerpo">
                <dl class="dato-clinico dato-clinico--cuadricula">
                    <div><dt>Tipo de sangre</dt><dd>{{ $historia?->tipo_sangre ?? 'No registrado' }}</dd></div>
                    <div class="dato-clinico--alerta"><dt>Alergias</dt><dd>{{ $historia?->alergias ?: 'Ninguna registrada' }}</dd></div>
                    <div><dt>Enfermedades crónicas</dt><dd>{{ $historia?->enfermedades_cronicas ?: 'Ninguna registrada' }}</dd></div>
                    <div><dt>Medicamentos</dt><dd>{{ $historia?->medicamentos ?: 'Ninguno registrado' }}</dd></div>
                    <div><dt>Antecedentes personales</dt><dd>{{ $historia?->antecedentes_personales ?: 'Sin antecedentes registrados' }}</dd></div>
                    <div><dt>Observaciones</dt><dd>{{ $historia?->observaciones ?: '—' }}</dd></div>
                </dl>
            </div>
        </div>

        <div class="tarjeta">
            <div class="tarjeta__encabezado"><x-icono nombre="corazon" /><h2>Consultas en la clínica ({{ $consultas->count() }})</h2></div>
            <div class="tarjeta__cuerpo">
                @forelse ($consultas as $consulta)
                    <article class="consulta-registro">
                        <header>
                            <strong>{{ $consulta->cita->fecha->format('d/m/Y') }}</strong>
                            <span class="texto-suave">{{ $consulta->cita->hora_inicio_corta }} · {{ $consulta->cita->especialidad->nombre }} · {{ $consulta->medico->nombre_completo }} · cita {{ $consulta->cita->codigo }}</span>
                        </header>
                        <dl class="dato-clinico">
                            <div><dt>Diagnóstico</dt><dd>{{ $consulta->diagnostico }}</dd></div>
                            <div><dt>Tratamiento</dt><dd>{{ $consulta->tratamiento ?: 'No indicado' }}</dd></div>
                            @if ($consulta->observaciones)<div><dt>Observaciones</dt><dd>{{ $consulta->observaciones }}</dd></div>@endif
                        </dl>
                    </article>
                @empty
                    <p class="vacio sin-margen">{{ HistoriaClinicaService::MENSAJE_SIN_CONSULTAS }}</p>
                @endforelse
            </div>
        </div>

        <div class="tarjeta">
            <div class="tarjeta__encabezado"><x-icono nombre="ubicacion" /><h2>Antecedentes de otros hospitales ({{ $antecedentes->count() }})</h2></div>
            <div class="tarjeta__cuerpo">
                @forelse ($antecedentes as $antecedente)
                    <article class="consulta-registro consulta-registro--externa">
                        <header>
                            <strong>{{ $antecedente->hospital_origen }}</strong>
                            <span class="texto-suave">{{ $antecedente->fecha_atencion?->format('d/m/Y') ?? 'Sin fecha' }}@if ($antecedente->especialidad) · {{ $antecedente->especialidad }}@endif</span>
                        </header>
                        <dl class="dato-clinico">
                            <div><dt>Diagnóstico</dt><dd>{{ $antecedente->diagnostico }}</dd></div>
                            <div><dt>Tratamiento</dt><dd>{{ $antecedente->tratamiento ?: 'No indicado' }}</dd></div>
                            @if ($antecedente->observaciones)<div><dt>Observaciones</dt><dd>{{ $antecedente->observaciones }}</dd></div>@endif
                        </dl>
                    </article>
                @empty
                    <p class="vacio sin-margen">{{ HistoriaClinicaService::MENSAJE_SIN_ANTECEDENTES }}</p>
                @endforelse
            </div>
        </div>
    @endif
@endsection
