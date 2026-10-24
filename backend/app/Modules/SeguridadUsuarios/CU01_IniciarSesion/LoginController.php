<?php

namespace App\Modules\SeguridadUsuarios\CU01_IniciarSesion;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * CU-01: Iniciar sesión (paquete P1 Seguridad y Usuarios, RF01).
 *
 * Flujo de suceso (ficha 4.3 del documento):
 *  1. El usuario accede a la página de inicio de sesión.            -> mostrarFormulario()
 *  2. Ingresa su correo o nombre de usuario y su contraseña.        -> pantalla login.blade.php (CILogin)
 *  3. El sistema valida que no se haya superado el límite de
 *     intentos fallidos.                                            -> LoginService::validarIntentos()
 *  4. El sistema verifica las credenciales (password_verify) y que
 *     la cuenta esté activa.                                        -> LoginService::verificarClave() y autenticar()
 *  5. El sistema crea la sesión, identifica los roles del usuario y
 *     muestra el panel principal con el menú permitido por todos
 *     sus roles.                                                    -> autenticar() redirige a /panel (PanelController)
 *
 * Excepciones:
 *  - Credenciales incorrectas: mensaje de error genérico y se permite reintentar.
 *  - Cuenta inactiva: «El acceso no está habilitado».
 *  - 3 intentos fallidos en un minuto: bloqueo temporal de esa cuenta (la llave es el correo, aunque se ingrese con el usuario).
 *
 * @CU CU-01 @RF RF01 @CI CILogin @CC CCLogin @CE CEUsuario
 */
class LoginController extends Controller
{
    /**
     * Paso 1 y 2: muestra el formulario de ingreso.
     *
     * @CI CILogin (mostrarFormulario)
     */
    public function mostrarFormulario(): View
    {
        return view('seguridadusuarios::CU01_IniciarSesion.login');
    }

    /**
     * Pasos 3 a 5: envía las credenciales; si son válidas abre la sesión y va al panel.
     *
     * @CI CILogin (enviarCredenciales, mostrarError) @CC CCLogin (autenticar)
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
}
