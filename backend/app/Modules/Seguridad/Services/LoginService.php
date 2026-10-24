<?php

namespace App\Modules\Seguridad\Services;

use App\Modules\Seguridad\Models\Usuario;
use App\Support\BitacoraService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Inicio de sesión: límite de intentos por cuenta, verificación de la contraseña (bcrypt),
 * cuenta activa, sesión regenerada, último acceso y bitácora.
 *
 * @CU CU-01 @RF RF01 @CI CILogin @CC CCLogin @CE CEUsuario
 */
class LoginService
{
    /** Intentos fallidos permitidos por minuto; el siguiente intento queda bloqueado. */
    public const MAX_INTENTOS = 3;

    public const VENTANA_SEGUNDOS = 60;

    public const MENSAJE_CREDENCIALES = 'Credenciales incorrectas.';

    /** Hash bcrypt (costo 12) de un texto aleatorio descartado: se compara cuando la cuenta no existe. */
    private const HASH_FALSO = '$2y$12$gk7vwmDnJ3StTZ.DDgefX.dpJrBDxHayBF1UDoKR91kdRXrVsah3.';

    public const MENSAJE_NO_HABILITADO = 'El acceso no está habilitado.';

    /**
     * Autentica y abre la sesión.
     *
     * @CC CCLogin::autenticar
     *
     * @throws LoginFallidoException con un mensaje apto para el usuario
     */
    public function autenticar(string $identificador, string $clave, Request $request): Usuario
    {
        $identificador = trim($identificador);
        $usuario = Usuario::buscarPorIdentificador($identificador);
        $llave = self::llave($usuario?->correo ?? $identificador);

        $this->validarIntentos($llave, $identificador, $usuario);

        if (! $this->verificarClave($usuario, $clave)) {
            RateLimiter::hit($llave, self::VENTANA_SEGUNDOS);
            BitacoraService::registrar('LOGIN_FALLIDO', 'Usuario', 'Intento fallido para: '.$identificador, $usuario?->id);

            throw new LoginFallidoException(self::MENSAJE_CREDENCIALES);
        }

        // Las credenciales son correctas: solo ahora se informa que la cuenta no está habilitada.
        if (! $usuario->estado || $usuario->roles()->doesntExist()) {
            BitacoraService::registrar('LOGIN_RECHAZADO', 'Usuario', 'Cuenta inactiva o sin roles: '.$usuario->nombre_usuario, $usuario->id);

            throw new LoginFallidoException(self::MENSAJE_NO_HABILITADO);
        }

        RateLimiter::clear($llave);
        Auth::login($usuario);
        $request->session()->regenerate();
        $usuario->registrarAcceso();
        BitacoraService::registrar('LOGIN', 'Usuario', 'Inicio de sesión de '.$usuario->nombre_usuario, $usuario->id);

        return $usuario;
    }

    /**
     * Rechaza el intento si esa cuenta ya superó el límite en el último minuto.
     *
     * @CC CCLogin::validarIntentos
     *
     * @throws LoginFallidoException
     */
    public function validarIntentos(string $llave, string $identificador, ?Usuario $usuario): void
    {
        if (! RateLimiter::tooManyAttempts($llave, self::MAX_INTENTOS)) {
            return;
        }

        BitacoraService::registrar('LOGIN_BLOQUEADO', 'Usuario', 'Ingreso bloqueado temporalmente para: '.$identificador, $usuario?->id);

        throw new LoginFallidoException(
            'Demasiados intentos fallidos. Espere '.RateLimiter::availableIn($llave).' segundos e intente nuevamente.'
        );
    }

    /**
     * Compara la contraseña con el hash bcrypt. Si la cuenta no existe igual se hace una comparación
     * para que el tiempo de respuesta no revele si el correo está registrado.
     *
     * @CC CCLogin::verificarClave
     */
    public function verificarClave(?Usuario $usuario, string $clave): bool
    {
        $hash = $usuario?->password_hash ?? self::HASH_FALSO;

        return Hash::check($clave, $hash) && $usuario !== null;
    }

    /**
     * La llave del límite es el correo de la cuenta, de modo que entrar con el correo o con el
     * nombre de usuario cuenta contra el mismo bloqueo. Si el texto no corresponde a ninguna
     * cuenta se usa tal cual (en minúsculas).
     */
    public static function llave(string $correo): string
    {
        return 'login:'.mb_strtolower(trim($correo));
    }
}
