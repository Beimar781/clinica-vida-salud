<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Códigos legibles generados con las secuencias de PostgreSQL (nunca con max()+1,
 * que provoca duplicados bajo concurrencia). Llamar dentro de la transacción que guarda el registro.
 */
class Codigos
{
    public static function cita(): string
    {
        return self::siguiente('citas_codigo_seq', 'CM-');
    }

    public static function paciente(): string
    {
        return self::siguiente('pacientes_codigo_seq', 'PAC-');
    }

    public static function medico(): string
    {
        return self::siguiente('medicos_codigo_seq', 'MED-');
    }

    private static function siguiente(string $secuencia, string $prefijo): string
    {
        $numero = DB::selectOne('SELECT nextval(?::regclass) AS numero', [$secuencia])->numero;

        return $prefijo.str_pad((string) $numero, 4, '0', STR_PAD_LEFT);
    }
}
