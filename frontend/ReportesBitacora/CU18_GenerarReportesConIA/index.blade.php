{{-- @CU CU-18 @RF RF18 @CI CIReportes (elegirFiltros, mostrarReporte, generarConIA, exportar) @CC CCReportes @CE CECita, CEPaciente, CEMedico
     Pasos 1 a 6 del flujo: ReportesController::index() (filtros, reporte con sus totales y resumen de IA) y exportar() (CSV y PDF).
     La impresión usa CSS @media print: solo se imprime el reporte. --}}
@extends('layouts.app')

@section('titulo', 'Reportes')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/reportes-bitacora.css')">
@endpush

@push('scripts')
    <script src="@recurso('js/modulos/reportes-bitacora.js')" defer></script>
    <script src="@recurso('js/modulos/reportes-voz.js')" defer></script>
@endpush

@section('contenido')
    @php
        use App\Modules\ReportesBitacora\CU18_GenerarReportesConIA\ReporteService;
        use App\Support\Dinero;

        $valor = fn (string $campo) => $filtros[$campo] ?? null;
        $medicoEjemplo = $medicos->firstWhere('estado', true)?->nombre_completo;
        // Frases que entiende el asistente de voz (se pueden dictar, escribir o tocar).
        $ejemplos = array_values(array_filter([
            'Citas de hoy',
            'Citas por médico del 1 al 31 de octubre',
            'Citas por especialidad de esta semana',
            'Citas por estado de este mes con IA',
            'Ingresos por método de pago de este mes',
            'Pacientes registrados de este año',
            'Atendidas, canceladas e inasistencias del mes pasado',
            $medicoEjemplo ? "Agenda de {$medicoEjemplo} de mañana" : null,
        ]));
    @endphp

    <div class="encabezado-pagina sin-impresion">
        <h1>Reportes</h1>
    </div>

    <div class="buscador sin-impresion" style="margin-bottom:1.25rem">
        <h2>Tipo de reporte y filtros</h2>
        <form method="GET" action="{{ route('reportes.index') }}" id="form-reporte" data-validar novalidate
              data-un-dia='@json($unDia)'>
            <div class="filtros-reporte">
                <x-seleccion nombre="tipo" etiqueta="Tipo de reporte" vacio="— Seleccione —" required :opciones="$tipos" :valor="$valor('tipo')" />
                <x-campo nombre="desde" etiqueta="Fecha" tipo="date" :valor="$valor('desde')" required data-etiqueta-un-dia="Fecha" data-etiqueta-rango="Desde" />
                <div data-campo-hasta><x-campo nombre="hasta" etiqueta="Hasta" tipo="date" :valor="$valor('hasta')" /></div>
                <div data-campo-medico>
                    <x-seleccion nombre="medico" etiqueta="Médico" vacio="Todos" :valor="$valor('medico')"
                                 :opciones="$medicos->mapWithKeys(fn ($m) => [$m->id => $m->nombre_completo.($m->estado ? '' : ' · inactivo')])->all()" />
                </div>
                <div data-campo-especialidad>
                    <x-seleccion nombre="especialidad" etiqueta="Especialidad o servicio" vacio="Todas" :valor="$valor('especialidad')"
                                 :opciones="$especialidades->mapWithKeys(fn ($e) => [$e->id => $e->nombre.($e->estado ? '' : ' · inactiva')])->all()" />
                </div>
                <div data-campo-estado>
                    <x-seleccion nombre="estado" etiqueta="Estado de la cita" vacio="Todos" :valor="$valor('estado')" :opciones="$estados" />
                </div>
            </div>

            <fieldset class="modalidad-ia">
                <legend class="etiqueta">Modalidad</legend>
                <label class="opcion-modalidad">
                    <input type="radio" name="ia" value="sin" @checked(old('ia', $valor('ia') ?? 'sin') !== 'con')>
                    <span>Sin IA<small>Solo el detalle y los totales.</small></span>
                </label>
                <label class="opcion-modalidad">
                    <input type="radio" name="ia" value="con" @checked(old('ia', $valor('ia')) === 'con')>
                    <span>Con IA<small>Agrega un resumen redactado por IA. Solo se envían los totales, nunca nombres, CI ni datos clínicos.</small></span>
                </label>
                @error('ia')<span class="campo__error" role="alert">{{ $message }}</span>@enderror
            </fieldset>

            <div class="acciones">
                <button type="submit" class="btn btn-primario"><x-icono nombre="documento" /> Generar reporte</button>
                <a class="btn btn-secundario" href="{{ route('reportes.index') }}">Limpiar</a>
            </div>
        </form>
    </div>


    {{-- Asistente de voz (extra del equipo, no está en el documento): entiende una frase dictada o escrita, llena este formulario, genera el
         reporte y lee en voz alta SOLO los totales. La frase se interpreta en el navegador (reportes-voz.js): no va a la IA ni al servidor. --}}
    <section class="asistente-voz sin-impresion" id="asistente-voz" aria-labelledby="titulo-asistente-voz">
        <h2 id="titulo-asistente-voz"><x-icono nombre="microfono" /> Asistente de voz</h2>
        <p class="texto-suave">Dicte o escriba una frase: el asistente llena el formulario, genera el reporte y le lee los totales en voz alta.</p>
        <div class="asistente-voz__entrada">
            <label class="sr-only" for="voz-frase">Frase para el asistente</label>
            <input type="text" id="voz-frase" maxlength="200" autocomplete="off" placeholder="Ej.: citas por médico del 1 al 31 de octubre">
            <button type="button" class="btn btn-primario" id="voz-enviar"><x-icono nombre="buscar" /> Preguntar</button>
            <button type="button" class="btn btn-secundario" id="voz-microfono" aria-pressed="false"><x-icono nombre="microfono" /> Dictar</button>
        </div>
        <p class="asistente-voz__estado" id="voz-estado" role="status" aria-live="polite">Toque un ejemplo, escriba una frase o use «Dictar».</p>
        <div class="asistente-voz__lectura">
            <button type="button" class="btn btn-secundario btn-sm" id="voz-leer" disabled><x-icono nombre="altavoz" /> Escuchar totales</button>
            <button type="button" class="btn btn-secundario btn-sm" id="voz-leer-ia" hidden><x-icono nombre="altavoz" /> Escuchar resumen de IA</button>
            <button type="button" class="btn btn-secundario btn-sm" id="voz-detener"><x-icono nombre="cerrar" /> Detener</button>
        </div>
        <h3>Frases que entiende</h3>
        <ul class="asistente-voz__ejemplos">
            @foreach ($ejemplos as $ejemplo)
                <li><button type="button" class="ejemplo-voz" data-frase="{{ $ejemplo }}">«{{ $ejemplo }}»</button></li>
            @endforeach
        </ul>
        <p class="texto-suave sin-margen">Puede cambiar las fechas: «hoy», «ayer», «mañana», «esta semana», «este mes», «el mes pasado», «octubre», «del 1 al 15 de noviembre» o «10/11/2026». Agregue «con IA» para pedir el resumen. Órdenes: «leer totales», «leer el resumen», «detener» y «ayuda».</p>
        <p class="asistente-voz__privacidad"><x-icono nombre="info" /> <span>Solo se leen los totales, nunca nombres ni datos de pacientes. El dictado usa el reconocimiento de voz del navegador (Chrome o Edge), que envía el audio al servicio de su fabricante: no diga nombres de pacientes ni CI. Si prefiere, escriba la frase.</span></p>
        <p class="aviso aviso-info" id="voz-sin-microfono" hidden><x-icono nombre="info" /> <span>Este navegador no permite dictar: escriba la frase o toque un ejemplo.</span></p>
        <p class="aviso aviso-info" id="voz-sin-lectura" hidden><x-icono nombre="info" /> <span>Este navegador no puede leer en voz alta: los totales se muestran en pantalla.</span></p>
    </section>

    <div id="resultado-reporte" aria-live="polite">
    @if ($errors->any() && ! $reporte)
        <div class="aviso aviso-alerta sin-impresion" role="alert"><x-icono nombre="alerta" /> <span>No se generó el reporte: revise los filtros marcados.</span></div>
    @endif

    @if ($reporte)
        <article class="reporte" aria-labelledby="titulo-reporte">
            <header class="reporte__cabecera">
                <div>
                    <h2 id="titulo-reporte">{{ $reporte['titulo'] }}</h2>
                    <p class="texto-suave sin-margen">Período: <strong>{{ $reporte['periodo'] }}</strong>
                        @foreach ($reporte['filtros'] as $filtro) · {{ $filtro }}@endforeach
                    </p>
                    <p class="texto-suave sin-margen">Generado el {{ $reporte['generado'] }} · Clínica Vida Salud</p>
                </div>
                @unless ($reporte['vacio'])
                    <div class="reporte__acciones sin-impresion">
                        <button type="button" class="btn btn-secundario btn-sm" data-imprimir><x-icono nombre="documento" /> Imprimir</button>
                        <a class="btn btn-secundario btn-sm" href="{{ route('reportes.exportar', ['formato' => 'csv'] + request()->query()) }}"><x-icono nombre="descargar" /> CSV</a>
                        <a class="btn btn-secundario btn-sm" href="{{ route('reportes.exportar', ['formato' => 'pdf'] + request()->query()) }}"><x-icono nombre="descargar" /> PDF</a>
                    </div>
                @endunless
            </header>

            @if ($avisoIA)
                <div class="aviso aviso-alerta" role="alert"><x-icono nombre="alerta" /> <span>{{ $avisoIA }}</span></div>
            @endif

            @if ($reporte['vacio'])
                <p class="vacio">{{ $reporte['mensaje_vacio'] }}</p>
            @else
                @if ($resumenIA)
                    <section class="resumen-ia" aria-labelledby="titulo-resumen-ia">
                        <h3 id="titulo-resumen-ia">Resumen generado por IA</h3>
                        <p>{!! nl2br(e($resumenIA)) !!}</p>
                        <small class="texto-suave">Texto redactado automáticamente a partir de los totales de este reporte (la IA no recibió datos de pacientes). Verifique las cifras en las tablas.</small>
                    </section>
                @endif

                <section aria-labelledby="titulo-totales">
                    <h3 id="titulo-totales">Totales</h3>
                    <dl class="totales-reporte">
                        @foreach ($reporte['resumen'] as [$etiqueta, $dato])
                            <div class="totales-reporte__dato"><dt>{{ $etiqueta }}</dt><dd>{{ $dato }}</dd></div>
                        @endforeach
                    </dl>
                </section>

                @foreach ($reporte['grupos'] as $grupo)
                    <section aria-label="{{ $grupo['titulo'] }}">
                        <h3>{{ $grupo['titulo'] }}</h3>
                        <div class="tabla-responsive">
                            <table class="tabla tabla-reporte">
                                <thead><tr>@foreach ($grupo['columnas'] as $columna)<th>{{ $columna }}</th>@endforeach</tr></thead>
                                <tbody>
                                    @foreach ($grupo['filas'] as $fila)
                                        <tr>@foreach ($fila as $celda)<td>{{ $celda }}</td>@endforeach</tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </section>
                @endforeach

                <section aria-labelledby="titulo-detalle">
                    <h3 id="titulo-detalle">Detalle <span class="chip">{{ $reporte['total_filas'] }} {{ $reporte['total_filas'] === 1 ? 'registro' : 'registros' }}</span></h3>
                    <div class="tabla-responsive">
                        <table class="tabla tabla-reporte">
                            <thead><tr>@foreach ($reporte['columnas'] as $columna)<th>{{ $columna['etiqueta'] }}</th>@endforeach</tr></thead>
                            <tbody>
                                @foreach ($reporte['filas'] as $fila)
                                    <tr>
                                        @foreach ($reporte['columnas'] as $columna)
                                            <td @class(['num' => $columna['tipo'] === 'monto'])>
                                                @if ($columna['tipo'] === 'estado')
                                                    <x-estado-cita :estado="explode('|', $fila['estado'])[0]" :vencida="str_contains($fila['estado'], '|VENCIDA')" />
                                                @else
                                                    {{ ReporteService::textoCelda($columna['tipo'], $fila[$columna['clave']] ?? null) }}
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($reporte['truncado'])
                        <p class="texto-suave sin-margen">Se muestran los primeros {{ $reporte['limite'] }} registros de {{ $reporte['total_filas'] }}. Los totales incluyen todos; exporte el reporte para ver el detalle completo.</p>
                    @endif
                </section>
            @endif
        </article>
    @endif
    </div>
@endsection
