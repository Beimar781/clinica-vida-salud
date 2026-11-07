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
 * Flujo de suceso (ficha 4.3 de CU-01, paso 5):
 *  5. El sistema crea la sesión, identifica los roles del usuario y muestra el panel principal con el menú
 *     permitido por todos sus roles (una tarjeta por paquete, cada función con su CU-xx).   -> __invoke()
 *
 * Excepciones:
 *  - Sin sesión: se redirige al inicio de sesión (middleware «auth»).
 *  - Cuenta desactivada con la sesión abierta: pierde el acceso (middleware VerificarCuentaActiva).
 *  - Un paciente con cuenta pero sin ficha ve el aviso de acudir a recepción (<x-aviso-sin-ficha>), nunca un error.
 *  - Tras registrarse (CU-20) el panel invita, una sola vez, a completar el perfil (CU-21).
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
