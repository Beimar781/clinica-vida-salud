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

    /**
     * Fragmento SQL (PostgreSQL) que pone una columna en minúsculas y sin tildes, igual que normalizar():
     * sirve para buscar «perez» y encontrar «Pérez». El nombre de columna es siempre una constante del código.
     */
    public static function sqlSinTildes(string $columna): string
    {
        return "translate(lower({$columna}), 'áéíóúüñàèìòù', 'aeiouunaeiou')";
    }

    /** Patrón «contiene» para LIKE: escapa la barra, % y _ del texto buscado (ya normalizado). */
    public static function patronLike(string $texto): string
    {
        $bs = chr(92);

        return '%'.str_replace([$bs, '%', '_'], [$bs.$bs, $bs.'%', $bs.'_'], $texto).'%';
    }
}
