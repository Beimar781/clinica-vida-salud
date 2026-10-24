<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Evita que el navegador guarde las páginas del sistema: al cerrar sesión y volver atrás
 * no se muestra ninguna página interna.
 *
 * @CU CU-02 @RF RF02 @CI CILogout @CC CCLogout @CE CEUsuario
 */
class SinCache
{
    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);
        $respuesta->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private');
        $respuesta->headers->set('Pragma', 'no-cache');
        $respuesta->headers->set('Expires', '0');

        return $respuesta;
    }
}
