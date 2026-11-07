<?php

namespace App\Modules\GestionCitas\Compartido;

/**
 * Lo que el sistema necesita saber de una sesión de Stripe Checkout (y de su PaymentIntent) para decidir si agenda la
 * cita. Solo contiene identificadores, estado, monto y los datos de la reserva (metadata): nunca datos de tarjeta,
 * que Stripe no entrega y el sistema no guarda (RNF01).
 *
 * @CU CU-09, CU-11 @RF RF09, RF11
 */
final class SesionPago
{
    /** Estado del PaymentIntent cuando el cliente ya pagó en Stripe y el cobro espera la captura del sistema. */
    public const AUTORIZADO = 'requires_capture';

    /**
     * @param  array<string, string>  $metadata  datos de la reserva que el sistema envió al crear la sesión
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $url,
        public readonly ?string $paymentIntentId,
        public readonly ?string $estadoPago,
        public readonly ?int $centavos,
        public readonly ?string $moneda,
        public readonly array $metadata = [],
    ) {}

    /** ¿El pago quedó autorizado en Stripe (listo para capturar)? */
    public function autorizado(): bool
    {
        return $this->paymentIntentId !== null && $this->estadoPago === self::AUTORIZADO;
    }

    /** Arma el objeto desde la respuesta JSON de Stripe (con el PaymentIntent expandido o solo su id). */
    public static function desdeRespuesta(array $datos): self
    {
        $intento = $datos['payment_intent'] ?? null;
        $intento = is_array($intento) ? $intento : ['id' => $intento];

        return new self(
            id: (string) $datos['id'],
            url: $datos['url'] ?? null,
            paymentIntentId: $intento['id'] ?? null,
            estadoPago: $intento['status'] ?? null,
            centavos: isset($intento['amount']) ? (int) $intento['amount'] : (isset($datos['amount_total']) ? (int) $datos['amount_total'] : null),
            moneda: isset($intento['currency']) ? strtolower($intento['currency']) : (isset($datos['currency']) ? strtolower($datos['currency']) : null),
            metadata: array_map('strval', $datos['metadata'] ?? []),
        );
    }
}
