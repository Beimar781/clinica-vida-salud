<?php

namespace App\Modules\SeguridadUsuarios\CU01_IniciarSesion;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del formulario «¿Olvidaste tu contraseña?».
 *
 * @CU CU-01 (flujo alternativo: recuperar contraseña) @RF RF01 @CI CILogin
 */
class OlvidoClaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['correo' => ['required', 'string', 'email', 'max:120']];
    }

    public function messages(): array
    {
        return [
            'correo.required' => 'Ingresa el correo de tu cuenta.',
            'correo.email' => 'Ingresa un correo válido.',
            'correo.max' => 'El correo es demasiado largo.',
        ];
    }
}
