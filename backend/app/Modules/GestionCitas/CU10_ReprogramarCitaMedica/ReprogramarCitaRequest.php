<?php

namespace App\Modules\GestionCitas\CU10_ReprogramarCitaMedica;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación en el servidor del formulario «Reprogramar»: nueva fecha, nuevo horario y motivo del cambio (obligatorio).
 * Las reglas del negocio las comprueba AgendarCitaService dentro de la transacción.
 *
 * @CU CU-10 @RF RF10 @CI CIReprogramarCita (confirmarCambio) @CC CCReprogramarCita
 */
class ReprogramarCitaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el acceso ya está limitado a la Recepcionista en routes.php
    }

    public function rules(): array
    {
        return [
            'fecha' => ['required', 'date_format:Y-m-d'],
            'hora_inicio' => ['required', 'date_format:H:i'],
            'motivo_cambio' => ['required', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'fecha.required' => 'Elija la nueva fecha.',
            'fecha.date_format' => 'La fecha no es válida.',
            'hora_inicio.required' => 'Seleccione uno de los horarios libres.',
            'hora_inicio.date_format' => 'El horario seleccionado no es válido.',
            'motivo_cambio.required' => 'Ingrese el motivo del cambio.',
            'motivo_cambio.max' => 'El motivo del cambio no puede superar los 200 caracteres.',
        ];
    }
}
