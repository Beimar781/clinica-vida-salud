<?php

namespace Database\Seeders;

use App\Models\Especialidad;
use App\Support\Texto;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Datos base del catálogo de especialidades y servicios (v30, CU-04). Corre siempre, también en producción.
 *
 * Idempotente y respetuoso con lo que el administrador cambie después en CU-04: una fila nueva se crea con su
 * tipo y su precio; una fila que ya existe no se pisa (ni su nombre, ni su estado, ni su precio), salvo que
 * tenga precio 0, que es el valor con que la migración del v30 dejó las filas del Ciclo I: en ese caso recibe
 * el precio base. Los precios son estimados según el medio de Santa Cruz (en bolivianos).
 *
 * Al final, las citas ya registradas con monto 0 (las del Ciclo I, antes de que existiera el precio) copian el
 * precio de su especialidad; las citas con un monto ya guardado no se tocan.
 */
class EspecialidadSeeder extends Seeder
{
    public function run(): void
    {
        // nombre => [descripción, tipo, precio en Bs]
        $catalogo = [
            'Medicina general' => ['Atención primaria y control de la salud del adulto', Especialidad::CONSULTA, 100],
            'Pediatría' => ['Atención médica de niños y adolescentes', Especialidad::CONSULTA, 150],
            'Ginecología' => ['Salud de la mujer y control prenatal', Especialidad::CONSULTA, 150],
            'Odontología' => ['Salud bucal y tratamientos dentales', Especialidad::CONSULTA, 150],
            'Cardiología' => ['Diagnóstico y control de enfermedades del corazón', Especialidad::CONSULTA, 150],
            'Laboratorio' => ['Análisis básicos', Especialidad::LABORATORIO, 80],
            'Enfermería' => ['Curaciones leves', Especialidad::ENFERMERIA, 40],
        ];

        foreach ($catalogo as $nombre => [$descripcion, $tipo, $precio]) {
            $especialidad = Especialidad::firstOrCreate(
                ['nombre_normalizado' => Texto::normalizar($nombre)],
                ['nombre' => $nombre, 'descripcion' => $descripcion, 'tipo' => $tipo, 'precio' => $precio, 'estado' => true],
            );

            if (! $especialidad->wasRecentlyCreated && (float) $especialidad->precio === 0.0) {
                $especialidad->update(['precio' => $precio]);
            }
        }

        // Citas del Ciclo I (monto 0): copian el precio de su especialidad.
        DB::update(
            'UPDATE citas SET monto = e.precio FROM especialidades e WHERE e.id = citas.especialidad_id AND citas.monto = 0 AND e.precio > 0'
        );
    }
}
