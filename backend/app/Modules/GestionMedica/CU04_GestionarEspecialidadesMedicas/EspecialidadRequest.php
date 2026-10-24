<?php

namespace App\Modules\GestionMedica\CU04_GestionarEspecialidadesMedicas;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación en el servidor del formulario de especialidades (sirve para registrar y para editar).
 * El nombre no puede repetirse una vez normalizado: «cardiologia » es igual a «Cardiología».
 *
 * @CU CU-04 @RF RF04 @CI CIEspecialidad (enviarDatos) @CC CCEspecialidad @CE CEEspecialidad (existeNombre)
 */
class EspecialidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el acceso ya está limitado al Administrador en routes.php
    }

    public function rules(): array
    {
        $id = $this->route('especialidad')?->id; // null cuando se está registrando

        return [
            'nombre' => [
                'required', 'string', 'max:80',
                function (string $atributo, mixed $valor, Closure $fallar) use ($id) {
                    if (app(EspecialidadService::class)->existeNombre($valor, $id)) {
                        $fallar(EspecialidadService::MENSAJE_DUPLICADO);
                    }
                },
            ],
            'descripcion' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'Ingrese el nombre de la especialidad.',
            'nombre.max' => 'El nombre no puede superar los 80 caracteres.',
            'descripcion.max' => 'La descripción no puede superar los 200 caracteres.',
        ];
    }
}
