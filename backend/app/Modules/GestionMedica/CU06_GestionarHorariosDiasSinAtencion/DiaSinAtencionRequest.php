<?php

namespace App\Modules\GestionMedica\CU06_GestionarHorariosDiasSinAtencion;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

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
                'bail', 'required', 'date_format:Y-m-d',
                // «2031-02-31» cumple el formato pero no existe en el calendario: se rechaza antes de tocar la base.
                function (string $atributo, mixed $valor, Closure $fallar) {
                    $fecha = Carbon::createFromFormat('Y-m-d', $valor);
                    if ($fecha === false || $fecha->format('Y-m-d') !== $valor) {
                        $fallar('La fecha no es válida.');
                    }
                },
                'after_or_equal:today',
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
