<?php

namespace App\Modules\GestionCitas\CU22_EnviarRecordatoriosCitas;

use App\Http\Controllers\Controller;
use App\Models\Cita;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * CU-22 (extendido, extra aprobado por el equipo): «Enviar recordatorio ahora». La recepción (y el administrador, que hereda recepción) envía
 * en el momento el recordatorio de UNA cita a su paciente, para mostrarlo en vivo. No cambia el comando programado de las 18:00.
 *
 * Flujo:
 *  1. La recepción pulsa «Enviar recordatorio» en la fila de una cita Programada o Confirmada que todavía no empezó.  -> enviar() (POST /citas/{cita}/recordatorio)
 *  2. El sistema verifica la cita y que el paciente esté activo, tenga correo y los recordatorios activados.        -> RecordatorioService::enviarACita()
 *  3. Envía el mismo correo del recordatorio automático, marca la cita (el comando de las 18:00 no repite el aviso)
 *     y lo registra en la bitácora (RECORDATORIO_MANUAL).                                                          -> RecordatorioService::enviarACita()
 *
 * Excepciones: cita cancelada, atendida, con inasistencia o ya pasada; paciente sin correo, inactivo o con los recordatorios desactivados (se dice el
 * motivo y no se envía); recordatorio ya enviado antes (se pide confirmar el reenvío); falla del correo (mensaje genérico, error al log y
 * RECORDATORIO_FALLIDO en la bitácora); más de 10 envíos por minuto (429).
 *
 * @CU CU-22 @RF RF22 @CI CIRecordatorio @CC CCRecordatorio (enviar) @CE CECita, CEPaciente
 */
class EnviarRecordatorioController extends Controller
{
    public function __construct(private readonly RecordatorioService $recordatorios) {}

    public function enviar(Request $request, Cita $cita): RedirectResponse
    {
        $resultado = $this->recordatorios->enviarACita($cita, $request->user(), $request->boolean('reenviar'));

        return back()->with($resultado['ok'] ? 'exito' : 'error', $resultado['mensaje']);
    }
}
