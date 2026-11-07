<?php

namespace App\Modules\GestionCitas\Compartido;

use App\Models\Cita;

/**
 * Cómo se paga una cita al registrarla: en efectivo (queda PENDIENTE hasta que la recepción registra el cobro,
 * CU-13) o con tarjeta (el pago ya está autorizado en Stripe; se captura dentro de la transacción de agendado y la
 * cita queda PAGADO con la referencia del PaymentIntent). El monto de la cita siempre es el precio de la
 * especialidad o servicio en ese momento; para la tarjeta se guarda el monto que se autorizó, para comprobar que
 * el precio no cambió mientras la persona pagaba.
 *
 * @CU CU-09, CU-11 @RF RF09, RF11 @CE CECita
 */
final class PagoCita
{
    private function __construct(
        public readonly string $metodo,
        public readonly ?string $referencia = null,
        public readonly ?string $montoAutorizado = null,
    ) {}

    public static function efectivo(): self
    {
        return new self(Cita::METODO_EFECTIVO);
    }

    /**
     * @param  string  $referencia  id del PaymentIntent de Stripe (nunca datos de tarjeta)
     * @param  string  $montoAutorizado  monto en bolivianos que se autorizó, por ejemplo «100.00»
     */
    public static function tarjeta(string $referencia, string $montoAutorizado): self
    {
        return new self(Cita::METODO_TARJETA, $referencia, $montoAutorizado);
    }

    public function esTarjeta(): bool
    {
        return $this->metodo === Cita::METODO_TARJETA;
    }
}
