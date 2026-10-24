<?php

namespace App\Modules\GestionCitas\CU10_ReprogramarCitaMedica;

use App\Models\Cita;
use App\Models\Usuario;
use App\Modules\GestionCitas\Compartido\AgendarCitaService;
use App\Modules\GestionCitas\Compartido\Disponibilidad;
use App\Modules\GestionCitas\Compartido\DisponibilidadService;
use App\Modules\GestionCitas\Compartido\HorarioOcupadoException;
use App\Modules\GestionCitas\Compartido\ReglaCitaException;
use App\Services\BitacoraService;
use App\Support\Texto;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Reprogramación de citas: cambia la fecha y la hora de una cita PROGRAMADA o CONFIRMADA a otro horario libre del
 * mismo médico, conservando su código; la cita queda PROGRAMADA y el horario anterior queda libre. La transacción y
 * el control de superposiciones están en Compartido/AgendarCitaService (los mismos de CU-09 y CU-11).
 *
 * El motivo del cambio no tiene columna en la tabla citas: se guarda en la bitácora junto con el horario anterior.
 *
 * @CU CU-10 @RF RF10 @CI CIReprogramarCita @CC CCReprogramarCita @CE CECita (obtener, citasDelDia, reprogramar), CEMedico (horariosLibres)
 */
class ReprogramarCitaService
{
    public const POR_PAGINA = 10;

    public function __construct(
        private readonly DisponibilidadService $disponibilidad,
        private readonly AgendarCitaService $agendar,
    ) {}

    /**
     * Busca citas por código, CI o nombre del paciente (sin distinguir mayúsculas ni tildes). Sin texto lista las
     * citas vigentes (programadas o confirmadas) desde hoy, las más próximas primero.
     *
     * @CC CCReprogramarCita (buscar) @CE CECita (obtener)
     */
    public function buscar(?string $buscar): LengthAwarePaginator
    {
        $texto = Texto::normalizar((string) $buscar);
        $patron = Texto::patronLike($texto);
        $sinTildes = fn (string $columna) => Texto::sqlSinTildes($columna);

        $consulta = Cita::with(['paciente', 'medico', 'especialidad']);

        if ($texto === '') {
            $consulta->whereIn('estado', [Cita::PROGRAMADA, Cita::CONFIRMADA])->whereDate('fecha', '>=', now()->toDateString())
                ->orderBy('fecha')->orderBy('hora_inicio');
        } else {
            $consulta->where(fn ($q) => $q
                ->whereRaw($sinTildes('citas.codigo').' like ?', [$patron])
                ->orWhereHas('paciente', fn ($p) => $p
                    ->whereRaw($sinTildes('pacientes.ci').' like ?', [$patron])
                    ->orWhereRaw($sinTildes('pacientes.nombres').' like ?', [$patron])
                    ->orWhereRaw($sinTildes('pacientes.apellidos').' like ?', [$patron])
                    ->orWhereRaw($sinTildes("pacientes.nombres || ' ' || pacientes.apellidos").' like ?', [$patron])))
                ->orderByDesc('fecha')->orderByDesc('hora_inicio');
        }

        return $consulta->paginate(self::POR_PAGINA)->withQueryString();
    }

    /** Solo las citas PROGRAMADA o CONFIRMADA se pueden reprogramar. */
    public function puedeReprogramarse(Cita $cita): bool
    {
        return AgendarCitaService::sePuedeReprogramar($cita);
    }

    public function motivoNoReprogramable(Cita $cita): string
    {
        return AgendarCitaService::mensajeNoReprogramable($cita);
    }

    /**
     * Horarios libres del MISMO médico de la cita en la nueva fecha (incluye CU-08).
     *
     * @CC CCReprogramarCita (calcularLibres) @CE CECita (citasDelDia), CEMedico (horariosLibres)
     */
    public function calcularLibres(Cita $cita, string $fecha): Disponibilidad
    {
        return $this->disponibilidad->calcularLibres($cita->medico, $fecha);
    }

    /**
     * Vuelve a verificar la disponibilidad dentro de una transacción y cambia la fecha y la hora de la cita.
     *
     * @CC CCReprogramarCita (reprogramar) @CE CECita (reprogramar)
     *
     * @return array{cita: Cita, anterior: array{fecha: string, horario: string, estado: string}, motivo: string}
     *
     * @throws HorarioOcupadoException
     * @throws ReglaCitaException
     */
    public function reprogramar(Cita $cita, string $fecha, string $inicio, string $motivoCambio, Usuario $actor): array
    {
        $anterior = [
            'fecha' => $cita->fecha->format('d/m/Y'),
            'horario' => $cita->hora_inicio_corta.' – '.$cita->hora_fin_corta,
            'estado' => $cita->estado,
        ];

        $cita = $this->agendar->mover($cita, $fecha, $inicio);
        $motivoCambio = trim($motivoCambio);

        BitacoraService::registrar(
            'CITA_REPROGRAMADA', 'Cita',
            "Cita {$cita->codigo} reprogramada de {$anterior['fecha']} {$anterior['horario']} a ".$cita->fecha->format('d/m/Y').' '.$cita->hora_inicio_corta.' – '.$cita->hora_fin_corta.". Motivo: {$motivoCambio}",
            $actor->id
        );

        return ['cita' => $cita, 'anterior' => $anterior, 'motivo' => $motivoCambio];
    }
}
