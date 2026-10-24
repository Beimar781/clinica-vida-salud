<?php

namespace App\Modules\SeguridadUsuarios\CU01_IniciarSesion;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del formulario de contraseña nueva (mínimo 8 caracteres y confirmación).
 *
 * @CU CU-01 (flujo alternativo: recuperar contraseña) @RF RF01 @CI CILogin
 */
class RestablecerClaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'correo' => ['required', 'string', 'email', 'max:120'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'token.required' => 'El enlace de recuperación no es válido. Solicita uno nuevo.',
            'correo.required' => 'El enlace de recuperación no es válido. Solicita uno nuevo.',
            'correo.email' => 'El enlace de recuperación no es válido. Solicita uno nuevo.',
            'password.required' => 'Ingresa la contraseña nueva.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'La confirmación no coincide con la contraseña.',
        ];
    }
}
