<?php

namespace App\Modules\AtencionClinica\CU16_RegistrarConsultaTratamiento;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación en el servidor del formulario de la consulta: el diagnóstico es obligatorio; el tratamiento y las observaciones son
 * opcionales. Los límites son los del esquema de la tabla consultas (diagnóstico 300, tratamiento 400, observaciones 400). Las reglas
 * de quién puede registrar y en qué estado las comprueba ConsultaService dentro de la transacción.
 *
 * @CU CU-16 @RF RF16 @CI CIConsulta (enviarConsulta) @CC CCConsulta (registrar)
 */
class ConsultaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el acceso por rol está en routes.php; la cita propia y el permiso los comprueba ConsultaService
    }

    public function rules(): array
    {
        return [
            'diagnostico' => ['required', 'string', 'max:300'],
            'tratamiento' => ['nullable', 'string', 'max:400'],
            'observaciones' => ['nullable', 'string', 'max:400'],
        ];
    }

    public function messages(): array
    {
        return [
            'diagnostico.required' => 'Ingrese el diagnóstico de la consulta.',
            'diagnostico.max' => 'El diagnóstico no puede superar los 300 caracteres.',
            'tratamiento.max' => 'El tratamiento no puede superar los 400 caracteres.',
            'observaciones.max' => 'Las observaciones no pueden superar los 400 caracteres.',
        ];
    }
}
