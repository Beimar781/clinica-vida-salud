<?php

namespace App\Modules\GestionMedica\CU06_GestionarHorariosDiasSinAtencion;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación en el servidor del formulario de días sin atención: la fecha no puede estar repetida ni ser
 * anterior a hoy y el motivo es obligatorio.
 *
 * @CU CU-06 @RF RF06 @CI CIHorarios (enviarDatos) @CC CCHorarios @CE CEHorario
 */
class DiaSinAtencionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el acceso ya está limitado al Administrador en routes.php
    }

    public function rules(): array
    {
        return [
            'fecha' => [
                'required', 'date_format:Y-m-d', 'after_or_equal:today',
                function (string $atributo, mixed $valor, Closure $fallar) {
                    if (app(DiaSinAtencionService::class)->existeFecha($valor)) {
                        $fallar(DiaSinAtencionService::MENSAJE_REPETIDA);
                    }
                },
            ],
            'motivo' => ['required', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'fecha.required' => 'Ingrese la fecha.',
            'fecha.date_format' => 'La fecha no es válida.',
            'fecha.after_or_equal' => 'La fecha no puede ser anterior a hoy.',
            'motivo.required' => 'Ingrese el motivo (por ejemplo, el nombre del feriado).',
            'motivo.max' => 'El motivo no puede superar los 120 caracteres.',
        ];
    }
}
