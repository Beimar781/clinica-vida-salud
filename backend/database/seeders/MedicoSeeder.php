<?php

namespace Database\Seeders;

use App\Models\BloqueoAgenda;
use App\Models\Especialidad;
use App\Models\HorarioAtencion;
use App\Models\Medico;
use App\Models\Usuario;
use App\Support\Codigos;
use App\Support\Texto;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Médicos de demostración con sus especialidades y horarios (dia_semana: 1 = lunes ... 7 = domingo),
 * y los próximos feriados como días sin atención.
 */
class MedicoSeeder extends Seeder
{
    public function run(): void
    {
        $lunAVie = [1, 2, 3, 4, 5];

        $medicos = [
            [
                'nro_registro' => 'RM-1001', 'nombres' => 'Carla', 'apellidos' => 'Montaño Rojas',
                'usuario' => 'cmontano', 'telefono' => '70011001', 'correo' => 'carla.montano@vidasalud.test',
                'ubicacion' => 'Sede Central · Consultorio 101', 'puede_marcar_atendida' => true,
                'especialidades' => ['Medicina general'],
                'horarios' => [[$lunAVie, '08:00', '12:00', 30], [[1, 3, 5], '14:00', '18:00', 30]],
            ],
            [
                'nro_registro' => 'RM-1002', 'nombres' => 'Luis Fernando', 'apellidos' => 'Soliz Peña',
                'usuario' => 'lsoliz', 'telefono' => '70011002', 'correo' => 'luis.soliz@vidasalud.test',
                'ubicacion' => 'Sede Central · Consultorio 102', 'puede_marcar_atendida' => true,
                'especialidades' => ['Pediatría'],
                'horarios' => [[$lunAVie, '08:00', '12:00', 20], [[2, 4], '15:00', '18:00', 30]],
            ],
            [
                'nro_registro' => 'RM-1003', 'nombres' => 'Patricia', 'apellidos' => 'Vargas Egüez',
                'usuario' => 'pvargas', 'telefono' => '70011003', 'correo' => 'patricia.vargas@vidasalud.test',
                'ubicacion' => 'Sede Equipetrol · Consultorio 3', 'puede_marcar_atendida' => true,
                'especialidades' => ['Ginecología'],
                'horarios' => [[[1, 3], '09:00', '13:00', 30], [[2, 4], '14:00', '18:00', 30]],
            ],
            [
                'nro_registro' => 'RM-1004', 'nombres' => 'Marcelo', 'apellidos' => 'Antelo Paz',
                'usuario' => null, 'telefono' => '70011004', 'correo' => null,
                'ubicacion' => 'Sede Equipetrol · Consultorio 5', 'puede_marcar_atendida' => true,
                'especialidades' => ['Odontología'],
                'horarios' => [[$lunAVie, '14:00', '19:00', 60]],
            ],
            [
                // Sin permiso para marcar citas como atendidas (lo marca la recepción o el administrador).
                'nro_registro' => 'RM-1005', 'nombres' => 'Jorge', 'apellidos' => 'Rivero Salvatierra',
                'usuario' => null, 'telefono' => '70011005', 'correo' => null,
                'ubicacion' => 'Sede Central · Consultorio 201', 'puede_marcar_atendida' => false,
                'especialidades' => ['Cardiología', 'Medicina general'],
                'horarios' => [[[2, 4], '08:00', '12:00', 30], [[5], '14:00', '17:00', 30]],
            ],
        ];

        foreach ($medicos as $datos) {
            $existente = Medico::where('nro_registro', $datos['nro_registro'])->first();
            if ($existente) {
                continue;
            }

            $medico = Medico::create([
                'usuario_id' => $datos['usuario'] ? Usuario::where('nombre_usuario', $datos['usuario'])->value('id') : null,
                'codigo' => Codigos::medico(),
                'nro_registro' => $datos['nro_registro'],
                'nombres' => $datos['nombres'],
                'apellidos' => $datos['apellidos'],
                'telefono' => $datos['telefono'],
                'correo' => $datos['correo'],
                'ubicacion' => $datos['ubicacion'],
                'puede_marcar_atendida' => $datos['puede_marcar_atendida'],
                'estado' => true,
            ]);

            $normalizados = array_map(Texto::normalizar(...), $datos['especialidades']);
            $medico->especialidades()->sync(
                Especialidad::whereIn('nombre_normalizado', $normalizados)->pluck('id')->all()
            );

            foreach ($datos['horarios'] as [$dias, $inicio, $fin, $duracion]) {
                foreach ($dias as $dia) {
                    HorarioAtencion::create([
                        'medico_id' => $medico->id,
                        'dia_semana' => $dia,
                        'hora_inicio' => $inicio,
                        'hora_fin' => $fin,
                        'duracion_min' => $duracion,
                        'estado' => true,
                    ]);
                }
            }
        }

        $this->sembrarFeriados();
    }

    /** Próxima ocurrencia de cada feriado (día sin atención para toda la clínica). */
    private function sembrarFeriados(): void
    {
        $feriados = [
            [11, 2, 'Día de los Difuntos'],
            [12, 25, 'Navidad'],
            [1, 1, 'Año Nuevo'],
        ];

        $hoy = Carbon::today();
        foreach ($feriados as [$mes, $dia, $motivo]) {
            $fecha = Carbon::create($hoy->year, $mes, $dia)->startOfDay();
            if ($fecha->lt($hoy)) {
                $fecha = $fecha->addYear();
            }
            BloqueoAgenda::firstOrCreate(['fecha' => $fecha->toDateString()], ['motivo' => $motivo, 'estado' => true]);
        }
    }
}
