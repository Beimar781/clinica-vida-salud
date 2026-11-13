<?php

namespace App\Modules\GestionCitas\CU22_EnviarRecordatoriosCitas;

use App\Models\Cita;
use App\Models\Paciente;
use App\Support\Dinero;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Correo de recordatorio (en español, HTML con estilos en línea y versión en texto): saluda al paciente y lista cada cita de ese día
 * con su código, médico, especialidad, fecha, horario, modalidad y, si se paga en efectivo y sigue pendiente, el monto a pagar.
 * No lleva datos clínicos (ni motivo de consulta ni diagnóstico).
 *
 * @CU CU-22 @RF RF22 @CI CIRecordatorio (armarAviso) @CE CECita, CEPaciente
 */
class RecordatorioCitaMail extends Mailable
{
    /**
     * @param  Collection<int, Cita>  $citas  las citas del paciente para ese día
     */
    public function __construct(
        public readonly Paciente $paciente,
        public readonly Collection $citas,
        public readonly Carbon $dia,
    ) {}

    public function build(): static
    {
        $una = $this->citas->count() === 1;

        return $this
            ->subject(($una ? 'Recordatorio de tu cita médica' : 'Recordatorio de tus citas médicas').' · Clínica Vida Salud')
            ->view(
                ['gestioncitas::CU22_EnviarRecordatoriosCitas.correo-recordatorio', 'gestioncitas::CU22_EnviarRecordatoriosCitas.correo-recordatorio-texto'],
                // Ojo: Laravel agrega al correo las propiedades públicas ($citas son modelos); por eso el detalle en arreglos se llama «lista».
                ['paciente' => $this->paciente, 'lista' => $this->detalle(), 'dia' => $this->dia, 'una' => $una,
                    // El aviso automático es del día siguiente; el envío manual (recepción) puede ser de hoy o de otra fecha.
                    'cuando' => $this->dia->isTomorrow() ? 'de mañana,' : ($this->dia->isToday() ? 'de hoy,' : 'del día')]
            );
    }

    /** @return list<array<string, string|null>> */
    private function detalle(): array
    {
        return $this->citas->map(fn (Cita $cita) => [
            'codigo' => $cita->codigo,
            'medico' => $cita->medico->nombre_completo,
            'especialidad' => $cita->especialidad->nombre,
            'fecha' => Carbon::parse($cita->fecha)->translatedFormat('l d/m/Y'),
            'horario' => substr($cita->hora_inicio, 0, 5).' a '.substr($cita->hora_fin, 0, 5),
            'modalidad' => $cita->modalidad === Cita::VIRTUAL
                ? 'Virtual (por videollamada)'
                : 'Presencial'.($cita->medico->ubicacion ? ' · '.$cita->medico->ubicacion : ''),
            // Solo si se paga en efectivo en la clínica y todavía no se cobró.
            'pendiente' => $cita->estado_pago === Cita::PAGO_PENDIENTE && $cita->metodo_pago === Cita::METODO_EFECTIVO && (float) $cita->monto > 0
                ? Dinero::formato($cita->monto)
                : null,
        ])->all();
    }
}
