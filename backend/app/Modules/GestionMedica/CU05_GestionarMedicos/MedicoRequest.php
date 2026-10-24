<?php

namespace App\Modules\GestionMedica\CU05_GestionarMedicos;

use App\Models\Usuario;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación en el servidor del formulario de médicos (sirve para registrar y para editar).
 * Si se marca «crear cuenta de acceso» también se valida el usuario, el correo y la contraseña.
 *
 * @CU CU-05 @RF RF05 @CI CIMedicos (enviarDatos) @CC CCMedicos @CE CEMedico, CEEspecialidad
 */
class MedicoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el acceso ya está limitado al Administrador en routes.php
    }

    protected function prepareForValidation(): void
    {
        $medico = $this->route('medico'); // null cuando se está registrando
        $limpiar = fn (string $clave) => trim(preg_replace('/\s+/u', ' ', (string) $this->input($clave)));

        $this->merge([
            'nombres' => $limpiar('nombres'),
            'apellidos' => $limpiar('apellidos'),
            'nro_registro' => mb_strtoupper(trim((string) $this->input('nro_registro'))),
            'correo' => mb_strtolower(trim((string) $this->input('correo'))),
            'puede_marcar_atendida' => $this->boolean('puede_marcar_atendida'),
            // Un médico que ya tiene cuenta no vuelve a crearla.
            'crear_cuenta' => $this->boolean('crear_cuenta') && ! $medico?->usuario_id,
        ]);
    }

    public function rules(): array
    {
        $id = $this->route('medico')?->id;
        $conCuenta = fn () => (bool) $this->input('crear_cuenta');

        return [
            'nombres' => ['required', 'string', 'max:80'],
            'apellidos' => ['required', 'string', 'max:80'],
            'nro_registro' => [
                'required', 'string', 'max:30',
                function (string $atributo, mixed $valor, Closure $fallar) use ($id) {
                    if (app(MedicoService::class)->existeNroRegistro($valor, $id)) {
                        $fallar(MedicoService::MENSAJE_REGISTRO_REPETIDO);
                    }
                },
            ],
            'telefono' => ['nullable', 'regex:/^[0-9+\s-]{7,20}$/'],
            'correo' => [Rule::requiredIf($conCuenta), 'nullable', 'email:filter', 'max:120'],
            'ubicacion' => ['nullable', 'string', 'max:100'],
            'especialidades' => ['required', 'array', 'min:1'],
            'especialidades.*' => ['integer', 'exists:especialidades,id'],
            'puede_marcar_atendida' => ['boolean'],
            'crear_cuenta' => ['boolean'],
            'nombre_usuario' => [
                Rule::requiredIf($conCuenta), 'nullable', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/',
                function (string $atributo, mixed $valor, Closure $fallar) use ($conCuenta) {
                    if ($conCuenta() && Usuario::whereRaw('lower(nombre_usuario) = ?', [mb_strtolower($valor)])->exists()) {
                        $fallar('El nombre de usuario ya está registrado.');
                    }
                },
            ],
            'password' => [Rule::requiredIf($conCuenta), 'nullable', 'string', 'min:8', 'max:72'],
        ];
    }

    /** La cuenta se crea con el correo del médico: no puede coincidir con el de otra cuenta. */
    public function after(): array
    {
        return [function ($validator) {
            if ($this->input('crear_cuenta') && ! $validator->errors()->has('correo')
                && Usuario::where('correo', $this->input('correo'))->exists()) {
                $validator->errors()->add('correo', 'El correo ya está registrado como cuenta de usuario.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'nombres.required' => 'Ingrese los nombres.',
            'apellidos.required' => 'Ingrese los apellidos.',
            'nro_registro.required' => 'Ingrese el número de registro profesional.',
            'nro_registro.max' => 'El número de registro no puede superar los 30 caracteres.',
            'telefono.regex' => 'El teléfono solo puede tener números (de 7 a 20 dígitos).',
            'correo.required' => 'Ingrese el correo del médico para crear su cuenta de acceso.',
            'correo.email' => 'Ingrese un correo válido.',
            'ubicacion.max' => 'La ubicación no puede superar los 100 caracteres.',
            'especialidades.required' => 'Seleccione al menos una especialidad.',
            'especialidades.min' => 'Seleccione al menos una especialidad.',
            'especialidades.*.exists' => 'La especialidad seleccionada no existe.',
            'nombre_usuario.required' => 'Ingrese el nombre de usuario de la cuenta.',
            'nombre_usuario.min' => 'El nombre de usuario debe tener al menos 3 caracteres.',
            'nombre_usuario.regex' => 'Use solo letras, números, punto, guion y guion bajo (sin espacios ni @).',
            'password.required' => 'Ingrese la contraseña de la cuenta.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
        ];
    }
}
