<?php

namespace App\Modules\GestionCitas\CU22_EnviarRecordatoriosCitas;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schedule;
use Throwable;

/**
 * CU-22: Enviar recordatorios de citas (paquete P4 Gestión de Citas, RF22). Actor: el Temporizador (tarea programada del sistema).
 * No tiene pantallas: se ejecuta con `php artisan citas:enviar-recordatorios` y está programado una vez al día a las 18:00
 * (hora de La Paz) en routes/console.php; en Railway hace falta un servicio cron aparte que ejecute este mismo comando.
 *
 * Precondición: existen citas Programadas o Confirmadas para el día siguiente y el paciente tiene correo y notificaciones activadas.
 *
 * Flujo de suceso (ficha 4.3 del documento):
 *  1. La tarea programada se ejecuta una vez al día, a la hora definida.            -> handle() (Schedule en routes/console.php)
 *  2. El sistema consulta las citas Programadas o Confirmadas del día siguiente
 *     cuyo recordatorio aún no fue enviado.                                          -> RecordatorioService::citasParaRecordar()
 *  3. Para cada cita, verifica que el paciente tenga correo y notificaciones.        -> RecordatorioService::verificarNotificaciones()
 *  4. Envía el recordatorio con el código de la cita, el médico, la fecha, el
 *     horario y la modalidad (un aviso por paciente con todas sus citas del día).    -> RecordatorioCitaMail y RecordatorioService::enviar()
 *  5. Marca el recordatorio como enviado y lo registra en la bitácora.              -> RecordatorioService::marcarRecordatorio()
 *
 * Excepciones:
 *  - Paciente con notificaciones desactivadas o sin correo (o inactivo): se omite el recordatorio.
 *  - El envío falla: el error queda en el log (sin detalles técnicos en pantalla), la cita queda sin marcar para reintentarla en la
 *    siguiente ejecución y el comando sigue con los demás pacientes.
 *  - Cita reprogramada (CU-10): ya vuelve a `recordatorio_enviado = false` y recibe el recordatorio con la nueva fecha y hora.
 *
 * Opción extra `--fecha=AAAA-MM-DD` (para probar o reenviar un día concreto); sin ella se usa mañana.
 *
 * @CU CU-22 @RF RF22 @CI CIRecordatorio @CC CCRecordatorio @CE CECita, CEPaciente
 */
class EnviarRecordatoriosCommand extends Command
{
    protected $signature = 'citas:enviar-recordatorios {--fecha= : Día de las citas a recordar (AAAA-MM-DD); por defecto, mañana}';

    protected $description = 'Envía por correo el recordatorio de las citas Programadas o Confirmadas del día siguiente';

    public function handle(RecordatorioService $recordatorios): int
    {
        $dia = null;
        if ($fecha = $this->option('fecha')) {
            // Carbon lanza una excepción con formatos como «06/03/2035» o fechas imposibles: se toma como fecha no válida.
            try {
                $dia = Carbon::createFromFormat('!Y-m-d', (string) $fecha);
            } catch (Throwable) {
                $dia = null;
            }
            if (! $dia || $dia->format('Y-m-d') !== $fecha) {
                $this->error('La fecha no es válida: use el formato AAAA-MM-DD (por ejemplo 2026-11-10).');

                return self::FAILURE;
            }
        }

        $resultado = $recordatorios->enviar($dia);
        $texto = $resultado['dia']->format('d/m/Y');

        if ($resultado['avisos'] === []) {
            $this->info("Recordatorios para el {$texto}: no hay citas Programadas o Confirmadas pendientes de aviso.");

            return self::SUCCESS;
        }

        foreach ($resultado['avisos'] as $aviso) {
            $linea = match ($aviso['resultado']) {
                'enviado' => '  [OK] Enviado a ',
                'omitido' => '  [--] Omitido ('.$aviso['motivo'].'): ',
                default => '  [!!] Falló el envío a ',
            };
            $this->line($linea.$aviso['paciente'].' · '.implode(', ', $aviso['citas']));
        }
        $this->info(
            "Recordatorios para el {$texto}: {$resultado['enviados']} avisos enviados ({$resultado['citas_marcadas']} citas marcadas), "
            ."{$resultado['citas_omitidas']} citas omitidas y {$resultado['fallidos']} avisos fallidos."
        );

        // Un fallo no hace fallar el comando: las citas siguen sin marcar y se reintentan en la siguiente ejecución.
        return self::SUCCESS;
    }
}
