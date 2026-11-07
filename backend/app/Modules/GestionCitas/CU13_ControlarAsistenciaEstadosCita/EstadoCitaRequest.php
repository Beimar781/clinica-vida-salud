<?php

namespace App\Modules\GestionCitas\CU13_ControlarAsistenciaEstadosCita;

use App\Modules\GestionCitas\Compartido\TransicionesCita;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Validación en el servidor del cambio de estado: la acción debe ser una de las conocidas y, si el administrador corrige un
 * estado, hay que indicar el nuevo estado y la justificación. Las reglas de transición (rol, estado actual, hora) las valida
 * TransicionesCita dentro de la transacción. Los errores de validación se muestran como aviso sobre la agenda del día.
 *
 * @CU CU-13 @RF RF13 @CI CIEstadoCita (elegirAccion) @CC CCEstadoCita (validarTransicion)
 */
class EstadoCitaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el acceso por rol está en routes.php; TransicionesCita valida la acción para el rol
    }

    public function rules(): array
    {
        return [
            'accion' => ['required', Rule::in(TransicionesCita::ACCIONES)],
            'estado_nuevo' => ['nullable', 'required_if:accion,corregir', Rule::in(array_keys(EstadoCitaService::ESTADOS))],
            'justificacion' => ['nullable', 'required_if:accion,corregir', 'string', 'max:150'],
            'volver_fecha' => ['nullable', 'date_format:Y-m-d'],
            'volver_estado' => ['nullable', Rule::in(array_keys(EstadoCitaService::ESTADOS))],
            'volver_medico' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'accion.required' => 'Elija la acción que desea realizar.',
            'accion.in' => 'La acción solicitada no es válida.',
            'estado_nuevo.required_if' => 'Elija el nuevo estado de la cita.',
            'estado_nuevo.in' => 'El estado elegido no es válido.',
            'justificacion.required_if' => 'Ingrese la justificación de la corrección: es obligatoria y queda en la bitácora.',
            'justificacion.max' => 'La justificación no puede superar los 150 caracteres.',
        ];
    }

    /** Vuelve a la agenda del día con el aviso (no a la página anterior, que puede ser un POST). */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            redirect()->route('control.index', $this->filtros())->with('error', $validator->errors()->first())
        );
    }

    /** Filtros de la agenda del día a la que se vuelve después de la acción. */
    public function filtros(): array
    {
        return array_filter([
            'fecha' => $this->input('volver_fecha'),
            'estado' => $this->input('volver_estado'),
            'medico' => $this->input('volver_medico'),
        ], fn ($valor) => filled($valor));
    }
}
