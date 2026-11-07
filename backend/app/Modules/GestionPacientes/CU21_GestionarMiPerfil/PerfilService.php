<?php

namespace App\Modules\GestionPacientes\CU21_GestionarMiPerfil;

use App\Models\HistoriaClinica;
use App\Models\Paciente;
use App\Models\Usuario;
use App\Services\BitacoraService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Perfil del paciente: sus datos personales, de contacto, de emergencia y sus preferencias viven en `pacientes`; su información
 * médica (tipo de sangre, alergias, crónicas, medicamentos y antecedentes) en su historia clínica, sin tocar las consultas.
 * El CI no se edita. Todo cambio queda en la bitácora indicando qué secciones cambiaron (nunca contraseñas ni datos clínicos).
 *
 * @CU CU-21 @RF RF21 @CI CIPerfil @CC CCPerfil @CE CEPaciente (actualizar), CEHistoriaClinica (actualizar), CEUsuario (cambiarContrasena)
 */
class PerfilService
{
    public const MENSAJE_CLAVE_ACTUAL = 'La contraseña actual es incorrecta.';

    /** Secciones del perfil y sus campos (la bitácora dice cuáles cambiaron). */
    private const SECCIONES = [
        'datos personales' => ['nombres', 'apellidos', 'fecha_nacimiento', 'sexo'],
        'contacto' => ['telefono', 'correo', 'direccion'],
        'contacto de emergencia' => ['emergencia_nombre', 'emergencia_parentesco', 'emergencia_telefono'],
        'preferencias de la cuenta' => ['recibe_recordatorios', 'mostrar_contacto_medico'],
    ];

    private const CAMPOS_MEDICOS = ['tipo_sangre', 'alergias', 'enfermedades_cronicas', 'medicamentos', 'antecedentes_personales'];

    /**
     * La ficha del paciente de la cuenta (nunca de un identificador de la URL); null si la cuenta aún no tiene ficha.
     *
     * @CE CEPaciente (obtenerPorUsuario)
     */
    public function fichaDe(Usuario $usuario): ?Paciente
    {
        return $usuario->paciente;
    }

    /** Historia clínica de la ficha (vacía y sin guardar si todavía no se abrió). */
    public function historiaDe(Paciente $paciente): HistoriaClinica
    {
        return $paciente->historiaClinica ?? new HistoriaClinica(['paciente_id' => $paciente->id]);
    }

    /**
     * Pasos 5 y 6: guarda los cambios de una o más secciones y los registra en la bitácora.
     *
     * @CC CCPerfil (actualizar) @CE CEPaciente (actualizar), CEHistoriaClinica (actualizar)
     *
     * @param  array<string, mixed>  $datos  campos validados de PerfilRequest
     * @return list<string> secciones que cambiaron (vacío si no hubo cambios)
     */
    public function actualizar(Paciente $paciente, array $datos, Usuario $usuario): array
    {
        return DB::transaction(function () use ($paciente, $datos, $usuario) {
            $cambiadas = [];

            // Solo se aplican los campos que el perfil permite editar: el CI, el código y el vínculo con la cuenta no.
            foreach (self::SECCIONES as $seccion => $campos) {
                $paciente->fill(array_intersect_key($datos, array_flip($campos)));
                if ($paciente->isDirty($campos)) {
                    $cambiadas[] = $seccion;
                }
            }
            $paciente->save();

            $historia = HistoriaClinica::firstOrCreate(['paciente_id' => $paciente->id]);
            $historia->fill(array_intersect_key($datos, array_flip(self::CAMPOS_MEDICOS)));
            if ($historia->isDirty(self::CAMPOS_MEDICOS)) {
                $cambiadas[] = 'información médica';
            }
            $historia->save();

            if ($cambiadas !== []) {
                BitacoraService::registrar(
                    'PERFIL_ACTUALIZADO', 'Paciente',
                    "{$paciente->codigo} actualizó su perfil: ".implode(', ', $cambiadas),
                    $usuario->id
                );
            }

            return $cambiadas;
        });
    }

    /**
     * Paso 4: cambia la contraseña si la actual es correcta. La sesión se renueva y la bitácora no guarda ninguna contraseña.
     *
     * @CC CCPerfil (cambiarContrasena) @CE CEUsuario (cambiarContrasena)
     *
     * @throws ValidationException si la contraseña actual es incorrecta
     */
    public function cambiarContrasena(Usuario $usuario, string $actual, string $nueva): void
    {
        if (! Hash::check($actual, $usuario->password_hash)) {
            BitacoraService::registrar('PERFIL_CLAVE_RECHAZADA', 'Usuario', 'Cambio de contraseña rechazado: la contraseña actual era incorrecta', $usuario->id);
            throw ValidationException::withMessages(['password_actual' => self::MENSAJE_CLAVE_ACTUAL]);
        }

        $usuario->forceFill(['password_hash' => $nueva])->save(); // el cast «hashed» guarda el hash bcrypt
        request()->session()->regenerate();
        BitacoraService::registrar('PERFIL_ACTUALIZADO', 'Usuario', 'Cambió su contraseña', $usuario->id);
    }
}
