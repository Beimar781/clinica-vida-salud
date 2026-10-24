<?php

namespace Database\Seeders;

use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Usuarios de demostración. El administrador toma su contraseña de ADMIN_PASSWORD (.env);
 * las demás cuentas usan la contraseña de demostración de config/vidasalud.php (ver README).
 */
class UsuarioSeeder extends Seeder
{
    public function run(): void
    {
        $claveAdmin = config('vidasalud.admin_password');
        if (blank($claveAdmin)) {
            throw new RuntimeException('Defina ADMIN_PASSWORD en el archivo .env antes de ejecutar los seeders.');
        }
        $claveDemo = config('vidasalud.demo_password');

        // nombre_usuario => [correo, contraseña, roles]
        $usuarios = [
            'admin' => ['admin@vidasalud.test', $claveAdmin, [Rol::ADMINISTRADOR, Rol::RECEPCIONISTA]],
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
