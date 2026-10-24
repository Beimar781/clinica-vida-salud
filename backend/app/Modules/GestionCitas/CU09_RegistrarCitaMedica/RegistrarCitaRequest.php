<?php

namespace App\Modules\GestionCitas\CU09_RegistrarCitaMedica;

use App\Models\Cita;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación en el servidor del formulario «Registrar cita»: los datos que llegan deben ser coherentes. Las reglas
 * del negocio (fecha pasada, horario ocupado, paciente inactivo...) las comprueba AgendarCitaService dentro de la transacción.
 *
 * @CU CU-09 @RF RF09 @CI CIRegistrarCita (confirmarCita) @CC CCRegistrarCita (validarReglas)
 */
class RegistrarCitaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el acceso ya está limitado a la Recepcionista en routes.php
    }

    public function rules(): array
    {
        return [
            'paciente_id' => ['required', 'integer', 'exists:pacientes,id'],
            'especialidad_id' => ['required', 'integer', 'exists:especialidades,id'],
            'medico_id' => ['required', 'integer', 'exists:medicos,id'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'hora_inicio' => ['required', 'date_format:H:i'],
            'modalidad' => ['required', Rule::in([Cita::PRESENCIAL, Cita::VIRTUAL])],
            'motivo' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'paciente_id.required' => 'Seleccione al paciente.',
            'paciente_id.exists' => 'El paciente seleccionado no existe.',
            'especialidad_id.required' => 'Seleccione la especialidad.',
            'especialidad_id.exists' => 'La especialidad seleccionada no existe.',
            'medico_id.required' => 'Seleccione el médico.',
            'medico_id.exists' => 'El médico seleccionado no existe.',
            'fecha.required' => 'Elija la fecha.',
            'fecha.date_format' => 'La fecha no es válida.',
            'hora_inicio.required' => 'Seleccione uno de los horarios libres.',
            'hora_inicio.date_format' => 'El horario seleccionado no es válido.',
            'modalidad.required' => 'Elija la modalidad (presencial o virtual).',
            'modalidad.in' => 'La modalidad debe ser presencial o virtual.',
            'motivo.max' => 'El motivo no puede superar los 200 caracteres.',
        ];
    }
}
