<?php

namespace App\Modules\GestionMedica\CU06_GestionarHorariosDiasSinAtencion;

use App\Models\HorarioAtencion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validación en el servidor del formulario de un bloque de atención (registrar y editar).
 * Orden de las reglas del documento: hora final posterior a la inicial, no superponer otro bloque del
 * mismo médico y día, y duración que divida exactamente el bloque (avisa cuántos minutos sobran).
 *
 * @CU CU-06 @RF RF06 @CI CIHorarios (enviarDatos) @CC CCHorarios (validarHoras) @CE CEHorario (haySuperposicion)
 */
class HorarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el acceso ya está limitado al Administrador en routes.php
    }

    public function rules(): array
    {
        $registrando = $this->route('horario') === null;

        return [
            // Al registrar se indica el médico (debe estar activo); al editar el médico es el del bloque.
            'medico_id' => [Rule::requiredIf($registrando), 'nullable', Rule::exists('medicos', 'id')->where('estado', true)],
            'dia_semana' => ['required', 'integer', 'between:1,7'],
            'hora_inicio' => ['required', 'date_format:H:i'],
            'hora_fin' => ['required', 'date_format:H:i'],
            'duracion_min' => ['required', 'integer', 'between:5,240'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            // Solo se comprueban las reglas del bloque si los campos tienen un formato válido.
            if ($validator->errors()->hasAny(['dia_semana', 'hora_inicio', 'hora_fin', 'duracion_min', 'medico_id'])) {
                return;
            }

            $servicio = app(HorarioService::class);
            $inicio = $this->input('hora_inicio');
            $fin = $this->input('hora_fin');
            $duracion = (int) $this->input('duracion_min');

            if (! $servicio->validarHoras($inicio, $fin)) {
                $validator->errors()->add('hora_fin', 'La hora final debe ser posterior a la hora inicial.');

                return;
            }

            /** @var HorarioAtencion|null $actual */
            $actual = $this->route('horario');
            $medicoId = $actual?->medico_id ?? (int) $this->input('medico_id');

            if ($otro = $servicio->bloqueEnConflicto($medicoId, (int) $this->input('dia_semana'), $inicio, $fin, $actual?->id)) {
                $validator->errors()->add('hora_inicio', HorarioService::mensajeSuperposicion($otro));
            }

            if ($sobrantes = $servicio->minutosSobrantes($inicio, $fin, $duracion)) {
                $validator->errors()->add('duracion_min', HorarioService::mensajeDuracion($sobrantes));
            }
        }];
    }

    public function messages(): array
    {
        return [
            'medico_id.required' => 'Seleccione el médico.',
            'medico_id.exists' => 'El médico seleccionado no existe o está inactivo.',
            'dia_semana.required' => 'Seleccione el día de la semana.',
            'dia_semana.between' => 'El día de la semana no es válido.',
            'hora_inicio.required' => 'Ingrese la hora inicial.',
            'hora_inicio.date_format' => 'La hora inicial no es válida (use HH:MM).',
            'hora_fin.required' => 'Ingrese la hora final.',
            'hora_fin.date_format' => 'La hora final no es válida (use HH:MM).',
            'duracion_min.required' => 'Ingrese la duración de la cita en minutos.',
            'duracion_min.integer' => 'La duración debe ser un número entero de minutos.',
            'duracion_min.between' => 'La duración de la cita debe estar entre 5 y 240 minutos.',
        ];
    }
}
