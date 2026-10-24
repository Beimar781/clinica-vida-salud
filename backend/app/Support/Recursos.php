<?php

namespace App\Support;

/**
 * Acceso seguro a los assets del frontend (frontend/assets: css, js e img).
 * Laravel los sirve por la ruta «assets»; aquí se resuelve cada ruta pedida dentro de esa carpeta
 * y se rechaza cualquier intento de salir de ella (path traversal).
 */
class Recursos
{
    /** Extensiones servidas y su tipo de contenido. */
    public const TIPOS = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];

    public static function carpeta(): ?string
    {
        $base = realpath(config('vidasalud.frontend').DIRECTORY_SEPARATOR.'assets');

        return $base === false ? null : $base;
    }

    /** Ruta real del archivo, o null si no existe, no es un asset permitido o queda fuera de la carpeta. */
    public static function resolver(string $ruta): ?string
    {
        $base = self::carpeta();
        if ($base === null || $ruta === '' || str_contains($ruta, "\0") || str_contains($ruta, '\\')) {
            return null;
        }

        foreach (explode('/', $ruta) as $segmento) {
            // Sin segmentos vacíos, ocultos ni «..»; solo letras, números, punto, guion y guion bajo.
            if (! preg_match('/^[A-Za-z0-9_-][A-Za-z0-9._-]*$/', $segmento)) {
                return null;
            }
        }

        if (! array_key_exists(strtolower(pathinfo($ruta, PATHINFO_EXTENSION)), self::TIPOS)) {
            return null;
        }

        $archivo = realpath($base.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $ruta));
        if ($archivo === false || ! is_file($archivo) || ! str_starts_with($archivo, $base.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $archivo;
    }

    public static function tipo(string $archivo): string
    {
        return self::TIPOS[strtolower(pathinfo($archivo, PATHINFO_EXTENSION))];
    }

    /** URL relativa del asset con la fecha de modificación como versión (cambia al editar el archivo). */
    public static function url(string $ruta): string
    {
        $archivo = self::resolver($ruta);
        $parametros = ['ruta' => $ruta] + ($archivo ? ['v' => filemtime($archivo)] : []);

        return route('assets', $parametros, false);
    }
}
