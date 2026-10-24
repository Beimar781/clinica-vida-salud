<?php

namespace App\Modules\GestionCitas\CU11_ReservarCitaEnLinea;

use App\Http\Controllers\Controller;
use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Medico;
use App\Modules\GestionCitas\Compartido\CatalogoMedicosService;
use App\Modules\GestionCitas\Compartido\HorarioOcupadoException;
use App\Modules\GestionCitas\Compartido\ReglaCitaException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * CU-11: Reservar cita en línea (paquete P4 Gestión de Citas, RF11). Solo el Paciente autenticado.
 * Incluye CU-08 (horarios libres) con el mismo DisponibilidadService.
 *
 * Precondición: el usuario autenticado es Paciente y está registrado y activo; existen médicos activos con horarios.
 *
 * Flujo de suceso (ficha 4.3 del documento):
 *  1. El paciente accede a «Reservar cita».                               -> buscar() (pantalla buscar.blade.php)
 *  2. Selecciona la especialidad y, opcionalmente, la ubicación; el
 *     sistema muestra los médicos activos que coinciden.                  -> buscar() con ReservarCitaService::buscarMedicos()
 *  3. Selecciona el médico y la fecha.                                    -> reservar() (pantalla reservar.blade.php)
 *  4. El sistema consulta la disponibilidad (CU-08) y muestra
 *     únicamente los horarios libres.                                     -> reservar() con ReservarCitaService::calcularLibres()
 *  5. Selecciona un horario y la modalidad (presencial o virtual),
 *     ingresa opcionalmente el motivo y confirma.                         -> formulario de reservar.blade.php (ReservarCitaRequest)
 *  6. El sistema vuelve a verificar la disponibilidad dentro de una
 *     transacción, genera el código, guarda la cita en estado
 *     Programada y muestra el comprobante.                                -> guardar() -> ReservarCitaService::reservar() -> AgendarCitaService::crear() (lockForUpdate); redirige a comprobante()
 *
 * Excepciones:
 *  - El horario fue ocupado mientras tanto: se rechaza la reserva y se vuelven a mostrar los horarios libres.
 *  - El paciente ya tiene una cita a esa misma hora: no se permite la reserva.
 *  - La fecha es pasada, feriado o día sin atención, o el médico no atiende ese día: se indica y no se muestran horarios.
 *  - Ningún médico coincide con la especialidad y la ubicación elegidas: se indica y se permite cambiar la búsqueda.
 *
 * La cita siempre queda a nombre del paciente de la cuenta autenticada (nunca de un dato enviado en el formulario).
 *
 * @CU CU-11 @RF RF11 @CI CIReservarCita @CC CCReservarCita @CE CECita, CEMedico, CEPaciente
 */
class ReservarCitaController extends Controller
{
    public function __construct(
        private readonly ReservarCitaService $reservas,
        private readonly CatalogoMedicosService $catalogo,
    ) {}

    /**
     * Pasos 1 y 2: busca médicos activos por especialidad y ubicación y muestra su próximo horario libre.
     *
     * @CI CIReservarCita (buscarMedicos) @CC CCReservarCita (buscar) @CE CEMedico (buscar, horariosLibres)
     */
    public function buscar(Request $request): View
    {
        $especialidad = $request->filled('especialidad') ? Especialidad::find($request->integer('especialidad')) : null;
        $ubicacion = $request->filled('ubicacion') ? (string) $request->query('ubicacion') : null;
        $paciente = $this->reservas->pacienteDe($request->user());

        return view('gestioncitas::CU11_ReservarCitaEnLinea.buscar', [
            'impedimento' => $this->reservas->impedimento($paciente),
            'especialidades' => $this->catalogo->especialidadesConMedicos(),
            'especialidad' => $especialidad,
            'ubicaciones' => $this->catalogo->ubicaciones($especialidad?->id),
            'ubicacion' => $ubicacion,
            'resultados' => $especialidad ? $this->reservas->buscarMedicos($especialidad->id, $ubicacion) : null,
        ]);
    }

    /**
     * Pasos 3 a 5: con el médico elegido muestra la fecha, los horarios libres y el formulario para confirmar.
     *
     * @CI CIReservarCita (mostrarHorarios) @CC CCReservarCita (calcularLibres) @CE CECita (citasDelDia), CEMedico (horariosLibres)
     */
    public function reservar(Request $request): View|RedirectResponse
    {
        $especialidad = Especialidad::find($request->integer('especialidad'));
        $medico = Medico::with('especialidades')->where('estado', true)->find($request->integer('medico'));

        if (! $especialidad || ! $medico) {
            return redirect()->route('reservas.crear');
        }

        $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('fecha')) ? (string) $request->query('fecha') : null;
        $impedimento = $this->reservas->impedimento($this->reservas->pacienteDe($request->user()));

        return view('gestioncitas::CU11_ReservarCitaEnLinea.reservar', [
            'impedimento' => $impedimento,
            'especialidad' => $especialidad,
            'medico' => $medico,
            'fecha' => $fecha,
            'resultado' => ! $impedimento && $fecha ? $this->reservas->calcularLibres($medico, $fecha) : null,
        ]);
    }

    /**
     * Paso 6: valida (ReservarCitaRequest), reserva dentro de una transacción a nombre del paciente autenticado y muestra el comprobante.
     * Si el horario ya fue ocupado o una regla lo impide, vuelve al paso 4 con el aviso y los horarios libres actualizados.
     *
     * @CI CIReservarCita (confirmarReserva, mostrarComprobante) @CC CCReservarCita (reservar) @CE CECita (crear)
     */
    public function guardar(ReservarCitaRequest $request): RedirectResponse
    {
        $volver = redirect()->route('reservas.reservar', [
            'especialidad' => $request->integer('especialidad_id'), 'medico' => $request->integer('medico_id'),
            'fecha' => $request->string('fecha')->toString(),
        ]);

        $paciente = $this->reservas->pacienteDe($request->user());
        if ($impedimento = $this->reservas->impedimento($paciente)) {
            return $volver->with('error', $impedimento);
        }

        try {
            $cita = $this->reservas->reservar(
                $paciente,
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
            report($e);

            return $volver->withInput()->with('error', 'No se pudo reservar la cita por un problema interno. Intente nuevamente; si el problema continúa, comuníquese con la clínica.');
        }

        return redirect()->route('reservas.comprobante', $cita)->with('exito', "Su cita {$cita->codigo} fue reservada correctamente.");
    }

    /**
     * Paso 6: comprobante de la reserva. Cada paciente solo ve los de sus propias citas.
     *
     * @CI CIReservarCita (mostrarComprobante) @CE CECita
     */
    public function comprobante(Request $request, Cita $cita): View
    {
        $cita->load(['paciente', 'medico', 'especialidad', 'creador']);
        abort_unless($cita->paciente->usuario_id === $request->user()->id, 403, 'Solo puede ver los comprobantes de sus propias citas.');

        return view('gestioncitas::CU11_ReservarCitaEnLinea.comprobante', ['cita' => $cita]);
    }
}
