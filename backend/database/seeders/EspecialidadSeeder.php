<?php

namespace Database\Seeders;

use App\Models\Especialidad;
use App\Support\Texto;
use Illuminate\Database\Seeder;

class EspecialidadSeeder extends Seeder
{
    public function run(): void
    {
        $especialidades = [
            'Medicina general' => 'Atención primaria y control de la salud del adulto',
            'Pediatría' => 'Atención médica de niños y adolescentes',
            'Ginecología' => 'Salud de la mujer y control prenatal',
            'Odontología' => 'Salud bucal y tratamientos dentales',
            'Cardiología' => 'Diagnóstico y control de enfermedades del corazón',
        ];

        foreach ($especialidades as $nombre => $descripcion) {
            Especialidad::updateOrCreate(
                ['nombre_normalizado' => Texto::normalizar($nombre)],
                ['nombre' => $nombre, 'descripcion' => $descripcion, 'estado' => true],
            );
        }
    }
}
