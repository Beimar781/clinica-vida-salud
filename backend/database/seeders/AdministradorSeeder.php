<?php

namespace Database\Seeders;

use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Administrador inicial. Su contraseña sale de ADMIN_PASSWORD (variable de entorno o .env) y su correo de
 * ADMIN_CORREO (por defecto admin@vidasalud.test). Tiene los roles Administrador y Recepcionista: la
 * recepción de la clínica (CU-09 y CU-10) solo la ejerce ese rol.
 *
 * Es idempotente: si ya existe la cuenta «admin» no la toca (así un cambio de contraseña hecho desde el
 * sistema no se pierde en el siguiente arranque) y solo se asegura de que conserve sus roles.
 */
class AdministradorSeeder extends Seeder
{
    public function run(): void
    {
        $clave = config('vidasalud.admin_password');
        if (blank($clave)) {
            throw new RuntimeException('Falta ADMIN_PASSWORD: defínala en el archivo .env (local) o en las variables del servicio (Railway) antes de ejecutar los seeders.');
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
