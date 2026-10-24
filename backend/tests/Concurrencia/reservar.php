<?php

/*
 * Guion de apoyo de CU09_RegistrarCitaMedicaConcurrenciaTest (no es una prueba): lo ejecutan DOS procesos PHP a la vez.
 * Cada uno espera el mismo instante y luego intenta registrar la misma cita con AgendarCitaService, igual que dos
 * recepcionistas que pulsan «Confirmar» al mismo tiempo. Imprime una línea JSON con el resultado.
 *
 * Uso: php reservar.php <instante en segundos (microtime)> <medico_id> <paciente_id> <especialidad_id> <fecha> <hora>
 */

use App\Models\Especialidad;
use App\Models\Medico;
use App\Models\Paciente;
use App\Modules\GestionCitas\Compartido\AgendarCitaService;
use App\Modules\GestionCitas\Compartido\HorarioOcupadoException;
use App\Modules\GestionCitas\Compartido\ReglaCitaException;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $instante, $medicoId, $pacienteId, $especialidadId, $fecha, $hora] = $argv;

// Todo lo que hay que cargar se carga antes de la espera, para que la carrera sea solo el momento de confirmar.
$servicio = $app->make(AgendarCitaService::class);
$medico = Medico::findOrFail($medicoId);
$paciente = Paciente::findOrFail($pacienteId);
$especialidad = Especialidad::findOrFail($especialidadId);

while (microtime(true) < (float) $instante) {
    usleep(500);
}

try {
    $cita = $servicio->crear($paciente, $medico, $especialidad, $fecha, $hora, 'PRESENCIAL', null, null);
    echo json_encode(['resultado' => 'creada', 'codigo' => $cita->codigo]);
} catch (HorarioOcupadoException $e) {
    echo json_encode(['resultado' => 'ocupado', 'mensaje' => $e->getMessage()]);
} catch (ReglaCitaException $e) {
    echo json_encode(['resultado' => 'regla', 'mensaje' => $e->getMessage()]);
} catch (Throwable $e) {
    echo json_encode(['resultado' => 'error', 'mensaje' => get_class($e).': '.$e->getMessage()]);
}
