<?php

namespace App\Modules\GestionMedica\CU04_GestionarEspecialidadesServicios;

use App\Models\Especialidad;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación en el servidor del formulario de especialidades y servicios (sirve para registrar y para editar).
 * El nombre no puede repetirse una vez normalizado: «cardiologia » es igual a «Cardiología». El tipo es
 * consulta médica, laboratorio o enfermería y el precio va en bolivianos (0 a 999999,99, dos decimales).
 *
 * @CU CU-04 @RF RF04 @CI CIEspecialidad (enviarDatos) @CC CCEspecialidad @CE CEEspecialidad (existeNombre)
 */
class EspecialidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el acceso ya está limitado al Administrador en routes.php
    }

    /** Acepta la coma decimal («100,50») además del punto, y quita espacios. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->precio)) {
            $this->merge(['precio' => str_replace(',', '.', trim($this->precio))]);
        }
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
            'tipo' => ['required', Rule::in(array_keys(Especialidad::TIPOS))],
            'precio' => ['required', 'numeric', 'min:0', 'max:999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'descripcion' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'Ingrese el nombre de la especialidad o del servicio.',
            'nombre.max' => 'El nombre no puede superar los 80 caracteres.',
            'tipo.required' => 'Seleccione el tipo: consulta médica, laboratorio o enfermería.',
            'tipo.in' => 'Seleccione el tipo: consulta médica, laboratorio o enfermería.',
            'precio.required' => 'Ingrese el precio en bolivianos (puede ser 0).',
            'precio.numeric' => 'El precio debe ser un número, por ejemplo 100 o 100,50.',
            'precio.min' => 'El precio no puede ser negativo.',
            'precio.max' => 'El precio no puede superar Bs 999.999,99.',
            'precio.regex' => 'El precio admite hasta dos decimales, por ejemplo 100,50.',
            'descripcion.max' => 'La descripción no puede superar los 200 caracteres.',
        ];
    }
}
