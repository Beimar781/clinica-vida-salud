<?php

namespace App\Modules\GestionCitas\CU10_ReprogramarCitaMedica;

use App\Http\Controllers\Controller;
use App\Models\Cita;
use App\Modules\GestionCitas\Compartido\HorarioOcupadoException;
use App\Modules\GestionCitas\Compartido\ReglaCitaException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * CU-10: Reprogramar cita médica (paquete P4 Gestión de Citas, RF10). Solo la Recepcionista.
 * Incluye CU-08 (horarios libres del mismo médico) con el mismo DisponibilidadService.
 *
 * Precondición: la cita existe y está en estado Programada o Confirmada.
 *
 * Flujo de suceso (ficha 4.3 del documento):
 *  1. La recepcionista accede a «Citas» y busca la cita por código, CI
 *     o nombre del paciente.                                            -> index() con ReprogramarCitaService::buscar()
 *  2. El sistema muestra los datos de la cita y verifica que pueda
 *     reprogramarse.                                                    -> reprogramar() con ReprogramarCitaService::puedeReprogramarse()
 *  3. Selecciona la opción «Reprogramar» e indica la nueva fecha.       -> botón «Reprogramar» de buscar.blade.php y fecha de reprogramar.blade.php
 *  4. El sistema consulta la disponibilidad (CU-08) y muestra
 *     únicamente los horarios libres del médico.                        -> reprogramar() con ReprogramarCitaService::calcularLibres()
 *  5. Selecciona el nuevo horario, ingresa el motivo del cambio y
 *     confirma.                                                         -> formulario de reprogramar.blade.php (ReprogramarCitaRequest)
 *  6. El sistema vuelve a verificar la disponibilidad dentro de una
 *     transacción, actualiza la fecha y la hora, deja la cita en estado
 *     Programada y libera el horario anterior.                          -> actualizar() -> ReprogramarCitaService::reprogramar() -> AgendarCitaService::mover() (lockForUpdate)
 *  7. El sistema muestra el comprobante actualizado.                    -> actualizar() redirige a comprobante()
 *
 * Excepciones:
 *  - La cita está Atendida, Cancelada o con Inasistencia: no se puede reprogramar y se sugiere registrar una cita
 *    nueva (CU-09), según la política del sistema (reprogramar() y actualizar()).
 *  - El nuevo horario fue ocupado mientras tanto: se rechaza el cambio y se vuelven a mostrar los horarios libres.
 *  - La fecha es pasada, feriado o día sin atención, o el médico no atiende ese día: se indica y no se muestran horarios.
 *
 * @CU CU-10 @RF RF10 @CI CIReprogramarCita @CC CCReprogramarCita @CE CECita, CEMedico
 */
class ReprogramarCitaController extends Controller
{
    public function __construct(private readonly ReprogramarCitaService $citas) {}

    /**
     * Paso 1: busca la cita por código, CI o nombre del paciente (sin texto, lista las citas vigentes).
     *
     * @CI CIReprogramarCita (buscarCita) @CC CCReprogramarCita (buscar) @CE CECita (obtener)
     */
    public function index(Request $request): View
    {
        return view('gestioncitas::CU10_ReprogramarCitaMedica.buscar', [
            'citas' => $this->citas->buscar($request->query('q')),
            'buscar' => (string) $request->query('q'),
            'servicio' => $this->citas,
        ]);
    }

    /**
     * Pasos 2 a 4: muestra los datos de la cita, verifica que pueda reprogramarse y, con la nueva fecha, los horarios libres.
     *
     * @CI CIReprogramarCita (buscarCita, mostrarHorarios) @CC CCReprogramarCita (calcularLibres) @CE CECita (obtener, citasDelDia), CEMedico (horariosLibres)
     */
    public function reprogramar(Request $request, Cita $cita): View
    {
        $cita->load(['paciente', 'medico', 'especialidad', 'creador']);
        $puede = $this->citas->puedeReprogramarse($cita);
        $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('fecha')) ? (string) $request->query('fecha') : null;

        return view('gestioncitas::CU10_ReprogramarCitaMedica.reprogramar', [
            'cita' => $cita,
            'puede' => $puede,
            'motivoNo' => $puede ? null : $this->citas->motivoNoReprogramable($cita),
            'fecha' => $fecha,
            'resultado' => $puede && $fecha ? $this->citas->calcularLibres($cita, $fecha) : null,
        ]);
    }

    /**
     * Pasos 5 a 7: valida (ReprogramarCitaRequest), reprograma dentro de una transacción y muestra el comprobante.
     * Si el horario ya fue ocupado o una regla lo impide, vuelve al paso 4 con el aviso y los horarios libres actualizados.
     *
     * @CI CIReprogramarCita (confirmarCambio, mostrarComprobante) @CC CCReprogramarCita (reprogramar) @CE CECita (reprogramar)
     */
    public function actualizar(ReprogramarCitaRequest $request, Cita $cita): RedirectResponse
    {
        $volver = redirect()->route('citas.reprogramar', ['cita' => $cita->id, 'fecha' => $request->string('fecha')->toString()]);

        try {
            $resultado = $this->citas->reprogramar(
                $cita,
                $request->string('fecha')->toString(),
                $request->string('hora_inicio')->toString(),
                $request->string('motivo_cambio')->toString(),
                $request->user(),
            );
        } catch (HorarioOcupadoException|ReglaCitaException $e) {
            return $volver->withInput()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $volver->withInput()->with('error', 'No se pudo reprogramar la cita por un problema interno. Intente nuevamente; si el problema continúa, avise al administrador.');
        }

        return redirect()->route('citas.reprogramada', $cita)
            ->with('exito', "Cita {$cita->codigo} reprogramada correctamente.")
            ->with('reprogramacion', ['anterior' => $resultado['anterior'], 'motivo' => $resultado['motivo']]);
    }

    /**
     * Paso 7: comprobante actualizado (con el horario anterior y el motivo si se acaba de reprogramar).
     *
     * @CI CIReprogramarCita (mostrarComprobante) @CE CECita
     */
    public function comprobante(Request $request, Cita $cita): View
    {
        return view('gestioncitas::CU10_ReprogramarCitaMedica.comprobante', [
            'cita' => $cita->load(['paciente', 'medico', 'especialidad', 'creador']),
            'reprogramacion' => $request->session()->get('reprogramacion'),
        ]);
    }
}
