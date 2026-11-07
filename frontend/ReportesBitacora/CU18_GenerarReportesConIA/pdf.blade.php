{{-- @CU CU-18 @RF RF18 @CI CIReportes (exportar) @CC CCReportes (exportar) @CE CECita, CEPaciente, CEMedico
     Vista del PDF (dompdf): mismo detalle y mismos totales que la pantalla, solo CSS en línea y fuente DejaVu Sans (sin recursos externos). --}}
@php
    use App\Modules\ReportesBitacora\CU18_GenerarReportesConIA\ReporteService;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $reporte['titulo'] }}</title>
    <style>
        @page { margin: 28px 30px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #1F3347; }
        h1 { font-size: 16px; color: #0B3D75; margin: 0 0 2px; }
        h2 { font-size: 11px; color: #0B3D75; margin: 14px 0 4px; }
        .marca { color: #0A7BC4; font-weight: bold; font-size: 10px; margin-bottom: 6px; }
        .meta { color: #5B7287; margin: 0 0 2px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        th { background: #DCEFFB; color: #0B3D75; text-align: left; padding: 4px 5px; border: 1px solid #BFE0F5; }
        td { padding: 3px 5px; border: 1px solid #D5E5F2; vertical-align: top; }
        tr:nth-child(even) td { background: #F8FBFE; }
        .num { text-align: right; white-space: nowrap; }
        .totales td:first-child { width: 70%; }
        .ia { border: 1px solid #BFE0F5; background: #F1F8FD; padding: 6px 8px; margin: 8px 0; }
        .ia strong { color: #0B3D75; }
        .nota { color: #5B7287; font-size: 8px; margin-top: 4px; }
    </style>
</head>
<body>
    <div class="marca">Clínica Vida Salud</div>
    <h1>{{ $reporte['titulo'] }}</h1>
    <p class="meta">Período: <strong>{{ $reporte['periodo'] }}</strong>@foreach ($reporte['filtros'] as $filtro) · {{ $filtro }}@endforeach</p>
    <p class="meta">Generado el {{ $reporte['generado'] }}</p>

    @if ($reporte['vacio'])
        <p>{{ $reporte['mensaje_vacio'] }}</p>
    @else
        @if ($resumenIA)
            <div class="ia"><strong>Resumen generado por IA</strong><br>{!! nl2br(e($resumenIA)) !!}
                <div class="nota">Texto redactado automáticamente a partir de los totales del reporte (la IA no recibió datos de pacientes).</div>
            </div>
        @endif

        <h2>Detalle ({{ $reporte['total_filas'] }} {{ $reporte['total_filas'] === 1 ? 'registro' : 'registros' }})</h2>
        <table>
            <thead><tr>@foreach ($reporte['columnas'] as $columna)<th>{{ $columna['etiqueta'] }}</th>@endforeach</tr></thead>
            <tbody>
                @foreach ($reporte['filas'] as $fila)
                    <tr>
                        @foreach ($reporte['columnas'] as $columna)
                            <td @class(['num' => $columna['tipo'] === 'monto'])>{{ ReporteService::textoCelda($columna['tipo'], $fila[$columna['clave']] ?? null) }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if ($reporte['truncado'])
            <p class="nota">El PDF muestra los primeros {{ $reporte['limite'] }} registros de {{ $reporte['total_filas'] }}; los totales incluyen todos. El CSV trae el detalle completo.</p>
        @endif

        <h2>Totales</h2>
        <table class="totales">
            @foreach ($reporte['resumen'] as [$etiqueta, $dato])
                <tr><td>{{ $etiqueta }}</td><td class="num"><strong>{{ $dato }}</strong></td></tr>
            @endforeach
        </table>

        @foreach ($reporte['grupos'] as $grupo)
            <h2>{{ $grupo['titulo'] }}</h2>
            <table>
                <thead><tr>@foreach ($grupo['columnas'] as $columna)<th>{{ $columna }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach ($grupo['filas'] as $fila)
                        <tr>@foreach ($fila as $celda)<td>{{ $celda }}</td>@endforeach</tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach
    @endif
</body>
</html>
