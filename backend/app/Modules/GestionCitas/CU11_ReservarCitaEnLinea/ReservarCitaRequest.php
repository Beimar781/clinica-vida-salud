<?php

namespace App\Modules\GestionCitas\CU11_ReservarCitaEnLinea;

use App\Models\Cita;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación en el servidor del formulario «Reservar cita». No lleva el paciente: la cita siempre queda a nombre
 * de la cuenta autenticada, nunca de un identificador que llegue en el formulario.
 *
 * @CU CU-11 @RF RF11 @CI CIReservarCita (confirmarReserva) @CC CCReservarCita
 */
class ReservarCitaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el acceso ya está limitado al rol Paciente en routes.php
    }

    public function rules(): array
    {
        return [
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
