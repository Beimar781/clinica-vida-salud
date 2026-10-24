<?php

namespace App\Modules\GestionCitas\CU08_ConsultarDisponibilidadHorarios;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación en el servidor de la consulta (médico y fecha, enviados por GET). Una fecha pasada NO se rechaza
 * aquí: DisponibilidadService la informa y no muestra horarios, como pide la ficha.
 *
 * @CU CU-08 @RF RF08 @CI CIDisponibilidad (elegirMedico, elegirFecha) @CC CCDisponibilidad
 */
class DisponibilidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el acceso ya está limitado a Recepcionista y Paciente en routes.php
    }

    public function rules(): array
    {
        return [
            'medico' => ['nullable', 'required_with:fecha', 'integer', Rule::exists('medicos', 'id')->where('estado', true)],
            'fecha' => ['nullable', 'required_with:medico', 'date_format:Y-m-d'],
        ];
    }

    public function messages(): array
    {
        return [
            'medico.required_with' => 'Elija el médico.',
            'medico.exists' => 'El médico elegido no existe o está inactivo.',
            'fecha.required_with' => 'Elija la fecha.',
            'fecha.date_format' => 'La fecha no es válida.',
        ];
    }
}
