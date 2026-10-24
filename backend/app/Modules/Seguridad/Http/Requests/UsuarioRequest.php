<?php

namespace App\Modules\Seguridad\Http\Requests;

use App\Modules\Seguridad\Models\Usuario;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación en el servidor al crear o editar un usuario (la contraseña es obligatoria solo al crear).
 *
 * @CU CU-03 @RF RF03 @CI CIUsuarios (enviarDatos) @CC CCUsuarios
 */
class UsuarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nombre_usuario' => trim((string) $this->input('nombre_usuario')),
            'correo' => mb_strtolower(trim((string) $this->input('correo'))),
        ]);
    }

    public function rules(): array
    {
        $id = $this->route('usuario')?->id;

        return [
            'nombre_usuario' => [
                'required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/',
                // Único sin distinguir mayúsculas.
                function (string $atributo, mixed $valor, \Closure $fallar) use ($id) {
                    $existe = Usuario::whereRaw('lower(nombre_usuario) = ?', [mb_strtolower($valor)])
                        ->when($id, fn ($q) => $q->where('id', '!=', $id))->exists();
                    if ($existe) {
                        $fallar('El nombre de usuario ya está registrado.');
                    }
                },
            ],
            'correo' => ['required', 'email:filter', 'max:120', Rule::unique('usuarios', 'correo')->ignore($id)],
            'password' => [$id ? 'nullable' : 'required', 'string', 'min:8', 'max:72'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['integer', 'exists:roles,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre_usuario.required' => 'Ingrese el nombre de usuario.',
            'nombre_usuario.min' => 'El nombre de usuario debe tener al menos 3 caracteres.',
            'nombre_usuario.max' => 'El nombre de usuario no puede superar los 50 caracteres.',
            'nombre_usuario.regex' => 'Use solo letras, números, punto, guion y guion bajo (sin espacios ni @).',
            'correo.required' => 'Ingrese el correo.',
            'correo.email' => 'Ingrese un correo válido.',
            'correo.max' => 'El correo no puede superar los 120 caracteres.',
            'correo.unique' => 'El correo ya está registrado.',
            'password.required' => 'Ingrese la contraseña.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.max' => 'La contraseña no puede superar los 72 caracteres.',
            'roles.required' => 'Seleccione al menos un rol.',
            'roles.min' => 'Seleccione al menos un rol.',
            'roles.*.exists' => 'El rol seleccionado no existe.',
        ];
    }
}
