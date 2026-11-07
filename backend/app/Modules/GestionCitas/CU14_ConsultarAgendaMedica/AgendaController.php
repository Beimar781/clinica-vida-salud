<?php

namespace App\Modules\GestionCitas\CU14_ConsultarAgendaMedica;

use App\Http\Controllers\Controller;
use App\Modules\GestionPacientes\CU15_ConsultarHistorialCitasPaciente\HistorialCitasService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * CU-14: Consultar agenda médica (paquete P4 Gestión de Citas, RF14). Médico, Recepcionista y Administrador. Solo consulta.
 *
 * Precondición: el usuario está autenticado con rol Médico, Recepcionista o Administrador.
 *
 * Flujo de suceso (ficha 4.3 del documento):
 *  1. El usuario accede a «Agenda médica».                                 -> index() (GET /agenda)
 *  2. Selecciona el médico (el médico ve solo su propia agenda), la fecha
 *     o el rango de fechas y, opcionalmente, el estado.                    -> filtros de agenda.blade.php (AgendaService::medicoConsultado())
 *  3. El sistema consulta las citas del médico y los horarios de
 *     atención del período.                                                -> AgendaService::citas() y libres() (el cálculo de CU-08)
 *  4. El sistema muestra la agenda ordenada por hora, con la hora, el
 *     código, el paciente, la especialidad, el estado y el motivo (si
 *     el usuario tiene permiso para verlo), e identifica las citas
 *     pendientes, atendidas y canceladas.                                  -> agenda.blade.php con AgendaService::resumen() y puedeVerMotivo()
 *  5. Al seleccionar una cita, muestra los datos básicos del paciente
 *     (nombre, edad, sexo y, si el paciente lo permite, su teléfono).      -> detalle de cada cita con AgendaService::puedeVerContacto()
 *
 * Excepciones:
 *  - No hay citas en el período: «No hay citas para la fecha seleccionada».
 *  - El rango de fechas es inválido (fin anterior al inicio o más de 31 días): se indica y no se consulta.
 *  - El usuario no tiene permiso para ver el motivo: se muestra el resto de los datos sin el motivo (puedeVerMotivo).
 *  - Un médico que elige a otro médico: el sistema solo muestra su propia agenda.
 *
 * @CU CU-14 @RF RF14 @CI CIAgenda @CC CCAgenda @CE CECita, CEMedico
 */
class AgendaController extends Controller
{
    public function __construct(private readonly AgendaService $agenda) {}

    /**
     * Pasos 1 a 5: elige el médico, la fecha o el rango y el estado y muestra la agenda con sus citas y, en un solo día, sus horarios libres.
     *
     * @CI CIAgenda (elegirFiltros, mostrarAgenda) @CC CCAgenda (consultar, filtrar) @CE CECita (citasPorPeriodo), CEMedico (horariosLibres)
     */
    public function index(Request $request): View
    {
        $usuario = $request->user();
        $veTodos = $this->agenda->veTodos($usuario);
        $desde = $this->fecha($request->query('desde')) ?? Carbon::today()->toDateString();
        $hasta = $this->fecha($request->query('hasta'));
        $filtro = array_key_exists((string) $request->query('estado'), HistorialCitasService::FILTROS) ? (string) $request->query('estado') : '';

        $medico = $this->agenda->medicoConsultado($usuario, $request->filled('medico') ? $request->integer('medico') : null);
        $errorRango = $this->agenda->errorDeRango($desde, $hasta);
        $consulta = $medico && ! $errorRango;
        $unSoloDia = blank($hasta) || $hasta === $desde;
        $citas = $consulta ? $this->agenda->citas($medico, $desde, $hasta, $filtro) : null;

        return view('gestioncitas::CU14_ConsultarAgendaMedica.agenda', [
            'veTodos' => $veTodos,
            'sinFichaMedico' => ! $veTodos && ! $medico,
            'medicos' => $this->agenda->medicosElegibles($usuario),
            'medico' => $medico,
            'desde' => $desde,
            'hasta' => $hasta,
            'filtro' => $filtro,
            'errorRango' => $errorRango,
            'citas' => $citas,
            'porDia' => $citas?->groupBy(fn ($cita) => $cita->fecha->toDateString()),
            'resumen' => $citas ? $this->agenda->resumen($citas) : null,
            'libres' => $consulta && $unSoloDia ? $this->agenda->libres($medico, $desde) : null,
            'servicio' => $this->agenda,
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
