<?php

namespace App\Modules\Seguridad\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Seguridad\Http\Requests\LoginRequest;
use App\Modules\Seguridad\Services\LoginFallidoException;
use App\Modules\Seguridad\Services\LoginService;
use App\Modules\Seguridad\Services\LogoutService;
use App\Support\PanelPrincipal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;

/**
 * Inicio y cierre de sesión y panel principal. Controlador delgado: la lógica está en los servicios.
 */
class AutenticacionController extends Controller
{
    /**
     * @CU CU-01 @RF RF01 @CI CILogin (mostrarFormulario) @CC CCLogin @CE CEUsuario
     */
    public function mostrarFormulario(): View
    {
        return view('seguridad::cu01-iniciar-sesion.login');
    }

    /**
     * @CU CU-01 @RF RF01 @CI CILogin (enviarCredenciales, mostrarError) @CC CCLogin (autenticar) @CE CEUsuario
     */
    public function autenticar(LoginRequest $request, LoginService $login): RedirectResponse
    {
        try {
            $login->autenticar($request->string('identificador')->toString(), $request->string('password')->toString(), $request);
        } catch (LoginFallidoException $e) {
            return back()->withInput($request->only('identificador'))->with('error', $e->getMessage());
        }

        return redirect()->intended('/panel');
    }

    /**
     * @CU CU-01 @RF RF01 @CI CILogin (mostrarPanel) @CC CCLogin @CE CEUsuario
     */
    public function mostrarPanel(Request $request): View
    {
        $usuario = $request->user();

        return view('seguridad::cu01-iniciar-sesion.panel', [
            'usuario' => $usuario,
            'roles' => $usuario->nombresRoles(),
            'paquetes' => PanelPrincipal::paquetes($usuario),
        ]);
    }

    /**
     * @CU CU-02 @RF RF02 @CI CILogout (solicitarCierre, mostrarInicio) @CC CCLogout (cerrar) @CE CEUsuario
     */
    public function cerrar(Request $request, LogoutService $logout): RedirectResponse
    {
        $logout->cerrar($request);

        return redirect('/login')->with('exito', 'Sesión cerrada correctamente.');
    }
}
