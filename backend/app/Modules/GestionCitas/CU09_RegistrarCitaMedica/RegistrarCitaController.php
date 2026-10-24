<?php

namespace App\Modules\GestionCitas\CU09_RegistrarCitaMedica;

use App\Http\Controllers\Controller;
use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Medico;
use App\Models\Paciente;
use App\Modules\GestionCitas\Compartido\CatalogoMedicosService;
use App\Modules\GestionCitas\Compartido\HorarioOcupadoException;
use App\Modules\GestionCitas\Compartido\ReglaCitaException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * CU-09: Registrar cita médica (paquete P4 Gestión de Citas, RF09). Solo la Recepcionista.
 * Incluye CU-08 (la consulta de horarios libres): se reutiliza el mismo cálculo de DisponibilidadService.
 *
 * Precondición: el paciente está registrado y activo; el médico está activo y tiene horario de atención.
 *
 * Flujo de suceso (ficha 4.3 del documento):
 *  1. La recepcionista accede a «Registrar cita» y busca al paciente
 *     por CI o nombre.                                                   -> index() con RegistrarCitaService::buscarPacientes()
 *  2. Selecciona la especialidad; el sistema muestra los médicos activos
 *     de esa especialidad.                                                -> index() con CatalogoMedicosService::medicosDe()
 *  3. Selecciona el médico y la fecha.                                    -> index() (formulario de registrar.blade.php)
 *  4. El sistema consulta la disponibilidad (CU-08) y muestra
 *     únicamente los horarios libres.                                     -> index() con RegistrarCitaService::calcularLibres()
 *  5. Selecciona un horario y la modalidad (presencial o virtual) e
 *     ingresa, opcionalmente, el motivo breve de la consulta.             -> formulario de registrar.blade.php
 *  6. Confirma el registro; el sistema vuelve a verificar la
 *     disponibilidad dentro de una transacción.                           -> guardar() -> RegistrarCitaService::registrar() -> AgendarCitaService::crear() (lockForUpdate)
 *  7. El sistema genera el código de la cita (CM-0001), registra la
 *     fecha de creación, la guarda con estado Programada y muestra el
 *     comprobante con el paciente, la especialidad, el médico, la
 *     fecha, el horario y el motivo.                                      -> guardar() redirige a comprobante()
 *
 * Excepciones:
 *  - El horario fue ocupado por otro usuario mientras tanto: se rechaza la operación, se explica el motivo y se
 *    vuelven a mostrar los horarios libres (HorarioOcupadoException; también la restricción EXCLUDE de la base, 23P01).
 *  - La fecha es pasada, feriado o día sin atención, o el médico no atiende ese día: se indica y no se muestran horarios.
 *  - El paciente está inactivo o ya tiene una cita a esa misma hora: no se permite continuar (ReglaCitaException).
 *  - Un error interno al guardar: el usuario ve un mensaje claro sin detalles técnicos y el error queda en el registro.
 *
 * @CU CU-09 @RF RF09 @CI CIRegistrarCita @CC CCRegistrarCita @CE CEPaciente, CECita, CEMedico
 */
class RegistrarCitaController extends Controller
{
    public const MENSAJE_ERROR_INTERNO = 'No se pudo registrar la cita por un problema interno. Intente nuevamente; si el problema continúa, avise al administrador.';

    public function __construct(
        private readonly RegistrarCitaService $citas,
        private readonly CatalogoMedicosService $catalogo,
    ) {}

    /**
     * Pasos 1 a 5: busca al paciente, ofrece especialidad, médico y fecha y muestra los horarios libres.
     *
     * @CI CIRegistrarCita (buscarPaciente, mostrarHorarios) @CC CCRegistrarCita (buscar, calcularLibres) @CE CEPaciente (buscar), CEMedico (horariosLibres)
     */
    public function index(Request $request): View
    {
        $busqueda = (string) $request->query('q');
        $paciente = $request->filled('paciente') ? Paciente::find($request->integer('paciente')) : null;
        $especialidad = $request->filled('especialidad') ? Especialidad::find($request->integer('especialidad')) : null;
        $medicos = $this->catalogo->medicosDe($especialidad?->id);
        $medico = $medicos->firstWhere('id', $request->integer('medico'));
        $fecha = $request->filled('fecha') && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('fecha')) ? (string) $request->query('fecha') : null;

        return view('gestioncitas::CU09_RegistrarCitaMedica.registrar', [
            'busqueda' => $busqueda,
            'pacientes' => $this->citas->buscarPacientes($busqueda),
            'paciente' => $paciente,
            'especialidades' => $this->catalogo->especialidadesConMedicos(),
            'especialidad' => $especialidad,
            'medicos' => $medicos,
            'medico' => $medico,
            'fecha' => $fecha,
            'resultado' => $paciente?->estado && $medico && $fecha ? $this->citas->calcularLibres($medico, $fecha) : null,
        ]);
    }

    /**
     * Pasos 6 y 7: valida (RegistrarCitaRequest), registra la cita dentro de una transacción y muestra el comprobante.
     * Si el horario ya fue ocupado o una regla lo impide, vuelve al paso 4 con el aviso y los horarios libres actualizados.
     *
     * @CI CIRegistrarCita (confirmarCita, mostrarComprobante) @CC CCRegistrarCita (validarReglas, registrar) @CE CECita (crear)
     */
    public function guardar(RegistrarCitaRequest $request): RedirectResponse
    {
        $volver = redirect()->route('citas.registrar', [
            'paciente' => $request->integer('paciente_id'), 'especialidad' => $request->integer('especialidad_id'),
            'medico' => $request->integer('medico_id'), 'fecha' => $request->string('fecha')->toString(),
        ]);

        try {
            $cita = $this->citas->registrar(
                Paciente::findOrFail($request->integer('paciente_id')),
                Especialidad::findOrFail($request->integer('especialidad_id')),
                Medico::findOrFail($request->integer('medico_id')),
                $request->string('fecha')->toString(),
                $request->string('hora_inicio')->toString(),
                $request->string('modalidad')->toString(),
                $request->input('motivo'),
                $request->user(),
            );
        } catch (HorarioOcupadoException|ReglaCitaException $e) {
            return $volver->withInput()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            // Error interno (por ejemplo, la base de datos): al usuario un mensaje claro, al registro el detalle.
            report($e);

            return $volver->withInput()->with('error', self::MENSAJE_ERROR_INTERNO);
        }

        return redirect()->route('citas.comprobante', $cita)->with('exito', "Cita {$cita->codigo} registrada correctamente.");
    }

    /**
     * Paso 7: comprobante con el paciente, la especialidad, el médico, la fecha, el horario y el motivo.
     *
     * @CI CIRegistrarCita (mostrarComprobante) @CE CECita
     */
    public function comprobante(Cita $cita): View
    {
        return view('gestioncitas::CU09_RegistrarCitaMedica.comprobante', ['cita' => $cita->load(['paciente', 'medico', 'especialidad', 'creador'])]);
    }
}
