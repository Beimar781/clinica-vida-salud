<?php

namespace App\Modules\SeguridadUsuarios\CU02_CerrarSesion;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * CU-02: Cerrar sesión (paquete P1 Seguridad y Usuarios, RF02).
 *
 * Flujo de suceso (ficha 4.3 del documento):
 *  1. El usuario selecciona la opción «Cerrar sesión».     -> botón del menú superior (layouts/app.blade.php) que envía POST /logout
 *  2. El sistema invalida la sesión y regenera el token.   -> LogoutService::cerrar() e invalidarSesion()
 *  3. El sistema redirige a la pantalla de inicio de sesión. -> __invoke() redirige a /login
 *
 * Excepción: si la sesión ya expiró, solo se redirige al inicio de sesión (la ruta no exige «auth»
 * y el error 419 de /logout se resuelve en bootstrap/app.php).
 *
 * @CU CU-02 @RF RF02 @CI CILogout @CC CCLogout @CE CEUsuario
 */
class LogoutController extends Controller
{
    /** @CI CILogout (solicitarCierre, mostrarInicio) @CC CCLogout (cerrar) */
    public function __invoke(Request $request, LogoutService $logout): RedirectResponse
    {
        $logout->cerrar($request);

        return redirect('/login')->with('exito', 'Sesión cerrada correctamente.');
    }
}
