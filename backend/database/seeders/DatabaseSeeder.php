<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Se puede ejecutar en cada arranque sin duplicar nada (todos los seeders son idempotentes).
 *
 *  - Siempre: roles, especialidades base y el administrador (contraseña de ADMIN_PASSWORD).
 *  - Con SEMBRAR_DEMO=true (por defecto en local): además los usuarios de demostración, médicos con horarios,
 *    días sin atención, pacientes con su historia clínica y algunas citas.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolSeeder::class,
            EspecialidadSeeder::class,
            AdministradorSeeder::class,
        ]);

        if (config('vidasalud.sembrar_demo')) {
            $this->call([
                UsuarioSeeder::class,
                MedicoSeeder::class,
                PacienteSeeder::class,
                CitaSeeder::class,
            ]);
        }
    }
}
