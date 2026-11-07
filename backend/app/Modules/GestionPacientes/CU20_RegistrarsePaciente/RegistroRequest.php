<?php

namespace App\Modules\GestionPacientes\CU20_RegistrarsePaciente;

use App\Modules\GestionPacientes\CU07_GestionarPacientes\PacienteService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación en el servidor del formulario «Crear cuenta» (paso 3 del flujo): datos obligatorios y su formato,
 * contraseña de al menos 8 caracteres y su confirmación. Que el CI o el correo ya tengan cuenta lo decide
 * RegistroPacienteService (paso 4), porque depende de la ficha que exista en la base.
 *
 * @CU CU-20 @RF RF20 @CI CIRegistro (validarDatos) @CC CCRegistro @CE CEUsuario, CEPaciente
 */
class RegistroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // pantalla pública: solo para quien no ha iniciado sesión (middleware «guest»)
    }

    protected function prepareForValidation(): void
    {
        $limpiar = fn (string $clave) => trim(preg_replace('/\s+/u', ' ', (string) $this->input($clave)));

        $this->merge([
            'ci' => PacienteService::normalizarCI((string) $this->input('ci')),
            'nombres' => $limpiar('nombres'),
            'apellidos' => $limpiar('apellidos'),
            'correo' => mb_strtolower(trim((string) $this->input('correo'))),
        ]);
    }

    public function rules(): array
    {
        return [
            'ci' => ['required', 'string', 'max:20', 'regex:/^[0-9A-Z][0-9A-Z\- ]{3,19}$/'],
            'nombres' => ['required', 'string', 'max:80'],
            'apellidos' => ['required', 'string', 'max:80'],
            'fecha_nacimiento' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:1900-01-01'],
            'sexo' => ['nullable', Rule::in(['M', 'F'])],
            'telefono' => ['required', 'regex:/^[0-9+\s-]{7,20}$/'],
            'correo' => ['required', 'email:filter', 'max:120'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'ci.required' => 'Ingrese su CI.',
            'ci.max' => 'El CI no puede superar los 20 caracteres.',
            'ci.regex' => 'El CI solo puede tener letras, números y guion (de 4 a 20 caracteres).',
            'nombres.required' => 'Ingrese sus nombres.',
            'apellidos.required' => 'Ingrese sus apellidos.',
            'fecha_nacimiento.required' => 'Ingrese su fecha de nacimiento.',
            'fecha_nacimiento.date_format' => 'La fecha de nacimiento no es válida.',
            'fecha_nacimiento.before_or_equal' => 'La fecha de nacimiento no puede ser futura.',
            'fecha_nacimiento.after_or_equal' => 'La fecha de nacimiento no es válida.',
            'sexo.in' => 'Seleccione masculino o femenino.',
            'telefono.required' => 'Ingrese su teléfono.',
            'telefono.regex' => 'El teléfono solo puede tener números (de 7 a 20 dígitos).',
            'correo.required' => 'Ingrese su correo.',
            'correo.email' => 'Ingrese un correo válido.',
            'correo.max' => 'El correo no puede superar los 120 caracteres.',
            'password.required' => 'Ingrese una contraseña.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.max' => 'La contraseña no puede superar los 72 caracteres.',
            'password.confirmed' => 'La confirmación no coincide con la contraseña.',
        ];
    }
}
