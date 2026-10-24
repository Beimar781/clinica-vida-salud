<?php

namespace Database\Seeders;

use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Database\Seeder;

/**
 * Usuarios de demostración (solo con SEMBRAR_DEMO=true): recepción, médicos y pacientes con la contraseña de
 * demostración de config/vidasalud.php (ver README). El administrador lo crea AdministradorSeeder.
 * Idempotente: si la cuenta ya existe no se toca.
 */
class UsuarioSeeder extends Seeder
{
    public function run(): void
    {
        $claveDemo = config('vidasalud.demo_password');

        // nombre_usuario => [correo, contraseña, roles]
        $usuarios = [
            'recepcion' => ['recepcion@vidasalud.test', $claveDemo, [Rol::RECEPCIONISTA]],
            'cmontano' => ['carla.montano@vidasalud.test', $claveDemo, [Rol::MEDICO]],
            'lsoliz' => ['luis.soliz@vidasalud.test', $claveDemo, [Rol::MEDICO]],
            // Un médico que también es paciente: ve las opciones de ambos roles.
            'pvargas' => ['patricia.vargas@vidasalud.test', $claveDemo, [Rol::MEDICO, Rol::PACIENTE]],
            'mrojas' => ['maria.rojas@correo.test', $claveDemo, [Rol::PACIENTE]],
            'jperez' => ['juan.perez@correo.test', $claveDemo, [Rol::PACIENTE]],
        ];

        $ids = Rol::pluck('id', 'nombre');

        foreach ($usuarios as $nombreUsuario => [$correo, $clave, $roles]) {
            $usuario = Usuario::firstOrCreate(
                ['correo' => $correo],
                ['nombre_usuario' => $nombreUsuario, 'password_hash' => $clave, 'estado' => true],
            );
            $usuario->roles()->syncWithoutDetaching($ids->only($roles)->values()->all());
        }
    }
}
