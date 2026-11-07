<?php

namespace Database\Seeders;

use App\Models\AntecedenteExterno;
use App\Models\Cita;
use App\Models\Consulta;
use App\Models\HistoriaClinica;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Usuario;
use Illuminate\Database\Seeder;

/**
 * Datos de demostración de la atención clínica (solo con SEMBRAR_DEMO=true): una consulta para cada cita Atendida de la
 * demostración y un antecedente de otro hospital para un paciente más. Idempotente registro por registro: una cita que ya tiene
 * consulta no se toca (la restricción UNIQUE de cita_id lo garantiza) y un antecedente existente no se repite.
 */
class ConsultaSeeder extends Seeder
{
    public function run(): void
    {
        // [CI del paciente, registro del médico, motivo de la cita Atendida, diagnóstico, tratamiento, observaciones]
        $consultas = [
            ['6543210', 'RM-1001', 'Control general', 'Paciente sana: control de rutina sin hallazgos.', 'Mantener hábitos saludables y control anual.', 'Se recomienda controlar la presión arterial en seis meses.'],
            ['9012345', 'RM-1002', 'Control pediátrico', 'Crecimiento y desarrollo normales para su edad.', 'Esquema de vacunas al día. Vitamina D según indicación.', null],
            ['7123456', 'RM-1006', 'Análisis de sangre', 'Hemograma y glucemia normales; colesterol levemente elevado.', 'Dieta baja en grasas y repetir el perfil lipídico en 3 meses.', null],
        ];

        foreach ($consultas as [$ci, $registro, $motivo, $diagnostico, $tratamiento, $observaciones]) {
            $paciente = Paciente::where('ci', $ci)->first();
            $medico = Medico::where('nro_registro', $registro)->first();
            $cita = $paciente && $medico
                ? Cita::where('paciente_id', $paciente->id)->where('medico_id', $medico->id)->where('motivo', $motivo)->where('estado', Cita::ATENDIDA)->first()
                : null;

            if (! $cita || Consulta::where('cita_id', $cita->id)->exists()) {
                continue;
            }

            $historia = $paciente->historiaClinica ?? HistoriaClinica::crearParaPaciente($paciente);
            Consulta::create([
                'cita_id' => $cita->id, 'historia_clinica_id' => $historia->id, 'medico_id' => $medico->id,
                'diagnostico' => $diagnostico, 'tratamiento' => $tratamiento, 'observaciones' => $observaciones,
            ]);
        }

        $this->antecedenteExterno();
    }

    /** Carlos Alberto Mamani trae su diagnóstico de diabetes de otro hospital (se registró en recepción). */
    private function antecedenteExterno(): void
    {
        $historia = Paciente::where('ci', '5987654')->first()?->historiaClinica;
        if (! $historia) {
            return;
        }

        AntecedenteExterno::firstOrCreate(
            ['historia_clinica_id' => $historia->id, 'hospital_origen' => 'Hospital Obrero N.º 1'],
            [
                'fecha_atencion' => '2025-11-12', 'especialidad' => 'Endocrinología', 'diagnostico' => 'Diabetes mellitus tipo 2',
                'tratamiento' => 'Metformina 850 mg cada 12 horas y control de glucemia', 'observaciones' => 'Control cada 3 meses.',
                'registrado_por' => Usuario::where('nombre_usuario', 'recepcion')->value('id'),
            ],
        );
    }
}
