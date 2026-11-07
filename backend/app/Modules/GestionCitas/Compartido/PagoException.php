<?php

namespace App\Modules\GestionCitas\Compartido;

/**
 * El pago con tarjeta no se pudo procesar (Stripe no disponible, pago no autorizado o cancelado, datos que no
 * coinciden con la reserva, reserva perdida). El mensaje es seguro para mostrarlo al usuario: nunca lleva claves,
 * respuestas de Stripe ni datos técnicos (el detalle va al registro interno). Es un caso particular de regla de la
 * cita: los controladores de CU-09 y CU-11 ya la capturan junto con las demás y la cita no se registra.
 *
 * @CU CU-09, CU-11 @RF RF09, RF11
 */
class PagoException extends ReglaCitaException
{
    public const MENSAJE_GENERAL = 'No se pudo procesar el pago con tarjeta. Elija otro método de pago.';

    public const MENSAJE_NO_DISPONIBLE = 'El pago con tarjeta no está disponible por ahora. Elija pagar en efectivo en la clínica.';

    public const MENSAJE_NO_AUTORIZADO = 'El pago con tarjeta no fue autorizado (la tarjeta fue rechazada o el pago se canceló). No se cobró nada: puede intentar de nuevo o elegir otro método de pago.';

    public const MENSAJE_SESION_PERDIDA = 'Se perdió la reserva en curso (la sesión venció o ya se procesó). No se cobró nada: vuelva a elegir el horario y el método de pago.';

    public static function general(): self
    {
        return new self(self::MENSAJE_GENERAL);
    }
}
