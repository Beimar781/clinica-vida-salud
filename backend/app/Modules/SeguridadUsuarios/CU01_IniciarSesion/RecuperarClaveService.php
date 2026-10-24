<?php

namespace App\Modules\SeguridadUsuarios\CU01_IniciarSesion;

use App\Models\Usuario;
use App\Services\BitacoraService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Flujo alternativo A3 de CU-01 «Recuperar contraseña»: envía un enlace con token de un solo uso (vence a los
 * 60 minutos) al correo de la cuenta y guarda la contraseña nueva con bcrypt. Usa el sistema de restablecimiento
 * de Laravel (Password::sendResetLink y Password::reset) sobre la tabla técnica password_reset_tokens.
 *
 * @CU CU-01 @RF RF01 @CI CILogin @CC CCLogin @CE CEUsuario
 */
class RecuperarClaveService
{
    /** Respuesta única para quien pide el enlace: no revela si el correo existe. */
    public const MENSAJE_SOLICITUD = 'Si el correo está registrado, te enviamos un enlace de recuperación.';

    public const MENSAJE_ENLACE_INVALIDO = 'El enlace de recuperación venció o ya fue usado. Solicita uno nuevo.';

    public const MENSAJE_FALLO_CORREO = 'No pudimos enviar el correo en este momento. Inténtalo de nuevo en unos minutos.';

    public const MENSAJE_CLAVE_ACTUALIZADA = 'Contraseña actualizada. Ya puedes iniciar sesión.';

    /**
     * Envía el enlace si la cuenta existe y está activa. Devuelve true siempre (exista o no la cuenta) salvo que
     * el proveedor de correo falle: entonces devuelve false, el error queda en el log y el token se descarta
     * para poder reintentar.
     *
     * @CC CCLogin::solicitarRecuperacion
     */
    public function solicitar(string $correo): bool
    {
        $usuario = Usuario::buscarPorCorreo($correo);

        if (! $usuario || ! $usuario->estado) {
            BitacoraService::registrar('RECUPERACION_SOLICITADA', 'Usuario', 'Solicitud sin cuenta activa; no se envió el enlace: '.$correo, $usuario?->id);

            return true;
        }

        try {
            $estado = Password::sendResetLink(['correo' => $usuario->correo, 'estado' => true]);
        } catch (Throwable $e) {
            // El proveedor falló (clave inválida, remitente no verificado, sin red...): nunca un error 500.
            Log::error('No se pudo enviar el correo de recuperación: '.$e->getMessage());
            Password::deleteToken($usuario);
            BitacoraService::registrar('RECUPERACION_FALLIDA', 'Usuario', 'No se pudo enviar el correo de recuperación a '.$usuario->correo, $usuario->id);

            return false;
        }

        $detalle = $estado === Password::RESET_LINK_SENT
            ? 'Enlace de recuperación enviado a '.$usuario->correo
            : 'Solicitud repetida antes de 60 segundos; no se envió otro enlace a '.$usuario->correo;
        BitacoraService::registrar('RECUPERACION_SOLICITADA', 'Usuario', $detalle, $usuario->id);

        return true;
    }

    /** ¿El enlace (token + correo) sigue vigente y sin usar? */
    public function enlaceVigente(string $correo, string $token): bool
    {
        $usuario = Usuario::buscarPorCorreo($correo);

        return $usuario !== null && $usuario->estado && Password::tokenExists($usuario, $token);
    }

    /**
     * Guarda la contraseña nueva (bcrypt en password_hash), invalida el token, limpia el bloqueo por
     * intentos fallidos de esa cuenta y registra en bitácora. Devuelve false si el enlace no sirve.
     *
     * @CC CCLogin::restablecerClave
     */
    public function restablecer(string $correo, string $token, string $clave, string $confirmacion): bool
    {
        $usuario = Usuario::buscarPorCorreo($correo);

        if (! $usuario || ! $usuario->estado) {
            return false;
        }

        $estado = Password::reset(
            ['correo' => $usuario->correo, 'estado' => true, 'token' => $token, 'password' => $clave, 'password_confirmation' => $confirmacion],
            function (Usuario $cuenta, string $nueva) {
                $cuenta->forceFill(['password_hash' => $nueva])->save(); // el cast «hashed» aplica bcrypt
                RateLimiter::clear(LoginService::llave($cuenta->correo));
                BitacoraService::registrar('RECUPERACION_COMPLETADA', 'Usuario', 'Contraseña restablecida con el enlace enviado a '.$cuenta->correo, $cuenta->id);
            }
        );

        return $estado === Password::PASSWORD_RESET;
    }
}
