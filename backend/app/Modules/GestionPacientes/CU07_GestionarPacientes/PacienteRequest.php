<?php

namespace App\Modules\GestionPacientes\CU07_GestionarPacientes;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación en el servidor del formulario de pacientes (sirve para registrar y para editar): datos
 * obligatorios y su formato, CI único y, si se marca «viene de otro hospital», los datos del antecedente.
 *
 * @CU CU-07 @RF RF07 @CI CIPacientes (enviarDatos) @CC CCPacientes @CE CEPaciente (existeCI), CEAntecedente
 */
class PacienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el acceso ya está limitado a Recepcionista y Administrador en routes.php
    }

    protected function prepareForValidation(): void
    {
        $limpiar = fn (string $clave) => trim(preg_replace('/\s+/u', ' ', (string) $this->input($clave)));

        $this->merge([
            'ci' => PacienteService::normalizarCI((string) $this->input('ci')),
            'nombres' => $limpiar('nombres'),
            'apellidos' => $limpiar('apellidos'),
            'correo' => mb_strtolower(trim((string) $this->input('correo'))),
            'recibe_recordatorios' => $this->boolean('recibe_recordatorios'),
            'mostrar_contacto_medico' => $this->boolean('mostrar_contacto_medico'),
            'con_antecedente' => $this->boolean('con_antecedente'),
        ]);
    }

    public function rules(): array
    {
        $id = $this->route('paciente')?->id; // null cuando se está registrando
        $conAntecedente = fn () => (bool) $this->input('con_antecedente');
        $telefono = ['regex:/^[0-9+\s-]{7,20}$/'];

        return [
            'ci' => [
                'required', 'string', 'max:20', 'regex:/^[0-9A-Z][0-9A-Z\- ]{3,19}$/',
                function (string $atributo, mixed $valor, Closure $fallar) use ($id) {
                    if (app(PacienteService::class)->existeCI($valor, $id)) {
                        $fallar(PacienteService::MENSAJE_CI_REPETIDO);
                    }
                },
            ],
            'nombres' => ['required', 'string', 'max:80'],
            'apellidos' => ['required', 'string', 'max:80'],
            'fecha_nacimiento' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:1900-01-01'],
            'sexo' => ['nullable', Rule::in(['M', 'F'])],
            'telefono' => array_merge(['required'], $telefono),
            'correo' => ['nullable', 'email:filter', 'max:120'],
            'direccion' => ['nullable', 'string', 'max:200'],
            'emergencia_nombre' => ['nullable', 'string', 'max:100'],
            'emergencia_parentesco' => ['nullable', 'string', 'max:40'],
            'emergencia_telefono' => array_merge(['nullable'], $telefono),
            'recibe_recordatorios' => ['boolean'],
            'mostrar_contacto_medico' => ['boolean'],
            'con_antecedente' => ['boolean'],
            'ant_hospital_origen' => [Rule::requiredIf($conAntecedente), 'nullable', 'string', 'max:120'],
            'ant_fecha_atencion' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'ant_especialidad' => ['nullable', 'string', 'max:80'],
            'ant_diagnostico' => [Rule::requiredIf($conAntecedente), 'nullable', 'string', 'max:300'],
            'ant_tratamiento' => ['nullable', 'string', 'max:400'],
            'ant_observaciones' => ['nullable', 'string', 'max:400'],
        ];
    }

    public function messages(): array
    {
        return [
            'ci.required' => 'Ingrese el CI.',
            'ci.max' => 'El CI no puede superar los 20 caracteres.',
            'ci.regex' => 'El CI solo puede tener letras, números y guion (de 4 a 20 caracteres).',
            'nombres.required' => 'Ingrese los nombres.',
            'apellidos.required' => 'Ingrese los apellidos.',
            'fecha_nacimiento.required' => 'Ingrese la fecha de nacimiento.',
            'fecha_nacimiento.date_format' => 'La fecha de nacimiento no es válida.',
            'fecha_nacimiento.before_or_equal' => 'La fecha de nacimiento no puede ser futura.',
            'fecha_nacimiento.after_or_equal' => 'La fecha de nacimiento no es válida.',
            'sexo.in' => 'Seleccione masculino o femenino.',
            'telefono.required' => 'Ingrese el teléfono.',
            'telefono.regex' => 'El teléfono solo puede tener números (de 7 a 20 dígitos).',
            'correo.email' => 'Ingrese un correo válido.',
            'direccion.max' => 'La dirección no puede superar los 200 caracteres.',
            'emergencia_telefono.regex' => 'El teléfono de emergencia solo puede tener números (de 7 a 20 dígitos).',
            'ant_hospital_origen.required' => 'Ingrese el hospital o clínica de origen.',
            'ant_hospital_origen.max' => 'El hospital de origen no puede superar los 120 caracteres.',
            'ant_diagnostico.required' => 'Ingrese el diagnóstico que consta en sus documentos.',
            'ant_diagnostico.max' => 'El diagnóstico no puede superar los 300 caracteres.',
            'ant_fecha_atencion.date_format' => 'La fecha de atención no es válida.',
            'ant_fecha_atencion.before_or_equal' => 'La fecha de atención no puede ser futura.',
        ];
    }
}
