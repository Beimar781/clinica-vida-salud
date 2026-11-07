<?php

namespace App\Modules\GestionCitas\Compartido;

use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Usuario;
use App\Services\BitacoraService;
use App\Support\Dinero;
use Illuminate\Support\Str;

/**
 * Orquesta el pago con tarjeta de una cita (CU-09 y CU-11) alrededor de PagoStripeService, en los tres momentos del flujo:
 *  1. iniciar(): se valida la reserva y la disponibilidad, se guardan los datos validados en la sesión del servidor, se
 *     crea la sesión de Stripe Checkout y se devuelve su URL (el controlador redirige a Stripe);
 *  2. verificarRetorno(): al volver de Stripe se consulta la sesión EN EL SERVIDOR (nunca se confía en la URL), se comprueba
 *     que corresponde a la reserva guardada, que el monto y la moneda son los esperados y que el pago quedó autorizado;
 *  3. el controlador agenda con AgendarCitaService::crear(), que captura el pago dentro de la transacción (o cancela la
 *     autorización si algo falla).
 * Los datos de tarjeta nunca pasan por aquí: los escribe la persona en la página de Stripe.
 *
 * @CU CU-09, CU-11 @RF RF09, RF11 @CC CCRegistrarCita (registrar), CCReservarCita (reservar) @CE CECita
 */
class PagoCitaService
{
    /** Clave de la sesión del servidor con la reserva que espera el resultado del pago. */
    public const SESION = 'pago_cita';

    public const MENSAJE_SIN_COSTO = 'Esta especialidad o servicio no tiene costo (Bs 0,00): no se puede cobrar con tarjeta. Elija pagar en efectivo.';

    public function __construct(
        private readonly AgendarCitaService $agendar,
        private readonly PagoStripeService $stripe,
    ) {}

    /** ¿Se puede ofrecer el pago con tarjeta? (hay STRIPE_SECRET) */
    public function disponible(): bool
    {
        return $this->stripe->configurado();
    }

    /**
     * Paso previo al pago: valida la reserva, la guarda en la sesión y crea la sesión de Checkout.
     *
     * @param  string  $cu  «CU-09» (recepcionista) o «CU-11» (paciente): decide a dónde vuelve el flujo
     * @return string URL de la página de pago de Stripe
     *
     * @throws HorarioOcupadoException
     * @throws ReglaCitaException la reserva no es válida (fecha pasada, horario ocupado, paciente inactivo...)
     * @throws PagoException el pago no está disponible o Stripe no respondió
     */
    public function iniciar(string $cu, Usuario $actor, Paciente $paciente, Especialidad $especialidad, Medico $medico, string $fecha, string $inicio, string $modalidad, ?string $motivo): string
    {
        if (! $this->disponible()) {
            throw new PagoException(PagoException::MENSAJE_NO_DISPONIBLE);
        }

        $especialidad = $especialidad->fresh();
        $monto = number_format((float) $especialidad->precio, 2, '.', '');
        if ((float) $monto <= 0) {
            throw new PagoException(self::MENSAJE_SIN_COSTO);
        }

        // 1. Se valida todo, incluida la disponibilidad, ANTES de mandar a la persona a pagar.
        $this->agendar->prevalidar($paciente, $medico, $especialidad, $fecha, $inicio);

        $token = Str::random(40);
        $metadata = [
            'cu' => $cu, 'token' => $token, 'paciente_id' => $paciente->id, 'medico_id' => $medico->id,
            'especialidad_id' => $especialidad->id, 'fecha' => $fecha, 'hora_inicio' => $inicio,
        ];

        // 2. Sesión de Checkout con captura manual.
        $sesion = $this->stripe->crearSesion(
            $monto,
            'Cita médica · '.$especialidad->nombre,
            $medico->nombre_completo.' · '.DisponibilidadService::dia($fecha)->format('d/m/Y').' '.$inicio,
            $metadata,
            route('pagos.stripe.retorno').'?session_id={CHECKOUT_SESSION_ID}',
            route('pagos.stripe.cancelado'),
        );

        session()->put(self::SESION, [
            'cu' => $cu, 'token' => $token, 'session_id' => $sesion->id, 'actor_id' => $actor->id,
            'paciente_id' => $paciente->id, 'especialidad_id' => $especialidad->id, 'medico_id' => $medico->id,
            'fecha' => $fecha, 'hora_inicio' => $inicio, 'modalidad' => $modalidad, 'motivo' => $motivo, 'monto' => $monto,
        ]);

        return $sesion->url;
    }

