<?php

namespace App\Modules\GestionCitas\CU13_ControlarAsistenciaEstadosCita;

use App\Http\Controllers\Controller;
use App\Models\Cita;
use App\Models\Medico;
use App\Modules\GestionCitas\Compartido\HorarioOcupadoException;
use App\Modules\GestionCitas\Compartido\ReglaCitaException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * CU-13: Controlar asistencia y estados de la cita (paquete P4 Gestión de Citas, RF13). Recepcionista, Médico y Administrador.
 *
 * Precondición: el usuario es Recepcionista, Médico o Administrador; existen citas registradas para el día.
 *
 * Flujo de suceso (ficha 4.3 del documento):
 *  1. El usuario accede a la agenda del día.                              -> index() (GET /control-citas, «Control de citas»)
 *  2. El sistema muestra las citas con su estado actual.                  -> index() con EstadoCitaService::listar() (filtros de fecha, estado y médico)
 *  3. Selecciona una cita y la acción por realizar: confirmar, registrar
 *     el cobro en efectivo, marcar como atendida, registrar inasistencia
 *     o, solo el administrador, corregir el estado.                       -> botones de control.blade.php -> cambiar() (POST /citas/{cita}/estado)
 *  4. El sistema valida que la transición sea permitida para el rol y
 *     el estado actual.                                                   -> EstadoCitaService::aplicar() con TransicionesCita::validarTransicion()
 *  5. El sistema actualiza el estado y registra quién lo cambió y cuándo. -> aplicar() (transacción con la cita bloqueada) y BitacoraService
 *  6. El sistema muestra la agenda con el estado actualizado.             -> cambiar() redirige a index() con los mismos filtros
 *
 * Excepciones:
 *  - Transición no permitida (por ejemplo, atender una cita cancelada): se rechaza y se informa el motivo.
 *  - Inasistencia antes de la hora de la cita: no se permite.
 *  - Médico: solo cambia el estado de sus propias citas (y solo ve las suyas).
 *  - Cita con pago Pendiente en efectivo: la recepcionista registra el cobro y el pago pasa a Pagado.
 *  - Inasistencia de una cita pagada: el pago se conserva y se informa que puede reprogramarse (CU-10).
 *  - Médico sin el permiso de marcar citas como atendidas: se rechaza esa acción.
 *  - El administrador corrige un estado fuera de las transiciones: exige una justificación y queda en la bitácora; si la
 *    cita vuelve a ocupar horario se verifica de nuevo la disponibilidad.
 *
 * @CU CU-13 @RF RF13 @CI CIEstadoCita @CC CCEstadoCita @CE CECita
 */
class EstadoCitaController extends Controller
{
    public function __construct(private readonly EstadoCitaService $estados) {}

    /**
     * Pasos 1 y 2: agenda del día con las citas, su estado y las acciones que el usuario puede hacer.
     *
     * @CI CIEstadoCita (mostrarAgenda) @CC CCEstadoCita (listar) @CE CECita (citasDelDia)
     */
    public function index(Request $request): View
    {
        $usuario = $request->user();
        $fecha = $this->fecha($request->query('fecha'));
        $estado = array_key_exists((string) $request->query('estado'), EstadoCitaService::ESTADOS) ? (string) $request->query('estado') : '';
        $veTodas = $this->estados->veTodas($usuario);
        $medicoId = $veTodas && $request->filled('medico') ? $request->integer('medico') : null;
        $propio = $veTodas ? null : $this->estados->medicoDe($usuario);

        return view('gestioncitas::CU13_ControlarAsistenciaEstadosCita.control', [
            'fecha' => $fecha,
            'estado' => $estado,
            'medicoId' => $medicoId,
            'veTodas' => $veTodas,
            'sinFichaMedico' => ! $veTodas && ! $propio,
            'medicos' => $veTodas ? Medico::where('estado', true)->orderBy('apellidos')->orderBy('nombres')->get() : collect(),
            'citas' => $this->estados->listar($usuario, $fecha, $estado, $medicoId),
            'servicio' => $this->estados,
            'usuario' => $usuario,
        ]);
    }

    /**
     * Pasos 3 a 6: aplica la acción elegida (con las reglas de TransicionesCita) y vuelve a la agenda del día.
     *
     * @CI CIEstadoCita (elegirAccion, mostrarResultado) @CC CCEstadoCita (validarTransicion, cambiarEstado) @CE CECita (cambiarEstado)
     */
    public function cambiar(EstadoCitaRequest $request, Cita $cita): RedirectResponse
    {
        $volver = redirect()->route('control.index', $request->filtros());

        try {
            $resultado = $this->estados->aplicar($cita, $request->string('accion')->toString(), $request->user(), [
                'estado_nuevo' => $request->input('estado_nuevo'),
                'justificacion' => $request->input('justificacion'),
            ]);
        } catch (HorarioOcupadoException|ReglaCitaException $e) {
            return $volver->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $volver->with('error', 'No se pudo cambiar el estado de la cita por un problema interno. Intente nuevamente; si el problema continúa, avise al administrador.');
        }

        $volver->with('exito', $resultado['mensaje']);

        // Inasistencia de una cita pagada: se ofrece reprogramarla (CU-10).
        return $resultado['reprogramar'] ? $volver->with('reprogramar', $resultado['cita']->id) : $volver;
    }

    /** «YYYY-MM-DD» válido o, si no, hoy. */
    private function fecha(mixed $valor): string
    {
        $texto = (string) $valor;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) && Carbon::createFromFormat('Y-m-d', $texto)?->format('Y-m-d') === $texto) {
            return $texto;
        }

        return Carbon::today()->toDateString();
    }
}
