<?php

namespace App\Modules\GestionCitas\Compartido;

use App\Http\Controllers\Controller;
use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\GestionCitas\CU09_RegistrarCitaMedica\RegistrarCitaService;
use App\Modules\GestionCitas\CU11_ReservarCitaEnLinea\ReservarCitaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Regreso de Stripe Checkout en el pago con tarjeta de CU-09 (recepcionista) y CU-11 (paciente).
 *
 * Flujo de suceso de la parte de pago (fichas 4.3 de CU-09 y CU-11, pasos 6 a 8 y excepción «Stripe rechaza el pago»):
 *  - La persona eligió tarjeta y confirmó: el servidor validó la reserva, la guardó en su sesión y la mandó a Stripe
 *    (RegistrarCitaController::guardar / ReservarCitaController::guardar -> PagoCitaService::iniciar()).
 *  - Pagó en la página de Stripe y vuelve a /pagos/stripe/retorno?session_id=...   -> retorno()
 *      · se consulta la sesión en el servidor y se comprueba que el pago quedó autorizado (PagoCitaService::verificarRetorno());
 *      · se agenda dentro de la transacción: lock del médico, verificación, inserción y CAPTURA del pago
 *        (AgendarCitaService::crear(..., PagoCita::tarjeta())) y se muestra el comprobante con el pago Pagado.
 *  - Si el horario se ocupó o algo falla: se CANCELA la autorización (no se cobra nada), no se crea la cita y se vuelve al
 *    paso 4 con el aviso y los horarios libres actualizados.
 *  - Si la persona cancela en Stripe, o Stripe rechaza la tarjeta: la cita no se registra y puede elegir otro método
 *    de pago (vuelve al formulario con sus datos)                                  -> cancelado()
 *
 * Nunca se confía en la URL ni en el navegador: todo se comprueba contra la sesión del servidor y la API de Stripe.
 *
 * @CU CU-09, CU-11 @RF RF09, RF11 @CI CIRegistrarCita, CIReservarCita (confirmarCita, confirmarReserva) @CC CCRegistrarCita (registrar), CCReservarCita (reservar) @CE CECita
 */
class RetornoPagoController extends Controller
{
    public function __construct(
        private readonly PagoCitaService $pagos,
        private readonly RegistrarCitaService $registro,
        private readonly ReservarCitaService $reservas,
    ) {}

    /**
     * La persona volvió de Stripe (GET /pagos/stripe/retorno?session_id=...): se verifica el pago y se agenda la cita.
     */
    public function retorno(Request $request): RedirectResponse
    {
        $usuario = $request->user();
        $sesionId = (string) $request->query('session_id');
        $pendiente = $this->pagos->tomarPendiente();

        try {
            $pago = $this->pagos->verificarRetorno($pendiente, $sesionId, $usuario);
        } catch (PagoException $e) {
            // ¿Se recargó la página después de agendar? Entonces ese mismo pago ya tiene su cita.
            if ($pendiente === null && ($cita = $this->pagos->citaDelPago($sesionId, $usuario))) {
                return $this->comprobante($pendiente, $cita);
            }

            $this->pagos->registrarFallo($usuario, $e->getMessage());

            return $this->volver($pendiente, $usuario)->with('error', $e->getMessage());
        }

        try {
            $cita = $this->agendar($pendiente, $usuario, $pago);
        } catch (HorarioOcupadoException $e) {
            $this->pagos->registrarFallo($usuario, 'el horario fue ocupado; se canceló la autorización y no se cobró nada');

            return $this->volver($pendiente, $usuario)->with('error', 'El horario fue ocupado por otro usuario mientras se realizaba el pago. No se cobró nada: elija otro de los horarios libres.');
        } catch (ReglaCitaException $e) {
            $this->pagos->registrarFallo($usuario, 'la reserva ya no es válida ('.$e->getMessage().'); no se cobró nada');

            return $this->volver($pendiente, $usuario)->with('error', $e->getMessage().($e instanceof PagoException ? '' : ' No se cobró nada.'));
        } catch (Throwable $e) {
            report($e);
            $this->pagos->registrarFallo($usuario, 'error interno al registrar la cita');

            return $this->volver($pendiente, $usuario)->with('error', 'No se pudo registrar la cita por un problema interno. Si el cobro aparece en su tarjeta, comuníquese con la clínica.');
        }

        return $this->comprobante($pendiente, $cita);
    }

