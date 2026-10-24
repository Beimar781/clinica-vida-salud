<?php

namespace App\Modules\SeguridadUsuarios\CU01_IniciarSesion;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * CU-01: Iniciar sesión · flujo alternativo A3 «Recuperar contraseña» (parte 1: pedir el enlace).
 * No es un CU nuevo: se agrega a la ficha de CU-01 (texto sugerido para el PDF en docs/INCONSISTENCIAS.md).
 *
 * Flujo alternativo:
 *  A3.1 En el paso 2 el usuario elige «¿Olvidaste tu contraseña?».       -> enlace de login.blade.php
 *  A3.2 El sistema pide el correo de la cuenta.                          -> mostrar() (olvido.blade.php)
 *  A3.3 El usuario ingresa su correo.                                    -> enviar()
 *  A3.4 Si la cuenta existe y está activa, el sistema envía un enlace con
 *       token de un solo uso que vence a los 60 minutos, y responde
 *       siempre igual, exista o no la cuenta.                            -> RecuperarClaveService::solicitar()
 *
 * Excepciones:
 *  - Correo inexistente o cuenta inactiva: misma respuesta genérica y no se envía nada.
 *  - Falla el proveedor de correo: aviso amable y el error queda en el log (nunca un error 500).
 *  - Más de 5 solicitudes por minuto: respuesta 429 (throttle en la ruta).
 *
 * En local (MAIL_MAILER=log) el enlace queda en storage/logs/laravel.log, línea «ENLACE DE RECUPERACION».
 *
 * @CU CU-01 @RF RF01 @CI CILogin @CC CCLogin @CE CEUsuario
 */
class OlvidoClaveController extends Controller
{
    /**
     * A3.2: muestra el formulario para pedir el enlace.
     *
     * @CI CILogin (mostrarOlvido)
     */
    public function mostrar(): View
    {
        return view('seguridadusuarios::CU01_IniciarSesion.olvido');
    }

    /**
     * A3.3 y A3.4: envía el enlace al correo.
     *
     * @CC CCLogin (solicitarRecuperacion)
     */
    public function enviar(OlvidoClaveRequest $request, RecuperarClaveService $recuperar): RedirectResponse
    {
        if (! $recuperar->solicitar($request->string('correo')->toString())) {
            return back()->withInput()->with('error', RecuperarClaveService::MENSAJE_FALLO_CORREO);
        }

        return back()->with('exito', RecuperarClaveService::MENSAJE_SOLICITUD);
    }
}
