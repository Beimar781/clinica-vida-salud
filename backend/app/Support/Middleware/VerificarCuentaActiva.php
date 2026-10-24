<?php

namespace App\Support\Middleware;

use App\Support\BitacoraService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Si el administrador desactiva una cuenta mientras la persona tiene la sesión abierta,
 * la siguiente petición cierra su sesión.
 *
 * @CU CU-03 @RF RF03 @CI CIUsuarios @CC CCUsuarios (desactivar) @CE CEUsuario
 */
class VerificarCuentaActiva
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario && ! $usuario->estado) {
            BitacoraService::registrar('SESION_CERRADA_CUENTA_INACTIVA', 'Usuario', 'Cuenta desactivada con sesión abierta: '.$usuario->nombre_usuario, $usuario->id);
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/login')->with('error', 'El acceso no está habilitado.');
        }

        return $next($request);
    }
}
