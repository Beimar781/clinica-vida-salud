<?php

namespace App\Modules\ReportesBitacora\CU19_ConsultarBitacora;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de los filtros de la consulta de la bitácora (se envían por GET).
 *
 * @CU CU-19 @RF RF19 @CI CIBitacora (filtrar) @CC CCBitacora
 */
class BitacoraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Id de un usuario o «sin» (acciones sin usuario: visitantes y sistema).
            'usuario' => ['nullable', 'regex:/^(sin|\d{1,18})$/'],
            'entidad' => ['nullable', 'string', 'max:40'],
            'accion' => ['nullable', 'string', 'max:60'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'usuario.regex' => 'El usuario elegido no es válido.',
            'desde.date_format' => 'La fecha «desde» no es válida.',
            'hasta.date_format' => 'La fecha «hasta» no es válida.',
            'hasta.after_or_equal' => 'La fecha «hasta» no puede ser anterior a la fecha «desde».',
            'q.max' => 'El texto a buscar es demasiado largo (máximo 100 caracteres).',
        ];
    }

    /**
     * Solo los filtros con valor (sin la página), para aplicarlos y mostrarlos en el formulario.
     *
     * @return array<string, string>
     */
    public function filtros(): array
    {
        return collect($this->validated())
            ->only(['usuario', 'entidad', 'accion', 'desde', 'hasta', 'q'])
            ->filter(fn ($valor) => filled($valor))
            ->map(fn ($valor) => (string) $valor)
            ->all();
    }
}
