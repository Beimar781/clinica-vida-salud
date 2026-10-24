<?php

namespace App\Modules\GestionCitas\CU09_RegistrarCitaMedica;

use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Usuario;
use App\Modules\GestionCitas\Compartido\AgendarCitaService;
use App\Modules\GestionCitas\Compartido\Disponibilidad;
use App\Modules\GestionCitas\Compartido\DisponibilidadService;
use App\Modules\GestionCitas\Compartido\HorarioOcupadoException;
use App\Modules\GestionCitas\Compartido\ReglaCitaException;
use App\Services\BitacoraService;
use App\Support\Texto;
use Illuminate\Database\Eloquent\Collection;

/**
 * Registro de citas por la recepcionista: busca al paciente, ofrece los horarios libres (CU-08) y registra la cita
 * con su código CM-0001, modalidad, fecha de creación y estado PROGRAMADA. La transacción, el bloqueo del médico y la
 * captura de 23P01 están en Compartido/AgendarCitaService (los mismos que usan CU-10 y CU-11).
 *
 * @CU CU-09 @RF RF09 @CI CIRegistrarCita @CC CCRegistrarCita @CE CEPaciente (buscar), CECita (citasDelDia, crear, estaLibre), CEMedico (horariosLibres)
 */
class RegistrarCitaService
{
    public const MAX_RESULTADOS = 15;

    public function __construct(
        private readonly DisponibilidadService $disponibilidad,
        private readonly AgendarCitaService $agendar,
    ) {}

    /**
     * Busca pacientes por CI o nombre (sin distinguir mayúsculas ni tildes). Incluye a los inactivos para poder
     * avisar que no se les puede registrar una cita.
     *
     * @CC CCRegistrarCita (buscar) @CE CEPaciente (buscar)
     *
     * @return Collection<int, Paciente>
     */
    public function buscarPacientes(?string $buscar): Collection
    {
        $texto = Texto::normalizar((string) $buscar);
        if ($texto === '') {
            return new Collection;
        }

        $patron = Texto::patronLike($texto);
        $sinTildes = fn (string $columna) => Texto::sqlSinTildes($columna);

        return Paciente::where(fn ($q) => $q
            ->whereRaw($sinTildes('ci').' like ?', [$patron])
            ->orWhereRaw($sinTildes('nombres').' like ?', [$patron])
            ->orWhereRaw($sinTildes('apellidos').' like ?', [$patron])
            ->orWhereRaw($sinTildes("nombres || ' ' || apellidos").' like ?', [$patron]))
            ->orderByDesc('estado')->orderBy('apellidos')->orderBy('nombres')
            ->limit(self::MAX_RESULTADOS)->get();
    }

    /**
     * Horarios libres del médico en la fecha (incluye CU-08).
     *
     * @CC CCRegistrarCita (calcularLibres) @CE CEMedico (horariosLibres)
     */
    public function calcularLibres(Medico $medico, string $fecha): Disponibilidad
    {
        return $this->disponibilidad->calcularLibres($medico, $fecha);
    }

    /**
     * Valida las reglas, vuelve a verificar la disponibilidad dentro de una transacción y registra la cita.
     *
     * @CC CCRegistrarCita (registrar) @CE CECita (crear)
     *
     * @throws HorarioOcupadoException
     * @throws ReglaCitaException
     */
    public function registrar(Paciente $paciente, Especialidad $especialidad, Medico $medico, string $fecha, string $inicio, string $modalidad, ?string $motivo, Usuario $actor): Cita
    {
        $cita = $this->agendar->crear($paciente, $medico, $especialidad, $fecha, $inicio, $modalidad, $motivo, $actor->id);

        BitacoraService::registrar(
            'CITA_REGISTRADA', 'Cita',
            "Cita {$cita->codigo} registrada: {$paciente->nombre_completo} con {$medico->nombre_completo} el ".$cita->fecha->format('d/m/Y').' '.$cita->hora_inicio_corta.' ('.$modalidad.')',
            $actor->id
        );

        return $cita;
    }
}
