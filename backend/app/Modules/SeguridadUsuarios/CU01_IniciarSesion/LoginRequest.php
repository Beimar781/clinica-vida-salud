<?php

namespace App\Modules\SeguridadUsuarios\CU01_IniciarSesion;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación en el servidor del formulario de ingreso.
 *
 * @CU CU-01 @RF RF01 @CI CILogin (enviarCredenciales) @CC CCLogin
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identificador' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'identificador.required' => 'Ingrese su correo o nombre de usuario.',
            'identificador.max' => 'El correo o nombre de usuario es demasiado largo.',
            'password.required' => 'Ingrese su contraseña.',
        ];
    }
}
