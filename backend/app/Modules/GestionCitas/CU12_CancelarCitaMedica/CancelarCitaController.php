<?php

namespace App\Modules\GestionCitas\CU12_CancelarCitaMedica;

use App\Http\Controllers\Controller;
use App\Models\Cita;
use App\Modules\GestionCitas\Compartido\ReglaCitaException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * CU-12: Cancelar cita médica (paquete P4 Gestión de Citas, RF12). La Recepcionista (y el Administrador, que hereda
 * recepción) y el Paciente dueño de la cita.
 *
 * Precondición: el usuario es Recepcionista o Paciente; la cita existe y está Programada o Confirmada.
 *
 * Flujo de suceso (ficha 4.3 del documento):
 *  1. El usuario accede a la cita: la recepcionista la busca en «Citas»
 *     y el paciente la elige en «Mis citas».                              -> botón «Cancelar» de CU10_.../buscar.blade.php y de CU15_.../historial.blade.php
 *  2. Selecciona la opción «Cancelar».                                    -> mostrar() (GET /citas/{cita}/cancelar)
 *  3. El sistema verifica que la cita pueda cancelarse (y, si es un
 *     paciente, que sea una cita propia).                                 -> mostrar() con CancelarCitaService::verificarPermiso() y puedeCancelarse()
 *  4. El usuario ingresa el motivo de la cancelación y confirma.          -> formulario de cancelar.blade.php (CancelarCitaRequest)
 *  5. El sistema cambia el estado a Cancelada, guarda el motivo y libera
 *     el horario.                                                         -> guardar() -> CancelarCitaService::cancelar() (transacción con la cita bloqueada)
 *  6. El sistema muestra la confirmación de la cancelación.               -> guardar() redirige con el aviso de éxito
 *
 * Excepciones:
 *  - La cita ya fue atendida o ya está cancelada: no se puede cancelar (ReglaCitaException).
 *  - El paciente intenta cancelar con menos de 2 horas de anticipación: se le indica comunicarse con recepción.
 *  - La cita está pagada: se avisa que el pago no se devuelve y se ofrece reprogramarla (CU-10); si confirma la
 *    cancelación, se cancela sin devolución.
 *  - No se ingresa el motivo: el sistema lo solicita (CancelarCitaRequest).
 *  - Un paciente no puede cancelar la cita de otro: acceso denegado (403).
 *
 * @CU CU-12 @RF RF12 @CI CICancelarCita @CC CCCancelarCita @CE CECita
 */
class CancelarCitaController extends Controller
{
    public function __construct(private readonly CancelarCitaService $cancelaciones) {}

    /**
     * Pasos 2 y 3: muestra los datos de la cita, verifica que pueda cancelarse y pide el motivo.
     *
     * @CI CICancelarCita (buscarCita, pedirMotivo) @CC CCCancelarCita (buscar, verificarPermiso) @CE CECita (obtener)
     */
    public function mostrar(Request $request, Cita $cita): View
    {
        $cita->load(['paciente', 'medico', 'especialidad', 'creador']);
        $usuario = $request->user();

        try {
            $this->cancelaciones->verificarPermiso($cita, $usuario);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        $puede = $this->cancelaciones->puedeCancelarse($cita);
        $anticipacion = $this->cancelaciones->esPersonal($usuario) || $this->cancelaciones->hayAnticipacion($cita);

        return view('gestioncitas::CU12_CancelarCitaMedica.cancelar', [
            'cita' => $cita,
            'puede' => $puede,
            'motivoNo' => $puede ? null : $this->cancelaciones->motivoNoCancelable($cita),
            'anticipacion' => $anticipacion,
            'esPersonal' => $this->cancelaciones->esPersonal($usuario),
            'ofrecerReprogramar' => $puede && $this->cancelaciones->sePuedeOfrecerReprogramar($cita, $usuario),
        ]);
    }

    /**
     * Pasos 4 a 6: valida el motivo, cancela dentro de una transacción y muestra la confirmación.
     *
     * @CI CICancelarCita (confirmarCancelacion, mostrarResultado) @CC CCCancelarCita (cancelar) @CE CECita (cancelar)
     */
    public function guardar(CancelarCitaRequest $request, Cita $cita): RedirectResponse
    {
        $volver = redirect()->route('citas.cancelar', $cita);
        $usuario = $request->user();

        try {
            $resultado = $this->cancelaciones->cancelar(
                $cita, $usuario, $request->string('motivo')->toString(), $request->boolean('confirmo_sin_devolucion'),
            );
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (ReglaCitaException $e) {
            return $volver->withInput()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $volver->withInput()->with('error', 'No se pudo cancelar la cita por un problema interno. Intente nuevamente; si el problema continúa, avise al administrador.');
        }

        $cancelada = $resultado['cita'];
        $mensaje = "Cita {$cancelada->codigo} cancelada. El horario quedó libre.".($resultado['pagada'] ? ' El pago registrado no se devuelve.' : '');

        // El paciente vuelve a «Mis citas» (CU-15); la recepción, al listado de citas.
        $destino = $this->cancelaciones->esPersonal($usuario)
            ? route('citas.index', ['q' => $cancelada->codigo])
            : (Route::has('historial.index') ? route('historial.index') : route('panel'));

        return redirect($destino)->with('exito', $mensaje);
    }
}