    /**
     * La persona canceló el pago en Stripe (GET /pagos/stripe/cancelado): la cita no se registra y puede elegir otro método.
     */
    public function cancelado(Request $request): RedirectResponse
    {
        $usuario = $request->user();
        $pendiente = $this->pagos->tomarPendiente();

        $this->pagos->registrarFallo($usuario, 'cancelado en la página de pago');

        return $this->volver($pendiente, $usuario)->with('error', 'El pago con tarjeta no se completó y la cita no se registró. No se cobró nada: puede intentar de nuevo o elegir pagar en efectivo.');
    }

    /** Registra la cita de la reserva guardada con el pago ya autorizado (CU-09 o CU-11). */
    private function agendar(array $p, Usuario $usuario, PagoCita $pago): Cita
    {
        $especialidad = Especialidad::findOrFail($p['especialidad_id']);
        $medico = Medico::findOrFail($p['medico_id']);

        if ($p['cu'] === 'CU-11') {
            $paciente = $this->reservas->pacienteDe($usuario);
            // La cita es de la cuenta autenticada: la ficha guardada debe seguir siendo la suya.
            if (! $paciente || $paciente->id !== (int) $p['paciente_id']) {
                $this->pagos->cancelarAutorizacion($pago); // no se cobra nada
                throw new PagoException(PagoException::MENSAJE_SESION_PERDIDA);
            }

            return $this->reservas->reservar($paciente, $especialidad, $medico, $p['fecha'], $p['hora_inicio'], $p['modalidad'], $p['motivo'], $usuario, $pago);
        }

        return $this->registro->registrar(Paciente::findOrFail($p['paciente_id']), $especialidad, $medico, $p['fecha'], $p['hora_inicio'], $p['modalidad'], $p['motivo'], $usuario, $pago);
    }

    private function comprobante(?array $p, Cita $cita): RedirectResponse
    {
        $usuario = request()->user();
        // Sin la reserva en la sesión (página recargada) se deduce por quién es la persona: la paciente dueña de la cita o la recepción.
        $esReserva = $p !== null
            ? $p['cu'] === 'CU-11'
            : $cita->paciente->usuario_id === $usuario->id && ! $usuario->tieneRol(Rol::RECEPCIONISTA);

        return $esReserva
            ? redirect()->route('reservas.comprobante', $cita)->with('exito', "Pago con tarjeta aprobado. Su cita {$cita->codigo} fue reservada correctamente.")
            : redirect()->route('citas.comprobante', $cita)->with('exito', "Pago con tarjeta aprobado. Cita {$cita->codigo} registrada correctamente.");
    }

    /** Vuelve al formulario donde se eligió el horario, con lo que la persona había escrito para que pueda cambiar de método. */
    private function volver(?array $p, Usuario $usuario): RedirectResponse
    {
        if ($p === null) {
            return redirect()->route($usuario->tieneRol(Rol::RECEPCIONISTA) ? 'citas.registrar' : 'reservas.crear');
        }

        $destino = $p['cu'] === 'CU-11'
            ? route('reservas.reservar', ['especialidad' => $p['especialidad_id'], 'medico' => $p['medico_id'], 'fecha' => $p['fecha']])
            : route('citas.registrar', ['paciente' => $p['paciente_id'], 'especialidad' => $p['especialidad_id'], 'medico' => $p['medico_id'], 'fecha' => $p['fecha']]);

        return redirect($destino)->withInput([
            'hora_inicio' => $p['hora_inicio'], 'modalidad' => $p['modalidad'], 'motivo' => $p['motivo'], 'metodo_pago' => 'EFECTIVO',
        ]);
    }
}
