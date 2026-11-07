<?php

namespace App\Modules\GestionCitas\CU22_EnviarRecordatoriosCitas;

use App\Models\Cita;
use App\Models\Paciente;
use App\Services\BitacoraService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Recordatorios de citas por correo: busca las citas PROGRAMADA o CONFIRMADA del día siguiente cuyo recordatorio aún no se envió,
 * verifica que el paciente tenga correo y las notificaciones activadas, envía UN aviso por paciente con todas sus citas de ese día
 * (código, médico, fecha, horario, modalidad y, si se paga en efectivo, el monto pendiente), marca cada cita con `recordatorio_enviado`
 * y lo registra en la bitácora. Si el envío falla, el error queda en el log (nunca se muestra en pantalla), las citas quedan sin marcar
 * para reintentarlas en la siguiente ejecución del comando y se sigue con los demás pacientes. Una cita reprogramada (CU-10) ya vuelve
 * a `recordatorio_enviado = false`, así que recibe el aviso con su nueva fecha y hora. Es idempotente: volver a ejecutarlo el mismo día
 * no repite los avisos ya enviados.
 *
 * @CU CU-22 @RF RF22 @CI CIRecordatorio (armarAviso, enviarCorreo) @CC CCRecordatorio (enviar, verificarNotificaciones) @CE CEPaciente (obtener), CECita (citasParaRecordar, marcarRecordatorio)
 */
class RecordatorioService
{
    public const OMITIDO_SIN_CORREO = 'el paciente no tiene correo';

    public const OMITIDO_SIN_NOTIFICACIONES = 'el paciente tiene las notificaciones desactivadas';

    public const OMITIDO_INACTIVO = 'el paciente está inactivo';

    /**
     * Citas que hay que recordar: Programadas o Confirmadas del día indicado (por defecto mañana) sin recordatorio enviado.
     *
     * @CE CECita (citasParaRecordar)
     *
     * @return Collection<int, Cita>
     */
    public function citasParaRecordar(Carbon $dia): Collection
    {
        return Cita::with(['paciente', 'medico', 'especialidad'])
            ->whereDate('fecha', $dia->toDateString())
            ->whereIn('estado', [Cita::PROGRAMADA, Cita::CONFIRMADA])
            ->where('recordatorio_enviado', false)
            ->orderBy('hora_inicio')->orderBy('id')
            ->get();
    }

    /**
     * ¿Se le puede avisar al paciente? Devuelve el motivo por el que se omite, o null si se puede enviar.
     *
     * @CC CCRecordatorio (verificarNotificaciones) @CE CEPaciente (obtener)
     */
    public function verificarNotificaciones(Paciente $paciente): ?string
    {
        if (! $paciente->estado) {
            return self::OMITIDO_INACTIVO;
        }
        if (! $paciente->recibe_recordatorios) {
            return self::OMITIDO_SIN_NOTIFICACIONES;
        }
        if (blank($paciente->correo)) {
            return self::OMITIDO_SIN_CORREO;
        }

        return null;
    }

    /**
     * Envía los recordatorios del día indicado (mañana si no se indica).
     *
     * @CC CCRecordatorio (enviar)
     *
     * @return array{dia: Carbon, avisos: list<array<string, mixed>>, enviados: int, citas_marcadas: int, citas_omitidas: int, fallidos: int}
     */
    public function enviar(?Carbon $dia = null): array
    {
        $dia ??= Carbon::tomorrow();
        $resultado = ['dia' => $dia, 'avisos' => [], 'enviados' => 0, 'citas_marcadas' => 0, 'citas_omitidas' => 0, 'fallidos' => 0];

        foreach ($this->citasParaRecordar($dia)->groupBy('paciente_id') as $citas) {
            /** @var Paciente $paciente */
            $paciente = $citas->first()->paciente;
            $codigos = $citas->pluck('codigo')->all();
            $aviso = ['paciente' => $paciente->codigo.' · '.$paciente->nombre_completo, 'citas' => $codigos];

            if ($motivo = $this->verificarNotificaciones($paciente)) {
                $resultado['avisos'][] = $aviso + ['resultado' => 'omitido', 'motivo' => $motivo];
                $resultado['citas_omitidas'] += count($codigos);

                continue;
            }

            try {
                $this->enviarCorreo($paciente, $citas, $dia);
            } catch (Throwable $e) {
                // Error técnico solo en el log: las citas quedan sin marcar y se reintentan en la siguiente ejecución.
                Log::error("CU-22: no se pudo enviar el recordatorio a {$paciente->codigo} (citas ".implode(', ', $codigos).'): '.get_class($e).': '.mb_substr($e->getMessage(), 0, 300));
                BitacoraService::registrar(
                    'RECORDATORIO_FALLIDO', 'Cita',
                    'No se pudo enviar el recordatorio a '.$paciente->codigo.' de las citas '.implode(', ', $codigos).'; se reintentará en la siguiente ejecución'
                );
                $resultado['avisos'][] = $aviso + ['resultado' => 'fallido', 'motivo' => 'no se pudo enviar el correo'];
                $resultado['fallidos']++;

                continue;
            }

            $this->marcarRecordatorio($citas, $paciente);
            $resultado['avisos'][] = $aviso + ['resultado' => 'enviado', 'motivo' => null];
            $resultado['enviados']++;
            $resultado['citas_marcadas'] += count($codigos);
        }

        return $resultado;
    }

    /**
     * Arma y envía el correo (un solo aviso con todas las citas del paciente para ese día).
     *
     * @CI CIRecordatorio (armarAviso, enviarCorreo)
     *
     * @param  Collection<int, Cita>  $citas
     */
    private function enviarCorreo(Paciente $paciente, Collection $citas, Carbon $dia): void
    {
        Mail::to($paciente->correo)->send(new RecordatorioCitaMail($paciente, $citas, $dia));
    }

    /**
     * Marca las citas con el recordatorio enviado y lo registra en la bitácora (sin el correo del paciente).
     *
     * @CE CECita (marcarRecordatorio)
     *
     * @param  Collection<int, Cita>  $citas
     */
    private function marcarRecordatorio(Collection $citas, Paciente $paciente): void
    {
        DB::transaction(function () use ($citas, $paciente) {
            Cita::whereIn('id', $citas->pluck('id'))->update(['recordatorio_enviado' => true]);
            BitacoraService::registrar(
                'RECORDATORIO_ENVIADO', 'Cita',
                'Recordatorio enviado a '.$paciente->codigo.' de '.($citas->count() === 1 ? 'la cita ' : 'las citas ').$citas->pluck('codigo')->implode(', ')
                .' del '.Carbon::parse($citas->first()->fecha)->format('d/m/Y')
            );
        });
    }
}