    /**
     * La reserva que estaba esperando el pago (y la quita de la sesión: cada reserva se procesa una sola vez).
     *
     * @return ?array<string, mixed>
     */
    public function tomarPendiente(): ?array
    {
        return session()->pull(self::SESION);
    }

    /**
     * Al volver de Stripe: comprueba en el servidor que el pago corresponde a la reserva guardada y quedó autorizado.
     * Si no corresponde pero Stripe dejó una autorización, la cancela (no se cobra nada).
     *
     * @param  ?array<string, mixed>  $pendiente
     *
     * @throws PagoException
     */
    public function verificarRetorno(?array $pendiente, string $sesionId, Usuario $actor): PagoCita
    {
        if ($pendiente === null || (int) $pendiente['actor_id'] !== $actor->id || $sesionId === '' || ! hash_equals((string) $pendiente['session_id'], $sesionId)) {
            $this->cancelarSiAutorizada($sesionId);
            throw new PagoException(PagoException::MENSAJE_SESION_PERDIDA);
        }

        $sesion = $this->stripe->obtenerSesion($sesionId);

        if (! hash_equals((string) $pendiente['token'], (string) ($sesion->metadata['token'] ?? ''))) {
            $this->cancelarSesion($sesion);
            throw new PagoException(PagoException::MENSAJE_SESION_PERDIDA);
        }

        if (! $sesion->autorizado()) {
            throw new PagoException(PagoException::MENSAJE_NO_AUTORIZADO);
        }

        if ($sesion->centavos !== Dinero::aCentavosStripe($pendiente['monto']) || $sesion->moneda !== Dinero::monedaStripe()) {
            $this->cancelarSesion($sesion);
            throw PagoException::general();
        }

        return PagoCita::tarjeta($sesion->paymentIntentId, $pendiente['monto']);
    }

    /**
     * Si la reserva ya no está en la sesión (por ejemplo, se recargó la página de retorno después de agendar), busca la
     * cita que ese mismo pago ya generó para llevar a su comprobante en vez de dar un error.
     */
    public function citaDelPago(string $sesionId, Usuario $actor): ?Cita
    {
        if ($sesionId === '' || ! $this->disponible()) {
            return null;
        }

        try {
            $sesion = $this->stripe->obtenerSesion($sesionId);
        } catch (PagoException) {
            return null;
        }

        return $sesion->paymentIntentId
            ? Cita::where('referencia_pago', $sesion->paymentIntentId)->where('creado_por', $actor->id)->first()
            : null;
    }

    /** Cancela la autorización de un pago que ya no se va a usar: no se cobra nada. */
    public function cancelarAutorizacion(PagoCita $pago): void
    {
        $this->agendar->cancelarAutorizacion($pago);
    }

    /** Anota en la bitácora que un pago con tarjeta no se completó (PAGO_TARJETA_FALLIDO). */
    public function registrarFallo(Usuario $actor, string $detalle): void
    {
        BitacoraService::registrar('PAGO_TARJETA_FALLIDO', 'Cita', 'Pago con tarjeta no completado: '.$detalle, $actor->id);
    }

    /** Consulta la sesión y, si el pago quedó autorizado, cancela la autorización. Nunca lanza errores. */
    private function cancelarSiAutorizada(string $sesionId): void
    {
        if ($sesionId === '' || ! $this->disponible()) {
            return;
        }

        try {
            $this->cancelarSesion($this->stripe->obtenerSesion($sesionId));
        } catch (PagoException) {
            // El detalle ya quedó en el log; la autorización vence sola en Stripe.
        }
    }

    private function cancelarSesion(SesionPago $sesion): void
    {
        if ($sesion->autorizado()) {
            $this->agendar->cancelarAutorizacion(PagoCita::tarjeta($sesion->paymentIntentId, '0'));
        }
    }
}
