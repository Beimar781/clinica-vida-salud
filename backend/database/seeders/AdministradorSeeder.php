<?php

namespace Database\Seeders;

use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Database\Seeder;

/**
 * Administrador inicial. Su contraseña sale de ADMIN_PASSWORD (variable de entorno o .env) si está definida;
 * si no, usa la contraseña de demostración (Demo1234*), igual que las demás cuentas de demostración. Su correo
 * sale de ADMIN_CORREO (por defecto admin@vidasalud.test). Tiene los roles Administrador y Recepcionista: la
 * recepción de la clínica (CU-09 y CU-10) solo la ejerce ese rol.
 *
 * Es idempotente: si ya existe la cuenta «admin» no la toca (así un cambio de contraseña hecho desde el
 * sistema no se pierde en el siguiente arranque) y solo se asegura de que conserve sus roles.
 */
class AdministradorSeeder extends Seeder
{
    public function run(): void
    {
        $clave = config('vidasalud.admin_password') ?: config('vidasalud.demo_password');

        if (blank(config('vidasalud.admin_password')) && app()->environment('production')) {
            $this->command?->warn('ADMIN_PASSWORD no está definida: el administrador usa la contraseña de demostración, que es pública. Defina ADMIN_PASSWORD o cámbiela desde «Usuarios y roles».');
        }

        $admin = Usuario::where('nombre_usuario', 'admin')->first()
            ?? Usuario::create([
                'nombre_usuario' => 'admin',
                'correo' => config('vidasalud.admin_correo'),
                'password_hash' => $clave,
                'estado' => true,
            ]);

        $ids = Rol::whereIn('nombre', [Rol::ADMINISTRADOR, Rol::RECEPCIONISTA])->pluck('id')->all();
        $admin->roles()->syncWithoutDetaching($ids);
    }
}
