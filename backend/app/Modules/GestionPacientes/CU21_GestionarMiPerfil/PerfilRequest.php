<?php

namespace App\Modules\GestionPacientes\CU21_GestionarMiPerfil;

use App\Modules\GestionPacientes\CU07_GestionarPacientes\PacienteService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación en el servidor del perfil del paciente (paso 5 del flujo): datos obligatorios y su formato, límites de la
 * información médica y del contacto de emergencia, y el CI, que no se puede cambiar. La ficha que se edita siempre es
 * la de la cuenta (nunca un identificador que llegue en el envío).
 *
 * @CU CU-21 @RF RF21 @CI CIPerfil (validarDatos) @CC CCPerfil @CE CEPaciente, CEHistoriaClinica
 */
class PerfilRequest extends FormRequest
{
    public const TIPOS_SANGRE = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    public const MENSAJE_CI = 'El CI no se puede modificar. Solicítalo en recepción.';

    public function authorize(): bool
    {
        return true; // el acceso ya está limitado al rol Paciente en routes.php
    }

    protected function prepareForValidation(): void
    {
        $limpiar = fn (string $clave) => trim(preg_replace('/\s+/u', ' ', (string) $this->input($clave)));

        $this->merge([
            'nombres' => $limpiar('nombres'),
            'apellidos' => $limpiar('apellidos'),
            'correo' => mb_strtolower(trim((string) $this->input('correo'))),
            'recibe_recordatorios' => $this->boolean('recibe_recordatorios'),
            'mostrar_contacto_medico' => $this->boolean('mostrar_contacto_medico'),
        ]);
    }

    public function rules(): array
    {
        $ficha = $this->user()?->paciente;
        $telefono = ['regex:/^[0-9+\s-]{7,20}$/'];

        return [
            // El CI solo se muestra: si llega y es distinto del de la ficha, se rechaza.
            'ci' => ['nullable', 'string', function (string $atributo, mixed $valor, Closure $fallar) use ($ficha) {
                if ($ficha && PacienteService::normalizarCI((string) $valor) !== $ficha->ci) {
                    $fallar(self::MENSAJE_CI);
                }
            }],
            'nombres' => ['required', 'string', 'max:80'],
            'apellidos' => ['required', 'string', 'max:80'],
            'fecha_nacimiento' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:1900-01-01'],
            'sexo' => ['nullable', Rule::in(['M', 'F'])],
            'telefono' => array_merge(['required'], $telefono),
            'correo' => ['nullable', 'email:filter', 'max:120'],
            'direccion' => ['nullable', 'string', 'max:200'],
            'tipo_sangre' => ['nullable', Rule::in(self::TIPOS_SANGRE)],
            'alergias' => ['nullable', 'string', 'max:200'],
            'enfermedades_cronicas' => ['nullable', 'string', 'max:300'],
            'medicamentos' => ['nullable', 'string', 'max:300'],
            'antecedentes_personales' => ['nullable', 'string', 'max:400'],
            'emergencia_nombre' => ['nullable', 'string', 'max:100'],
            'emergencia_parentesco' => ['nullable', 'string', 'max:40'],
            'emergencia_telefono' => array_merge(['nullable'], $telefono),
            'recibe_recordatorios' => ['boolean'],
            'mostrar_contacto_medico' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombres.required' => 'Ingrese sus nombres.',
            'apellidos.required' => 'Ingrese sus apellidos.',
            'fecha_nacimiento.required' => 'Ingrese su fecha de nacimiento.',
            'fecha_nacimiento.date_format' => 'La fecha de nacimiento no es válida.',
            'fecha_nacimiento.before_or_equal' => 'La fecha de nacimiento no puede ser futura.',
            'fecha_nacimiento.after_or_equal' => 'La fecha de nacimiento no es válida.',
            'sexo.in' => 'Seleccione masculino o femenino.',
            'telefono.required' => 'Ingrese su teléfono.',
            'telefono.regex' => 'El teléfono solo puede tener números (de 7 a 20 dígitos).',
            'correo.email' => 'Ingrese un correo válido.',
            'direccion.max' => 'La dirección no puede superar los 200 caracteres.',
            'tipo_sangre.in' => 'Seleccione un tipo de sangre de la lista.',
            'alergias.max' => 'Las alergias no pueden superar los 200 caracteres.',
            'enfermedades_cronicas.max' => 'Las enfermedades crónicas no pueden superar los 300 caracteres.',
            'medicamentos.max' => 'Los medicamentos no pueden superar los 300 caracteres.',
            'antecedentes_personales.max' => 'Los antecedentes no pueden superar los 400 caracteres.',
            'emergencia_telefono.regex' => 'El teléfono de emergencia solo puede tener números (de 7 a 20 dígitos).',
        ];
    }
}
