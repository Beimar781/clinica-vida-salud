<?php

namespace App\Support\Middleware;

use App\Support\BitacoraService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware «rol»: acepta al usuario si tiene al menos uno de los roles indicados.
 * Uso: Route::middleware('rol:Administrador,Recepcionista').
 *
 * @CU CU-01 @RF RF01 @CI CILogin @CC CCLogin @CE CEUsuario
 */
class VerificarRol
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $usuario = $request->user();

        if (! $usuario) {
            return redirect()->guest('/login');
        }

        if (! $usuario->tieneRol(...$roles)) {
            BitacoraService::registrar('ACCESO_DENEGADO', 'Seguridad', 'Ruta: '.$request->path());
            abort(403, 'No tiene permiso para acceder a esta sección.');
        }

        return $next($request);
    }
}
