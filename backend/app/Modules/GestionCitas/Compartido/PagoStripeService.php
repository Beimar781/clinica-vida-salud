<?php

namespace App\Modules\GestionCitas\Compartido;

use App\Support\Dinero;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pago con tarjeta mediante Stripe Checkout ALOJADO, hablando con la API REST de Stripe con el cliente HTTP de Laravel:
 * sin SDK y sin scripts de Stripe en nuestras páginas. La persona escribe su tarjeta en la página de Stripe, así que
 * ningún dato de tarjeta pasa por el sistema ni se guarda (RNF01): solo se conserva la referencia del pago.
 *
 * El cobro se hace en dos tiempos (captura manual): Stripe AUTORIZA el pago al terminar el Checkout y el sistema lo
 * CAPTURA recién dentro de la transacción que agenda la cita; si el horario se ocupó o algo falla, se CANCELA la
 * autorización y no se cobra nada. Así no existe «cobrar y no tener cita».
 *
 * Moneda: STRIPE_MONEDA y TIPO_CAMBIO (config/vidasalud.php); con bob y 1 el monto en Bs se envía tal cual.
 * Cualquier error de red o de Stripe se registra en el log (sin la clave) y se convierte en un mensaje claro para el
 * usuario (PagoException), nunca en un 500.
 *
 * @CU CU-09, CU-11 @RF RF09, RF11 @CC CCRegistrarCita (registrar), CCReservarCita (reservar) @CE CECita
 */
class PagoStripeService
{
    private const API = 'https://api.stripe.com/v1';

    private const SEGUNDOS_ESPERA = 15;

    /** ¿Hay clave de Stripe (STRIPE_SECRET)? Sin ella el pago con tarjeta no está disponible y el efectivo sigue funcionando. */
    public function configurado(): bool
    {
        return filled(config('services.stripe.secret'));
    }

    /**
     * Crea la sesión de Checkout (pago único, captura manual) con los datos de la reserva en metadata.
     *
     * @param  string  $montoBs  monto en bolivianos, por ejemplo «100.00»
     * @param  array<string, string|int>  $metadata  datos de la reserva (ids, fecha, hora y token; sin datos personales)
     *
     * @throws PagoException
     */
    public function crearSesion(string $montoBs, string $producto, string $descripcion, array $metadata, string $urlExito, string $urlCancelado): SesionPago
    {
        $datos = [
            'mode' => 'payment',
            'locale' => 'es',
            'success_url' => $urlExito,
            'cancel_url' => $urlCancelado,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => Dinero::monedaStripe(),
                    'unit_amount' => Dinero::aCentavosStripe($montoBs),
                    'product_data' => ['name' => $producto, 'description' => $descripcion],
                ],
            ]],
            'payment_intent_data' => [
                'capture_method' => 'manual',
                'description' => $producto,
                'metadata' => $metadata,
            ],
            'metadata' => $metadata,
        ];

        $respuesta = $this->enviar(fn (PendingRequest $http) => $http->asForm()->post(self::API.'/checkout/sessions', $datos), 'crear la sesión de Checkout');

        $sesion = SesionPago::desdeRespuesta($respuesta->json());
        if (blank($sesion->url)) {
            Log::error('Stripe: la sesión de Checkout no trajo URL de pago.', ['sesion' => $sesion->id]);
            throw PagoException::general();
        }

        return $sesion;
    }

    /**
     * Consulta en el servidor la sesión y su PaymentIntent. Nunca se confía en lo que diga la URL ni el navegador.
     *
     * @throws PagoException
     */
    public function obtenerSesion(string $sesionId): SesionPago
    {
        $respuesta = $this->enviar(
            fn (PendingRequest $http) => $http->get(self::API.'/checkout/sessions/'.rawurlencode($sesionId), ['expand' => ['payment_intent']]),
            'consultar la sesión de Checkout'
        );

        return SesionPago::desdeRespuesta($respuesta->json());
    }

    /**
     * Captura el pago autorizado (el cobro real). Se llama dentro de la transacción que guarda la cita.
     *
     * @throws PagoException
     */
    public function capturar(string $paymentIntentId): void
    {
        $respuesta = $this->enviar(
            fn (PendingRequest $http) => $http->asForm()->post(self::API.'/payment_intents/'.rawurlencode($paymentIntentId).'/capture'),
            'capturar el pago'
        );

        if ($respuesta->json('status') !== 'succeeded') {
            Log::error('Stripe: la captura no terminó en «succeeded».', ['payment_intent' => $paymentIntentId, 'estado' => $respuesta->json('status')]);
            throw PagoException::general();
        }
    }

    /**
     * Cancela la autorización: no se cobra nada y el dinero queda libre en la tarjeta.
     *
     * @throws PagoException
     */
    public function cancelar(string $paymentIntentId): void
    {
        $this->enviar(
            fn (PendingRequest $http) => $http->asForm()->post(self::API.'/payment_intents/'.rawurlencode($paymentIntentId).'/cancel'),
            'cancelar la autorización del pago'
        );
    }

    /**
     * Envía la petición con la clave, el tiempo máximo de espera y el manejo de errores común.
     *
     * @param  callable(PendingRequest): Response  $peticion
     *
     * @throws PagoException con un mensaje claro; el detalle va al log
     */
    private function enviar(callable $peticion, string $accion): Response
    {
        if (! $this->configurado()) {
            throw new PagoException(PagoException::MENSAJE_NO_DISPONIBLE);
        }

        try {
            $respuesta = $peticion(Http::withToken((string) config('services.stripe.secret'))->timeout(self::SEGUNDOS_ESPERA));
        } catch (ConnectionException $e) {
            Log::error("Stripe: no hubo conexión al {$accion}.", ['error' => $e->getMessage()]);
            throw PagoException::general();
        }

        if ($respuesta->failed()) {
            Log::error("Stripe: error al {$accion}.", [
                'http' => $respuesta->status(),
                'tipo' => $respuesta->json('error.type'),
                'codigo' => $respuesta->json('error.code'),
                'mensaje' => $respuesta->json('error.message'),
            ]);
            throw PagoException::general();
        }

        return $respuesta;
    }
}
