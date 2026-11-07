<?php

namespace App\Modules\AtencionClinica\CU16_RegistrarConsultaTratamiento;

use App\Http\Controllers\Controller;
use App\Models\Cita;
use App\Modules\GestionCitas\Compartido\ReglaCitaException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * CU-16: Registrar consulta y tratamiento (paquete P5 Atención Clínica, RF16). Solo el Médico.
 *
 * Precondición: el usuario es Médico con permiso para marcar citas como atendidas; la cita es suya y está en estado Programada o
 * Confirmada.
 *
 * Flujo de suceso (ficha 4.3 del documento):
 *  1. El médico accede a su agenda y selecciona la cita que atiende.      -> botón «Registrar consulta» de la agenda médica (CU-14)
 *  2. El sistema muestra los datos del paciente y de la cita.              -> mostrar() (GET /consultas/{cita}) con ConsultaService::abrir()
 *  3. El médico selecciona «Registrar consulta».                           -> formulario de registrar.blade.php
 *  4. Ingresa el diagnóstico, el tratamiento y las observaciones.          -> ConsultaRequest
 *  5. El sistema valida los datos y guarda la consulta asociada a la cita
 *     y a la historia clínica del paciente.                                -> guardar() (POST /consultas/{cita}) -> ConsultaService::registrar()
 *  6. El sistema cambia el estado de la cita a Atendida y muestra la
 *     consulta registrada.                                                 -> registrar() (misma transacción, TransicionesCita) y redirección a mostrar()
 *
 * Excepciones:
 *  - El diagnóstico está vacío: se solicita y no se guarda (ConsultaRequest).
 *  - La cita no pertenece al médico o está cancelada: no se permite registrar la consulta (ConsultaService).
 *  - El médico no tiene el permiso de marcar citas como atendidas: no se permite registrar la consulta.
 *  - La cita ya tiene una consulta: se muestra la existente y se permite corregirla -> actualizar() (PUT /consultas/{cita}).
 *
 * @CU CU-16 @RF RF16 @CI CIConsulta @CC CCConsulta @CE CECita, CEConsulta, CEHistoriaClinica
 */
class ConsultaController extends Controller
{
    public function __construct(private readonly ConsultaService $consultas) {}

    /**
     * Pasos 2 y 3: muestra los datos del paciente y de la cita y el formulario (vacío, o con la consulta existente para corregirla).
     *
     * @CI CIConsulta (mostrarCita) @CC CCConsulta (abrir) @CE CECita (obtener), CEConsulta (obtenerPorCita), CEHistoriaClinica (obtenerPorPaciente)
     */
    public function mostrar(Request $request, Cita $cita): View|RedirectResponse
    {
        try {
            $consulta = $this->consultas->abrir($cita, $request->user());
        } catch (ReglaCitaException $e) {
            return $this->aLaAgenda($e->getMessage());
        }

        $cita->load(['paciente.historiaClinica', 'medico', 'especialidad']);

        return view('atencionclinica::CU16_RegistrarConsultaTratamiento.registrar', ['cita' => $cita, 'consulta' => $consulta]);
    }

    /**
     * Pasos 4 a 6: valida el diagnóstico, guarda la consulta en la historia clínica y pasa la cita a Atendida.
     *
     * @CI CIConsulta (enviarConsulta, mostrarResultado) @CC CCConsulta (registrar) @CE CEConsulta (crear), CECita (cambiarEstado)
     */
    public function guardar(ConsultaRequest $request, Cita $cita): RedirectResponse
    {
        try {
            $this->consultas->registrar($cita, $request->user(), $request->validated());
        } catch (ReglaCitaException $e) {
            return $this->aLaAgenda($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('consultas.mostrar', $cita)->withInput()->with('error', 'No se pudo guardar la consulta por un problema interno. Intente nuevamente; si el problema continúa, avise al administrador.');
        }

        return redirect()->route('consultas.mostrar', $cita)->with('exito', "Consulta registrada en la historia clínica. La cita {$cita->codigo} pasó a Atendida.");
    }

    /**
     * Excepción de la consulta existente: la corrige (la cita no cambia de estado).
     *
     * @CI CIConsulta (enviarConsulta, mostrarResultado) @CC CCConsulta (registrar) @CE CEConsulta (obtenerPorCita)
     */
    public function actualizar(ConsultaRequest $request, Cita $cita): RedirectResponse
    {
        try {
            $this->consultas->corregir($cita, $request->user(), $request->validated());
        } catch (ReglaCitaException $e) {
            return $this->aLaAgenda($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('consultas.mostrar', $cita)->withInput()->with('error', 'No se pudo corregir la consulta por un problema interno. Intente nuevamente; si el problema continúa, avise al administrador.');
        }

        return redirect()->route('consultas.mostrar', $cita)->with('exito', 'Consulta corregida. La cita sigue Atendida.');
    }

    /** Sin permiso, cita ajena o en un estado que no admite consulta: vuelve a la agenda con el motivo (sin mostrar datos clínicos). */
    private function aLaAgenda(string $motivo): RedirectResponse
    {
        return redirect()->route('agenda.index')->with('error', $motivo);
    }
}
