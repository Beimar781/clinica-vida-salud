<?php

namespace App\Modules\GestionPacientes\CU15_ConsultarHistorialCitasPaciente;

use App\Http\Controllers\Controller;
use App\Models\Paciente;
use App\Modules\GestionCitas\CU12_CancelarCitaMedica\CancelarCitaService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * CU-15: Consultar historial de citas del paciente (paquete P3 Gestión de Pacientes, RF15). Recepcionista, Administrador
 * (hereda recepción) y Paciente («Mis citas»). Solo consulta: no modifica datos.
 *
 * Precondición: el usuario es Recepcionista, Administrador o Paciente; el paciente está registrado.
 *
 * Flujo de suceso (ficha 4.3 del documento):
 *  1. El usuario accede a «Historial de citas».                            -> index() (GET /historial-citas; «Mis citas» para el paciente)
 *  2. La recepcionista o el administrador buscan al paciente por CI o
 *     nombre; el paciente ve directamente su propio historial.             -> index() con HistorialCitasService::buscarPacientes() o pacienteDe()
 *  3. El sistema consulta las citas del paciente.                          -> HistorialCitasService::citas()
 *  4. El sistema muestra el historial con fecha y hora, médico,
 *     especialidad, estado y fecha de registro, con filtros por estado
 *     (pendientes, confirmadas, atendidas y canceladas) y por rango de
 *     fechas.                                                              -> historial.blade.php
 *
 * Excepciones:
 *  - El paciente no tiene citas: «El paciente aún no tiene citas».
 *  - Un paciente no puede consultar el historial de otro paciente: la ficha siempre sale de su cuenta (nunca de la URL) y si
 *    pide el identificador de otro se le responde acceso denegado (403).
 *
 * @CU CU-15 @RF RF15 @CI CIHistorial @CC CCHistorial @CE CEPaciente, CECita
 */
class HistorialController extends Controller
{
    public function __construct(
        private readonly HistorialCitasService $historial,
        private readonly CancelarCitaService $cancelaciones,
    ) {}

    /**
     * Pasos 1 a 4: busca al paciente (personal) o toma el de la cuenta (paciente) y muestra su historial con los filtros.
     *
     * @CI CIHistorial (buscarPaciente, aplicarFiltros, mostrarHistorial) @CC CCHistorial (buscar, consultar, filtrar) @CE CEPaciente (buscar), CECita (citasDelPaciente)
     */
    public function index(Request $request): View
    {
        $usuario = $request->user();
        $esPersonal = $this->historial->esPersonal($usuario);
        $buscar = $esPersonal ? (string) $request->query('q') : '';
        $encontrados = $esPersonal ? $this->historial->buscarPacientes($buscar) : collect();

        if ($esPersonal) {
            $paciente = $request->filled('paciente') ? Paciente::find($request->integer('paciente')) : null;
            // Una sola coincidencia: se muestra directamente su historial.
            $paciente ??= $encontrados->count() === 1 ? $encontrados->first() : null;
        } else {
            $paciente = $this->historial->pacienteDe($usuario);
            // Un paciente nunca consulta el historial de otro: la ficha sale de su cuenta, no de la URL.
            if ($request->filled('paciente') && (! $paciente || $request->integer('paciente') !== $paciente->id)) {
                abort(403, 'Solo puede consultar su propio historial de citas.');
            }
        }

        $filtro = array_key_exists((string) $request->query('estado'), HistorialCitasService::FILTROS) ? (string) $request->query('estado') : '';
        $desde = $this->fecha($request->query('desde'));
        $hasta = $this->fecha($request->query('hasta'));
        $errorRango = $this->historial->errorDeRango($desde, $hasta);

        return view('gestionpacientes::CU15_ConsultarHistorialCitasPaciente.historial', [
            'esPersonal' => $esPersonal,
            'buscar' => $buscar,
            'encontrados' => $encontrados,
            'paciente' => $paciente,
            'filtro' => $filtro,
            'desde' => $desde,
            'hasta' => $hasta,
            'errorRango' => $errorRango,
            'citas' => $paciente && ! $errorRango ? $this->historial->citas($paciente, $filtro, $desde, $hasta) : null,
            'cancelaciones' => $this->cancelaciones,
            'usuario' => $usuario,
        ]);
    }

    /** «YYYY-MM-DD» válida o null. */
    private function fecha(mixed $valor): ?string
    {
        $texto = (string) $valor;

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) && Carbon::createFromFormat('Y-m-d', $texto)?->format('Y-m-d') === $texto ? $texto : null;
    }
}
