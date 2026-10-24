<?php

namespace Tests\Feature;

use App\Models\BloqueoAgenda;
use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\HorarioAtencion;
use App\Models\Medico;
use App\Models\Paciente;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CU-09, caso 5 del plan 8.1: dos usuarios reservan el mismo horario a la vez.
 *
 * Esta prueba NO usa DatabaseTransactions: dos procesos PHP distintos necesitan ver los datos confirmados en la base
 * (una transacción de prueba no la verían). Por eso crea sus propios datos «T-...» y los borra al terminar, en un
 * try/finally, sin tocar los de demostración.
 */
class CU09_RegistrarCitaMedicaConcurrenciaTest extends TestCase
{
    /** Dos recepcionistas confirman el mismo horario en el mismo instante: una gana y la otra recibe el aviso. */
    public function test_dos_confirmaciones_simultaneas_del_mismo_horario_solo_registran_una(): void
    {
        $sufijo = strtoupper(Str::random(6));
        $creados = ['citas' => [], 'pacientes' => [], 'medicos' => [], 'especialidades' => []];

        try {
            $especialidad = Especialidad::create(['nombre' => 'T-Conc '.$sufijo, 'nombre_normalizado' => 't-conc '.strtolower($sufijo), 'estado' => true]);
            $creados['especialidades'][] = $especialidad->id;
            $medico = Medico::create(['codigo' => 'T-'.$sufijo, 'nro_registro' => 'T-CONC-'.$sufijo, 'nombres' => 'Conc', 'apellidos' => 'Medico', 'estado' => true]);
            $creados['medicos'][] = $medico->id;
            $medico->especialidades()->attach($especialidad->id);
            HorarioAtencion::create(['medico_id' => $medico->id, 'dia_semana' => 1, 'hora_inicio' => '08:00', 'hora_fin' => '12:00', 'duracion_min' => 30, 'estado' => true]);

            $pacientes = [];
            foreach (['A', 'B'] as $letra) {
                $pacientes[$letra] = Paciente::create([
                    'codigo' => 'T-'.$sufijo.$letra, 'ci' => 'T-'.$sufijo.$letra, 'nombres' => 'Conc', 'apellidos' => 'Paciente '.$letra,
                    'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000', 'estado' => true,
                ]);
                $creados['pacientes'][] = $pacientes[$letra]->id;
            }

            $fecha = $this->proximoLunes();

            // Los dos procesos esperan el mismo instante y confirman a la vez.
            $instante = microtime(true) + 3.0;
            $resultados = $this->ejecutarEnParalelo([
                [$instante, $medico->id, $pacientes['A']->id, $especialidad->id, $fecha, '09:00'],
                [$instante, $medico->id, $pacientes['B']->id, $especialidad->id, $fecha, '09:00'],
            ]);

            $creados['citas'] = Cita::where('medico_id', $medico->id)->pluck('id')->all();
            $porResultado = collect($resultados)->groupBy('resultado');

            $this->assertCount(1, $porResultado->get('creada', []), 'Solo una confirmación puede registrarse. Resultados: '.json_encode($resultados));
            $this->assertCount(1, $porResultado->get('ocupado', []), 'La otra recibe el aviso de horario ocupado. Resultados: '.json_encode($resultados));
            $this->assertSame(
                'El horario fue ocupado por otro usuario. Elija otro de los horarios libres que se muestran.',
                $porResultado->get('ocupado')[0]['mensaje']
            );

            // En la base queda una sola cita activa en ese horario, y el horario ya no se ofrece.
            $this->assertSame(1, Cita::where('medico_id', $medico->id)->whereDate('fecha', $fecha)->where('hora_inicio', '09:00')->where('estado', '<>', Cita::CANCELADA)->count());
        } finally {
            Cita::whereIn('medico_id', $creados['medicos'])->delete();
            DB::table('medico_especialidad')->whereIn('medico_id', $creados['medicos'])->delete();
            HorarioAtencion::whereIn('medico_id', $creados['medicos'])->delete();
            Medico::whereIn('id', $creados['medicos'])->delete();
            Paciente::whereIn('id', $creados['pacientes'])->delete();
            Especialidad::whereIn('id', $creados['especialidades'])->delete();
        }
    }

    /**
     * Lanza un proceso PHP por cada juego de argumentos y espera a todos.
     *
     * @param  list<list<mixed>>  $argumentos
     * @return list<array<string, mixed>>
     */
    private function ejecutarEnParalelo(array $argumentos): array
    {
        $guion = base_path('tests/Concurrencia/reservar.php');
        $procesos = [];

        foreach ($argumentos as $i => $args) {
            $comando = array_merge([PHP_BINARY, $guion], array_map('strval', $args));
            $procesos[$i] = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias[$i]);
            $this->assertIsResource($procesos[$i], 'No se pudo lanzar el proceso '.($i + 1));
        }

        $resultados = [];
        foreach ($procesos as $i => $proceso) {
            $salida = stream_get_contents($tuberias[$i][1]);
            $errores = stream_get_contents($tuberias[$i][2]);
            fclose($tuberias[$i][1]);
            fclose($tuberias[$i][2]);
            proc_close($proceso);

            $json = json_decode(trim($salida), true);
            $this->assertIsArray($json, 'El proceso '.($i + 1)." no devolvió un resultado válido. Salida: {$salida} Errores: {$errores}");
            $resultados[] = $json;
        }

        return $resultados;
    }

    /** Un lunes futuro que no sea día sin atención. */
    private function proximoLunes(): string
    {
        $fecha = Carbon::today()->addDays(7);
        while ($fecha->dayOfWeekIso !== 1 || BloqueoAgenda::where('estado', true)->whereDate('fecha', $fecha->toDateString())->exists()) {
            $fecha->addDay();
        }

        return $fecha->toDateString();
    }
}
