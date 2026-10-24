<?php

namespace Database\Seeders;

use App\Models\BloqueoAgenda;
use App\Models\Cita;
use App\Models\HorarioAtencion;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Usuario;
use App\Support\Codigos;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Citas de demostración en todos los estados, pasadas y próximas. Cada una cae en un día en que el
 * médico atiende (y que no es feriado), en el bloque que corresponda a su horario.
 */
class CitaSeeder extends Seeder
{
    public function run(): void
    {
        if (Cita::exists()) {
            return;
        }

        $recepcionId = Usuario::where('nombre_usuario', 'recepcion')->value('id');

        // [registro del médico, CI del paciente, días desde hoy, posición del bloque, estado, modalidad, motivo]
        $citas = [
            ['RM-1001', '6543210', -14, 0, Cita::ATENDIDA, Cita::PRESENCIAL, 'Control general'],
            ['RM-1002', '9012345', -10, 1, Cita::ATENDIDA, Cita::PRESENCIAL, 'Control pediátrico'],
            ['RM-1001', '5987654', -7, 2, Cita::INASISTENCIA, Cita::PRESENCIAL, 'Chequeo anual'],
            ['RM-1003', '10234567', -3, 0, Cita::CANCELADA, Cita::PRESENCIAL, 'Control ginecológico'],
            ['RM-1001', '6543210', 1, 0, Cita::CONFIRMADA, Cita::PRESENCIAL, 'Dolor de cabeza frecuente'],
            ['RM-1001', '7123456', 1, 1, Cita::PROGRAMADA, Cita::PRESENCIAL, 'Control de presión arterial'],
            ['RM-1002', '9012345', 2, 2, Cita::PROGRAMADA, Cita::PRESENCIAL, 'Vacunas'],
            ['RM-1003', '10234567', 3, 0, Cita::PROGRAMADA, Cita::VIRTUAL, 'Consulta de resultados'],
            ['RM-1004', '5987654', 4, 1, Cita::CONFIRMADA, Cita::PRESENCIAL, 'Limpieza dental'],
            ['RM-1005', '6543210', 5, 0, Cita::PROGRAMADA, Cita::PRESENCIAL, 'Evaluación cardiológica'],
            ['RM-1001', '11345678', 7, 3, Cita::PROGRAMADA, Cita::VIRTUAL, null],
            ['RM-1002', '7123456', 8, 0, Cita::CANCELADA, Cita::PRESENCIAL, null],
        ];

        foreach ($citas as [$registro, $ci, $dias, $posicion, $estado, $modalidad, $motivo]) {
            $medico = Medico::with('especialidades')->where('nro_registro', $registro)->firstOrFail();
            $paciente = Paciente::where('ci', $ci)->firstOrFail();
            [$fecha, $inicio, $fin] = $this->horarioLibre($medico, Carbon::today()->addDays($dias), $posicion);

            Cita::create([
                'codigo' => Codigos::cita(),
                'paciente_id' => $paciente->id,
                'medico_id' => $medico->id,
                'especialidad_id' => $medico->especialidades->first()->id,
                'fecha' => $fecha->toDateString(),
                'hora_inicio' => $inicio,
                'hora_fin' => $fin,
                'modalidad' => $modalidad,
                'motivo' => $motivo,
                'estado' => $estado,
                'motivo_cancelacion' => $estado === Cita::CANCELADA ? 'Cancelada a pedido del paciente' : null,
                'creado_por' => $recepcionId,
            ]);
        }
    }

    /**
     * Primer día desde $desde en que el médico atiende (sin feriado) y el bloque número $posicion
     * (contado desde el primer horario del día).
     *
     * @return array{0: Carbon, 1: string, 2: string}
     */
    private function horarioLibre(Medico $medico, Carbon $desde, int $posicion): array
    {
        $bloqueados = BloqueoAgenda::where('estado', true)->pluck('fecha')->map->toDateString()->all();
        $horarios = HorarioAtencion::where('medico_id', $medico->id)->where('estado', true)
            ->orderBy('hora_inicio')->get()->groupBy('dia_semana');

        for ($fecha = $desde->copy(); ; $fecha->addDay()) {
            if (in_array($fecha->toDateString(), $bloqueados, true) || ! isset($horarios[$fecha->dayOfWeekIso])) {
                continue;
            }

            $bloque = $horarios[$fecha->dayOfWeekIso]->first();
            $inicio = Carbon::parse($bloque->hora_inicio)->addMinutes($posicion * $bloque->duracion_min);

            return [$fecha, $inicio->format('H:i'), $inicio->copy()->addMinutes($bloque->duracion_min)->format('H:i')];
        }
    }
}
