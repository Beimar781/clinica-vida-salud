<?php

namespace App\Modules\SeguridadUsuarios\CU01_IniciarSesion;

use App\Http\Controllers\Controller;
use App\Support\PanelPrincipal;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Panel principal después de iniciar sesión (paso 5 del CU-01): una tarjeta por paquete con las
 * funciones que permiten TODOS los roles del usuario (la lista sale de PanelPrincipal).
 *
 * @CU CU-01 @RF RF01 @CI CILogin (mostrarPanel) @CC CCLogin @CE CEUsuario
 */
class PanelController extends Controller
{
    public function __invoke(Request $request): View
    {
        $usuario = $request->user();

        return view('seguridadusuarios::CU01_IniciarSesion.panel', [
            'usuario' => $usuario,
            'roles' => $usuario->nombresRoles(),
            'paquetes' => PanelPrincipal::paquetes($usuario),
        ]);
    }
}
