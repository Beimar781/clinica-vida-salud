<?php

namespace App\Modules\SeguridadUsuarios\CU01_IniciarSesion;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CU-01: Iniciar sesión · flujo alternativo A3 «Recuperar contraseña» (parte 2: contraseña nueva).
 *
 * Flujo alternativo (continuación):
 *  A3.5 El usuario abre el enlace recibido.                              -> mostrar()
 *  A3.6 El sistema pide la contraseña nueva y su confirmación.           -> restablecer.blade.php
 *  A3.7 El usuario las ingresa (mínimo 8 caracteres).                    -> guardar()
 *  A3.8 El sistema guarda la contraseña con bcrypt, invalida el token,
 *       limpia el bloqueo por intentos fallidos, registra en la bitácora
 *       y lleva al login con «Contraseña actualizada. Ya puedes iniciar
 *       sesión.»                                                         -> RecuperarClaveService::restablecer()
 *
 * Excepciones:
 *  - Enlace vencido (60 minutos), ya usado o alterado: mensaje claro y opción de pedir otro.
 *  - Contraseña de menos de 8 caracteres o confirmación distinta: error junto al campo.
 *
 * @CU CU-01 @RF RF01 @CI CILogin @CC CCLogin @CE CEUsuario
 */
class RestablecerClaveController extends Controller
{
    /**
     * A3.5 y A3.6: muestra el formulario si el enlace sigue vigente; si no, ofrece pedir otro.
     *
     * @CI CILogin (mostrarRestablecer)
     */
    public function mostrar(Request $request, string $token, RecuperarClaveService $recuperar): View|RedirectResponse
    {
        $correo = (string) $request->query('correo');

        if (! $recuperar->enlaceVigente($correo, $token)) {
            return redirect()->route('password.request')->with('error', RecuperarClaveService::MENSAJE_ENLACE_INVALIDO);
        }

        return view('seguridadusuarios::CU01_IniciarSesion.restablecer', ['token' => $token, 'correo' => $correo]);
    }

    /**
     * A3.7 y A3.8: guarda la contraseña nueva.
     *
     * @CC CCLogin (restablecerClave)
     */
    public function guardar(RestablecerClaveRequest $request, RecuperarClaveService $recuperar): RedirectResponse
    {
        $guardada = $recuperar->restablecer(
            $request->string('correo')->toString(),
            $request->string('token')->toString(),
            $request->string('password')->toString(),
            $request->string('password_confirmation')->toString(),
        );

        if (! $guardada) {
            return redirect()->route('password.request')->with('error', RecuperarClaveService::MENSAJE_ENLACE_INVALIDO);
        }

        return redirect()->route('login')->with('exito', RecuperarClaveService::MENSAJE_CLAVE_ACTUALIZADA);
    }
}
