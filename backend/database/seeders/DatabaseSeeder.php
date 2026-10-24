<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Datos base y de demostración: roles, usuarios de cada rol, especialidades, médicos con horarios,
 * días sin atención, pacientes con su historia clínica y algunas citas.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolSeeder::class,
            EspecialidadSeeder::class,
            UsuarioSeeder::class,
            MedicoSeeder::class,
            PacienteSeeder::class,
            CitaSeeder::class,
        ]);
    }
}
