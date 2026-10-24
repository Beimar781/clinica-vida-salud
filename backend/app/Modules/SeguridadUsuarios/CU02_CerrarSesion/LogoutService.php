<?php

namespace App\Modules\SeguridadUsuarios\CU02_CerrarSesion;

use App\Services\BitacoraService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Cierre de sesión: invalida la sesión, regenera el token y deja registro en la bitácora.
 *
 * @CU CU-02 @RF RF02 @CI CILogout @CC CCLogout @CE CEUsuario
 */
class LogoutService
{
    /**
     * Si la sesión ya expiró no hay usuario: solo se limpia lo que quede y el controlador redirige al login.
     *
     * @CC CCLogout::cerrar
     */
    public function cerrar(Request $request): void
    {
        $usuario = Auth::user();

        if ($usuario) {
            BitacoraService::registrar('LOGOUT', 'Usuario', 'Cierre de sesión de '.$usuario->nombre_usuario, $usuario->id);
        }

        $this->invalidarSesion($request);
    }

    /** @CC CCLogout::invalidarSesion */
    public function invalidarSesion(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
