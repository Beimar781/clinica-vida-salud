<?php

namespace Database\Seeders;

use App\Models\Rol;
use Illuminate\Database\Seeder;

class RolSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            Rol::ADMINISTRADOR => 'Configura usuarios, médicos, especialidades y horarios; consulta reportes y bitácora',
            Rol::RECEPCIONISTA => 'Registra pacientes y programa, reprograma y cancela citas',
            Rol::MEDICO => 'Consulta su agenda y registra la atención de sus pacientes',
            Rol::PACIENTE => 'Reserva y consulta sus citas desde el sitio web',
        ];

        foreach ($roles as $nombre => $descripcion) {
            Rol::updateOrCreate(['nombre' => $nombre], ['descripcion' => $descripcion]);
        }
    }
}
