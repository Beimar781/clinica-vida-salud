<?php

namespace App\Modules\GestionPacientes\CU21_GestionarMiPerfil;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del cambio de contraseña del perfil (paso 4 del flujo): contraseña actual, nueva de al menos 8 caracteres,
 * distinta de la actual, y su confirmación. Que la actual sea la correcta lo comprueba PerfilService.
 *
 * @CU CU-21 @RF RF21 @CI CIPerfil (validarDatos) @CC CCPerfil (cambiarClave) @CE CEUsuario
 */
class PerfilContrasenaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el acceso ya está limitado al rol Paciente en routes.php
    }

    public function rules(): array
    {
        return [
            'password_actual' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed', 'different:password_actual'],
        ];
    }

    public function messages(): array
    {
        return [
            'password_actual.required' => 'Ingrese su contraseña actual.',
            'password.required' => 'Ingrese la contraseña nueva.',
            'password.min' => 'La contraseña nueva debe tener al menos 8 caracteres.',
            'password.max' => 'La contraseña no puede superar los 72 caracteres.',
            'password.confirmed' => 'La confirmación no coincide con la contraseña nueva.',
            'password.different' => 'La contraseña nueva debe ser distinta de la actual.',
        ];
    }
}
