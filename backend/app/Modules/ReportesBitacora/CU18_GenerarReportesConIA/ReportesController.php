<?php

namespace App\Modules\ReportesBitacora\CU18_GenerarReportesConIA;

use App\Http\Controllers\Controller;
use App\Models\Especialidad;
use App\Models\Medico;
use App\Services\BitacoraService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CU-18: Generar reportes con IA (paquete P6 Reportes y Bitácora, RF18). Administrador y Recepcionista. Solo consulta: no modifica datos
 * (salvo el registro en la bitácora).
 *
 * Precondición: el usuario autenticado es Administrador o Recepcionista.
 *
 * Flujo de suceso (ficha 4.3 del documento):
 *  1. El usuario accede a «Reportes».                                            -> index() (GET /reportes)
 *  2. Selecciona el tipo de reporte (citas de una fecha, por médico, por
 *     especialidad, por estado, pacientes registrados, atendidas, canceladas e
 *     inasistencias del período, ingresos por método de pago o agenda diaria del
 *     médico), la fecha o rango y, opcionalmente, el médico, la especialidad o el
 *     estado, y elige sin IA o con IA.                                           -> index.blade.php (CIReportes)
 *  3. El sistema valida los filtros y consulta las citas del período.            -> ReporteRequest y ReporteService::generar()
 *  4. El sistema calcula los totales (programadas, atendidas, canceladas,
 *     inasistencias e ingresos por método de pago).                              -> ReporteService::generar() (calcularTotales)
 *  5. El sistema muestra el reporte con su detalle y sus totales. Con IA, envía
 *     solo los totales calculados (sin datos personales) y agrega el resumen.    -> index() + ResumenIAService::resumir()
 *  6. El usuario puede imprimir el reporte o exportarlo a CSV o PDF.             -> botón Imprimir (CSS @media print) y exportar()
 *
 * Excepciones:
 *  - La fecha final es anterior a la inicial: se indica y no se genera el reporte (ReporteRequest).
 *  - No hay citas en el período: reporte vacío con el mensaje correspondiente.
 *  - La IA no responde, tarda más de 20 s o no hay clave: el reporte se muestra sin resumen y con el aviso
 *    «No se pudo generar el resumen con IA» (error al log, nunca un 500).
 *  - Exportar: descarga el CSV o el PDF con los mismos filtros, detalle y totales; incluye el resumen de IA solo si se eligió esa
 *    modalidad y ya se generó en pantalla.
 *  - Médicos y pacientes no tienen acceso (403).
 *
 * @CU CU-18 @RF RF18 @CI CIReportes @CC CCReportes @CE CECita, CEPaciente, CEMedico
 */
class ReportesController extends Controller
{
    /** Cuántos resúmenes de IA se recuerdan en la sesión para reutilizarlos al exportar. */
    private const RESUMENES_EN_SESION = 6;

    public function __construct(
        private readonly ReporteService $reportes,
        private readonly ResumenIAService $ia,
    ) {}

    /**
     * Pasos 1 a 5: muestra el formulario de filtros y, si se enviaron filtros válidos, el reporte con sus totales.
     *
     * @CI CIReportes (elegirFiltros, mostrarReporte, generarConIA) @CC CCReportes (generar, resumirConIA)
     */
    public function index(ReporteRequest $request): View
    {
        $filtros = $request->filtros();
        $datos = [
            'filtros' => $filtros,
            'tipos' => ReporteService::TIPOS,
            'unDia' => ReporteService::UN_DIA,
            'estados' => ReporteService::estados(),
            'medicos' => Medico::orderBy('apellidos')->orderBy('nombres')->get(['id', 'nombres', 'apellidos', 'estado']),
            'especialidades' => Especialidad::orderBy('nombre')->get(['id', 'nombre', 'estado']),
            'reporte' => null, 'resumenIA' => null, 'avisoIA' => null,
        ];

        if ($filtros === null) {
            return view('reportesbitacora::CU18_GenerarReportesConIA.index', $datos);
        }

        $reporte = $this->reportes->generar($filtros);
        $resumen = null;
        $aviso = null;

        // Con IA: solo si hay datos que resumir; la IA recibe únicamente los totales (ReporteService::totalesParaIA).
        if ($filtros['ia'] === 'con' && ! $reporte['vacio']) {
            $resumen = $this->ia->resumir($this->reportes->totalesParaIA($reporte));
            if ($resumen === null) {
                $aviso = ResumenIAService::AVISO_SIN_RESUMEN;
            } else {
                $this->recordarResumen($filtros, $resumen);
            }
        }

        BitacoraService::registrar(
            'REPORTE_GENERADO', 'Reporte',
            "{$reporte['titulo']}: {$reporte['periodo']}; {$reporte['total_filas']} registros; ".($filtros['ia'] === 'con'
                ? ($resumen !== null ? 'con IA (resumen generado)' : 'con IA (no disponible, sin resumen)')
                : 'sin IA')
        );

        return view('reportesbitacora::CU18_GenerarReportesConIA.index', [
            'reporte' => $reporte, 'resumenIA' => $resumen, 'avisoIA' => $aviso,
        ] + $datos);
    }

    /**
     * Paso 6: descarga el reporte con los mismos filtros, detalle y totales, en CSV (streaming) o PDF.
     *
     * @CI CIReportes (exportar) @CC CCReportes (exportar)
     */
    public function exportar(ReporteRequest $request, string $formato): StreamedResponse|Response
    {
        abort_unless(in_array($formato, ['csv', 'pdf'], true) && $request->conFiltros(), 404);

        $filtros = $request->filtros();
        $reporte = $this->reportes->generar($filtros, $formato === 'pdf' ? ReporteService::LIMITE_PDF : null);
        // El resumen de IA solo se incluye si se eligió la modalidad con IA y ya se generó en pantalla (no se vuelve a llamar a la IA).
        $resumen = $filtros['ia'] === 'con' ? $this->resumenRecordado($filtros) : null;

        BitacoraService::registrar(
            'REPORTE_EXPORTADO', 'Reporte',
            "{$reporte['titulo']}: {$reporte['periodo']}; ".strtoupper($formato).($resumen !== null ? ' con resumen de IA' : '')
        );

        $nombre = $this->reportes->nombreArchivo($reporte, $formato);

        if ($formato === 'pdf') {
            return Pdf::loadView('reportesbitacora::CU18_GenerarReportesConIA.pdf', ['reporte' => $reporte, 'resumenIA' => $resumen])
                ->setPaper('a4', 'landscape')
                ->download($nombre);
        }

        return response()->streamDownload(function () use ($reporte, $filtros, $resumen) {
            $salida = fopen('php://output', 'w');
            $this->reportes->escribirCsv($salida, $reporte, $this->reportes->detalleCompleto($filtros), $resumen);
            fclose($salida);
        }, $nombre, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Clave del resumen en la sesión: depende de los filtros (no de la modalidad). */
    private function claveResumen(array $filtros): string
    {
        return sha1(json_encode(array_diff_key($filtros, ['ia' => true])));
    }

    private function recordarResumen(array $filtros, string $resumen): void
    {
        $guardados = session('resumenes_ia', []);
        $guardados[$this->claveResumen($filtros)] = $resumen;
        session(['resumenes_ia' => array_slice($guardados, -self::RESUMENES_EN_SESION, null, true)]);
    }

    private function resumenRecordado(array $filtros): ?string
    {
        return session('resumenes_ia', [])[$this->claveResumen($filtros)] ?? null;
    }
}
