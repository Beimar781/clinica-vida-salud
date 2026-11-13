<?php

namespace App\Modules\SeguridadUsuarios\CU03_GestionarUsuariosRoles;

use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\GestionPacientes\CU07_GestionarPacientes\PacienteService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación en el servidor al crear o editar un usuario (la contraseña es obligatoria solo al crear).
 *
 * @CU CU-03 @RF RF03 @CI CIUsuarios (enviarDatos) @CC CCUsuarios
 */
class UsuarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nombre_usuario' => trim((string) $this->input('nombre_usuario')),
            'correo' => mb_strtolower(trim((string) $this->input('correo'))),
            'ci' => PacienteService::normalizarCI((string) $this->input('ci')),
            'nombres' => trim(preg_replace('/\s+/u', ' ', (string) $this->input('nombres'))),
            'apellidos' => trim(preg_replace('/\s+/u', ' ', (string) $this->input('apellidos'))),
        ]);
    }

    /** ¿Hay que pedir los datos de la ficha? Con el rol Paciente marcado y sin ficha todavía (cuenta nueva, o cuenta existente sin ficha). */
    private function pideFicha(): bool
    {
        $idPaciente = Rol::where('nombre', Rol::PACIENTE)->value('id');
        $marcado = in_array($idPaciente, array_map('intval', (array) $this->input('roles', [])), true);

        return $marcado && ! ($this->route('usuario')?->paciente()->exists());
    }

    /** Los nombres y apellidos solo hacen falta si la ficha no existe todavía (si existe, se vincula y conserva los suyos). */
    private function pideNombres(): bool
    {
        return $this->pideFicha() && ! Paciente::where('ci', (string) $this->input('ci'))->exists();
    }

    public function rules(): array
    {
        $id = $this->route('usuario')?->id;

        return [
            'nombre_usuario' => [
                'required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/',
                // Único sin distinguir mayúsculas.
                function (string $atributo, mixed $valor, \Closure $fallar) use ($id) {
                    $existe = Usuario::whereRaw('lower(nombre_usuario) = ?', [mb_strtolower($valor)])
                        ->when($id, fn ($q) => $q->where('id', '!=', $id))->exists();
                    if ($existe) {
                        $fallar('El nombre de usuario ya está registrado.');
                    }
                },
            ],
            'correo' => ['required', 'email:filter', 'max:120', Rule::unique('usuarios', 'correo')->ignore($id)],
            'password' => [$id ? 'nullable' : 'required', 'string', 'min:8', 'max:72'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['integer', 'exists:roles,id'],
            // Ficha del paciente: toda cuenta con el rol Paciente debe tener su ficha (se crea o se vincula una existente con ese CI).
            'ci' => [Rule::requiredIf(fn () => $this->pideFicha()), 'nullable', 'string', 'max:20', 'regex:/^[0-9A-Z][0-9A-Z\- ]{3,19}$/'],
            'nombres' => [Rule::requiredIf(fn () => $this->pideNombres()), 'nullable', 'string', 'max:80'],
            'apellidos' => [Rule::requiredIf(fn () => $this->pideNombres()), 'nullable', 'string', 'max:80'],
            'fecha_nacimiento' => [Rule::requiredIf(fn () => $this->pideNombres()), 'nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:1900-01-01'],
            'telefono' => [Rule::requiredIf(fn () => $this->pideNombres()), 'nullable', 'regex:/^[0-9+\s-]{7,20}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre_usuario.required' => 'Ingrese el nombre de usuario.',
            'nombre_usuario.min' => 'El nombre de usuario debe tener al menos 3 caracteres.',
            'nombre_usuario.max' => 'El nombre de usuario no puede superar los 50 caracteres.',
            'nombre_usuario.regex' => 'Use solo letras, números, punto, guion y guion bajo (sin espacios ni @).',
            'correo.required' => 'Ingrese el correo.',
            'correo.email' => 'Ingrese un correo válido.',
            'correo.max' => 'El correo no puede superar los 120 caracteres.',
            'correo.unique' => 'El correo ya está registrado.',
            'password.required' => 'Ingrese la contraseña.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.max' => 'La contraseña no puede superar los 72 caracteres.',
            'roles.required' => 'Seleccione al menos un rol.',
            'roles.min' => 'Seleccione al menos un rol.',
            'roles.*.exists' => 'El rol seleccionado no existe.',
            'ci.required' => 'Ingrese el CI del paciente: toda cuenta con el rol Paciente necesita su ficha.',
            'ci.regex' => 'El CI solo puede tener letras, números y guion (de 4 a 20 caracteres).',
            'nombres.required' => 'Ingrese los nombres del paciente.',
            'apellidos.required' => 'Ingrese los apellidos del paciente.',
            'fecha_nacimiento.required' => 'Ingrese la fecha de nacimiento.',
            'fecha_nacimiento.date_format' => 'La fecha de nacimiento no es válida.',
            'fecha_nacimiento.before_or_equal' => 'La fecha de nacimiento no puede ser futura.',
            'fecha_nacimiento.after_or_equal' => 'La fecha de nacimiento no es válida.',
            'telefono.required' => 'Ingrese el teléfono del paciente.',
            'telefono.regex' => 'El teléfono solo puede tener números (de 7 a 20 dígitos).',
        ];
    }
}
