<?php

namespace Database\Seeders;

use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Database\Seeder;

/**
 * Administrador inicial. Su contraseña sale de ADMIN_PASSWORD (variable de entorno o .env) si está definida;
 * si no, usa la contraseña de demostración (Demo1234*), igual que las demás cuentas de demostración. Su correo
 * sale de ADMIN_CORREO (por defecto admin@vidasalud.test). Tiene únicamente el rol Administrador: ese rol
 * hereda las funciones de recepción (ver Usuario::tieneRol), así que no necesita el rol Recepcionista.
 *
 * Es idempotente: si ya existe la cuenta «admin» no la toca (así un cambio de contraseña hecho desde el
 * sistema no se pierde en el siguiente arranque) y solo deja sus roles en uno solo, Administrador (corrige
 * las cuentas que se sembraron antes con los roles Administrador y Recepcionista).
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

        $admin->roles()->sync([Rol::where('nombre', Rol::ADMINISTRADOR)->value('id')]);
    }
}
