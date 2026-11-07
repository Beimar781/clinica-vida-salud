<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Montos del sistema. El precio de las especialidades y servicios y el monto de las citas están en
 * bolivianos (Bs). En pantalla se escribe «Bs 100,00» (coma decimal). Para Stripe se convierte a su moneda
 * con TIPO_CAMBIO (STRIPE_MONEDA=bob y TIPO_CAMBIO=1 envían el monto tal cual; con usd y 6.96 se divide).
 */
class Dinero
{
    /** «Bs 100,00»; con miles «Bs 1.250,50». */
    public static function formato(float|int|string|null $monto): string
    {
        return config('vidasalud.moneda', 'Bs').' '.number_format((float) $monto, 2, ',', '.');
    }

    /** Monto en bolivianos → monto en la moneda de Stripe (con dos decimales). */
    public static function aMonedaStripe(float|int|string $bolivianos): float
    {
        return round((float) $bolivianos / self::tipoCambio(), 2);
    }

    /** Monto en bolivianos → centavos de la moneda de Stripe, el entero que pide la API (100 Bs = 10000). */
    public static function aCentavosStripe(float|int|string $bolivianos): int
    {
        return (int) round((float) $bolivianos / self::tipoCambio() * 100);
    }

    /** Código de moneda de Stripe en minúsculas (bob, usd). */
    public static function monedaStripe(): string
    {
        return strtolower((string) config('vidasalud.stripe_moneda', 'bob'));
    }

    private static function tipoCambio(): float
    {
        $tipo = (float) config('vidasalud.tipo_cambio', 1);

        if ($tipo <= 0) {
            throw new InvalidArgumentException('TIPO_CAMBIO debe ser mayor que cero.');
        }

        return $tipo;
    }
}
