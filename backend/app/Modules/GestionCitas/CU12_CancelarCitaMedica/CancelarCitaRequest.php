<?php

namespace App\Modules\GestionCitas\CU12_CancelarCitaMedica;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación en el servidor del formulario «Cancelar cita»: el motivo es obligatorio (se guarda en motivo_cancelacion, de
 * hasta 200 caracteres). Las reglas del negocio (estado, anticipación, cita propia, cita pagada) las comprueba
 * CancelarCitaService dentro de la transacción.
 *
 * @CU CU-12 @RF RF12 @CI CICancelarCita (pedirMotivo, confirmarCancelacion) @CC CCCancelarCita
 */
class CancelarCitaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el acceso por rol está en routes.php y la cita propia la comprueba CancelarCitaService
    }

    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'max:200'],
            'confirmo_sin_devolucion' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'motivo.required' => 'Ingrese el motivo de la cancelación.',
            'motivo.max' => 'El motivo no puede superar los 200 caracteres.',
        ];
    }
}
