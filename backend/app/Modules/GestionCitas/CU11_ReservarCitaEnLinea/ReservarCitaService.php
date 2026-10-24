<?php

namespace App\Modules\GestionCitas\CU11_ReservarCitaEnLinea;

use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Usuario;
use App\Modules\GestionCitas\Compartido\AgendarCitaService;
use App\Modules\GestionCitas\Compartido\CatalogoMedicosService;
use App\Modules\GestionCitas\Compartido\Disponibilidad;
use App\Modules\GestionCitas\Compartido\DisponibilidadService;
use App\Modules\GestionCitas\Compartido\HorarioOcupadoException;
use App\Modules\GestionCitas\Compartido\ReglaCitaException;
use App\Services\BitacoraService;
use Illuminate\Support\Collection;

/**
 * Reserva de citas por el propio paciente desde el sitio web: busca médicos activos por especialidad y ubicación
 * (con su próximo horario libre), muestra los horarios libres (CU-08) y registra la cita a nombre del paciente
 * autenticado, con la misma transacción, bloqueo del médico y captura de 23P01 de CU-09 (Compartido/AgendarCitaService).
 *
 * @CU CU-11 @RF RF11 @CI CIReservarCita @CC CCReservarCita @CE CECita (citasDelDia, crear, estaLibre), CEMedico (buscar, horariosLibres)
 */
class ReservarCitaService
{
    public const MENSAJE_SIN_FICHA = 'Su cuenta todavía no tiene una ficha de paciente. Comuníquese con la recepción de la clínica para registrarla.';

    public const MENSAJE_FICHA_INACTIVA = 'Su ficha de paciente está inactiva y no puede reservar citas. Comuníquese con la recepción de la clínica.';

    public const MENSAJE_SIN_MEDICOS = 'No hay médicos activos que coincidan con la especialidad y la ubicación elegidas. Cambie la búsqueda.';

    public function __construct(
        private readonly DisponibilidadService $disponibilidad,
        private readonly AgendarCitaService $agendar,
        private readonly CatalogoMedicosService $catalogo,
    ) {}

    /**
     * La ficha de paciente de la cuenta autenticada (la cita siempre queda a nombre de ella).
     *
     * @CE CEPaciente
     */
    public function pacienteDe(Usuario $usuario): ?Paciente
    {
        return Paciente::where('usuario_id', $usuario->id)->first();
    }

    /**
     * Por qué la cuenta no puede reservar (sin ficha o ficha inactiva), o null si puede.
     */
    public function impedimento(?Paciente $paciente): ?string
    {
        if (! $paciente) {
            return self::MENSAJE_SIN_FICHA;
        }

        return $paciente->estado ? null : self::MENSAJE_FICHA_INACTIVA;
    }

    /**
     * Médicos activos que coinciden con la especialidad y, si se indica, la ubicación; cada uno con su próximo horario libre.
     *
     * @CC CCReservarCita (buscar) @CE CEMedico (buscar, horariosLibres)
     *
     * @return Collection<int, array{medico: Medico, proximo: ?array{fecha: string, inicio: string}}>
     */
    public function buscarMedicos(?int $especialidadId, ?string $ubicacion): Collection
    {
        return $this->catalogo->medicosDe($especialidadId, $ubicacion)->map(fn (Medico $medico) => [
            'medico' => $medico,
            'proximo' => $this->disponibilidad->proximoHorarioLibre($medico),
        ]);
    }

    /**
     * Horarios libres del médico en la fecha (incluye CU-08).
     *
     * @CC CCReservarCita (calcularLibres) @CE CECita (citasDelDia), CEMedico (horariosLibres)
     */
    public function calcularLibres(Medico $medico, string $fecha): Disponibilidad
    {
        return $this->disponibilidad->calcularLibres($medico, $fecha);
    }

    /**
     * Reserva la cita a nombre del paciente de la cuenta: vuelve a verificar la disponibilidad dentro de una transacción.
     *
     * @CC CCReservarCita (reservar) @CE CECita (crear)
     *
     * @throws HorarioOcupadoException
     * @throws ReglaCitaException con el mensaje en primera persona para el paciente
     */
    public function reservar(Paciente $paciente, Especialidad $especialidad, Medico $medico, string $fecha, string $inicio, string $modalidad, ?string $motivo, Usuario $actor): Cita
    {
        try {
            $cita = $this->agendar->crear($paciente, $medico, $especialidad, $fecha, $inicio, $modalidad, $motivo, $actor->id);
        } catch (ReglaCitaException $e) {
            throw match ($e->getMessage()) {
                AgendarCitaService::MENSAJE_PACIENTE_OCUPADO => new ReglaCitaException('Ya tiene una cita activa a esa hora. Elija otro horario.'),
                AgendarCitaService::MENSAJE_PACIENTE_INACTIVO => new ReglaCitaException(self::MENSAJE_FICHA_INACTIVA),
                default => $e,
            };
        }

        BitacoraService::registrar(
            'CITA_RESERVADA', 'Cita',
            "Cita {$cita->codigo} reservada en línea por {$paciente->nombre_completo} con {$medico->nombre_completo} el ".$cita->fecha->format('d/m/Y').' '.$cita->hora_inicio_corta.' ('.$modalidad.')',
            $actor->id
        );

        return $cita;
    }
}
