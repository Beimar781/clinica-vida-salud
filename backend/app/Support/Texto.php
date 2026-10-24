<?php

namespace App\Support;

use Illuminate\Support\Str;

/** Utilidades de texto comunes a los paquetes. */
class Texto
{
    /** Minúsculas, sin tildes y sin espacios sobrantes (nombre_normalizado de las especialidades). */
    public static function normalizar(string $texto): string
    {
        $texto = preg_replace('/\s+/u', ' ', trim($texto));

        return Str::lower(Str::ascii($texto));
    }
}
