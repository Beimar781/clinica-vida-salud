<?php

namespace App\Modules\GestionCitas\Compartido;

use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Medico;
use App\Models\Paciente;
use App\Support\Codigos;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Núcleo transaccional de las citas, compartido por CU-09 (registrar), CU-10 (reprogramar) y CU-11 (reservar en
 * línea) para no duplicar la lógica más delicada del sistema. Tres capas contra las citas superpuestas:
 *  1. el cálculo de disponibilidad (DisponibilidadService), que se VUELVE a hacer dentro de la transacción;
 *  2. el bloqueo de la fila del médico (lockForUpdate): dos personas que confirman a la vez se atienden una detrás de otra;
 *  3. las dos restricciones EXCLUDE de PostgreSQL (médico y paciente) como última barrera: si algo se escapa, la base
 *     de datos rechaza la segunda con SQLSTATE 23P01, que se captura FUERA de la transacción y se traduce a
 *     «El horario fue ocupado por otro usuario».
 *
 * @CU CU-09, CU-10, CU-11 @RF RF09, RF10, RF11 @CC CCRegistrarCita (validarReglas, registrar), CCReprogramarCita (reprogramar), CCReservarCita (reservar) @CE CECita (estaLibre, crear, reprogramar), CEPaciente, CEMedico
 */
class AgendarCitaService
{
    public function __construct(private readonly DisponibilidadService $disponibilidad) {}

    /**
     * Reglas del negocio sobre quién y con quién: paciente activo, médico activo, especialidad activa y que el
     * médico la ejerza. Se llama con datos recién leídos de la base.
     *
     * @CC CCRegistrarCita (validarReglas) @CE CEPaciente, CEMedico
     *
     * @throws ReglaCitaException
     */
    public function validarReglas(Paciente $paciente, Medico $medico, Especialidad $especialidad): void
    {
        if (! $paciente->estado) {
            throw new ReglaCitaException('El paciente está inactivo: no se le pueden registrar citas.');
        }
        if (! $medico->estado) {
            throw new ReglaCitaException(DisponibilidadService::MENSAJE_MEDICO_INACTIVO);
        }
        if (! $especialidad->estado) {
            throw new ReglaCitaException('La especialidad está inactiva: no se ofrece para nuevas citas.');
        }
        if (! $medico->especialidades()->whereKey($especialidad->id)->exists()) {
            throw new ReglaCitaException('El médico no atiende la especialidad elegida.');
        }
    }

    /**
     * Crea la cita en estado PROGRAMADA con su código CM-0001 (secuencia citas_codigo_seq).
     *
     * @CC CCRegistrarCita (registrar), CCReservarCita (reservar) @CE CECita (estaLibre, crear)
     *
     * @param  string  $fecha  «YYYY-MM-DD»
     * @param  string  $inicio  «HH:MM» (debe ser el inicio de un bloque libre)
     *
     * @throws HorarioOcupadoException si el horario ya no está libre
     * @throws ReglaCitaException si una regla impide la cita
     */
    public function crear(Paciente $paciente, Medico $medico, Especialidad $especialidad, string $fecha, string $inicio, string $modalidad, ?string $motivo, ?int $creadoPor): Cita
    {
        try {
            return DB::transaction(function () use ($paciente, $medico, $especialidad, $fecha, $inicio, $modalidad, $motivo, $creadoPor) {
                $medicoBloqueado = Medico::whereKey($medico->id)->lockForUpdate()->firstOrFail();
                $paciente = $paciente->fresh();
                $especialidad = $especialidad->fresh();

                $this->validarReglas($paciente, $medicoBloqueado, $especialidad);
                $bloque = $this->verificarBloque($medicoBloqueado, $fecha, $inicio, null);
                $this->verificarPaciente($paciente, $fecha, $bloque, null);

                return Cita::create([
                    'codigo' => Codigos::cita(),
                    'paciente_id' => $paciente->id,
                    'medico_id' => $medicoBloqueado->id,
                    'especialidad_id' => $especialidad->id,
                    'fecha' => $fecha,
                    'hora_inicio' => $bloque['inicio'],
                    'hora_fin' => $bloque['fin'],
                    'modalidad' => $modalidad,
                    'motivo' => filled($motivo) ? trim($motivo) : null,
                    'estado' => Cita::PROGRAMADA,
                    'creado_por' => $creadoPor,
                ]);
            });
        } catch (QueryException $e) {
            // Fuera de la transacción: dentro de ella PostgreSQL ya marcó la transacción como abortada.
            throw $this->traducir($e) ?? $e;
        }
    }

    /**
     * Cambia la fecha y hora de una cita conservando su código: solo desde PROGRAMADA o CONFIRMADA; queda en
     * PROGRAMADA y el horario anterior queda libre.
     *
     * @CC CCReprogramarCita (reprogramar) @CE CECita (obtener, reprogramar)
     *
     * @throws HorarioOcupadoException
     * @throws ReglaCitaException
     */
    public function mover(Cita $cita, string $fecha, string $inicio): Cita
    {
        try {
            return DB::transaction(function () use ($cita, $fecha, $inicio) {
                // Siempre en el mismo orden (primero el médico, luego la cita) para no provocar bloqueos cruzados.
                $medico = Medico::whereKey($cita->medico_id)->lockForUpdate()->firstOrFail();
                $actual = Cita::whereKey($cita->id)->lockForUpdate()->firstOrFail();

                if (! self::sePuedeReprogramar($actual)) {
                    throw new ReglaCitaException(self::mensajeNoReprogramable($actual));
                }
                if (! $medico->estado) {
                    throw new ReglaCitaException(DisponibilidadService::MENSAJE_MEDICO_INACTIVO);
                }

                $bloque = $this->verificarBloque($medico, $fecha, $inicio, $actual->id);
                if ($actual->fecha->toDateString() === $fecha && substr($actual->hora_inicio, 0, 5) === $bloque['inicio']) {
                    throw new ReglaCitaException('El nuevo horario es el mismo que ya tiene la cita. Elija otro.');
                }
                $this->verificarPaciente($actual->paciente, $fecha, $bloque, $actual->id);

                $actual->update([
                    'fecha' => $fecha,
                    'hora_inicio' => $bloque['inicio'],
                    'hora_fin' => $bloque['fin'],
                    'estado' => Cita::PROGRAMADA,
                ]);

                return $actual;
            });
        } catch (QueryException $e) {
            throw $this->traducir($e) ?? $e;
        }
    }

    /** Solo las citas PROGRAMADA o CONFIRMADA se pueden reprogramar. */
    public static function sePuedeReprogramar(Cita $cita): bool
    {
        return in_array($cita->estado, [Cita::PROGRAMADA, Cita::CONFIRMADA], true);
    }

    public static function mensajeNoReprogramable(Cita $cita): string
    {
        $estado = ['ATENDIDA' => 'atendida', 'CANCELADA' => 'cancelada', 'INASISTENCIA' => 'con inasistencia'][$cita->estado] ?? mb_strtolower($cita->estado);

        return "La cita está {$estado}: no se puede reprogramar. Registre una cita nueva.";
    }

    /**
     * Comprueba que el bloque que empieza a esa hora esté libre ese día; si no, explica por qué.
     *
     * @return array{inicio: string, fin: string}
     *
     * @throws ReglaCitaException fecha pasada, feriado, médico que no atiende, hora fuera de su horario o que ya pasó
     * @throws HorarioOcupadoException el bloque existe pero ya tiene una cita
     */
    private function verificarBloque(Medico $medico, string $fecha, string $inicio, ?int $ignorarCitaId): array
    {
        $resultado = $this->disponibilidad->calcularLibres($medico, $fecha, $ignorarCitaId);

        if (in_array($resultado->motivo, [Disponibilidad::FECHA_PASADA, Disponibilidad::DIA_SIN_ATENCION, Disponibilidad::MEDICO_INACTIVO, Disponibilidad::NO_ATIENDE], true)) {
            throw new ReglaCitaException($resultado->mensaje);
        }

        if ($libre = $resultado->libre($inicio)) {
            return $libre;
        }

        if ($resultado->bloque($inicio) === null) {
            throw new ReglaCitaException('La hora elegida está fuera del horario de atención del médico.');
        }

        $hoy = DisponibilidadService::dia($fecha)->isSameDay(Carbon::now());
        if ($hoy && DisponibilidadService::minutos($inicio) <= Carbon::now()->hour * 60 + Carbon::now()->minute) {
            throw new ReglaCitaException('Ese horario ya pasó. Elija uno de los horarios libres.');
        }

        throw new HorarioOcupadoException;
    }

    /**
     * El paciente no puede tener dos citas activas a la misma hora (aunque sean con médicos distintos).
     *
     * @param  array{inicio: string, fin: string}  $bloque
     *
     * @throws ReglaCitaException
     */
    private function verificarPaciente(Paciente $paciente, string $fecha, array $bloque, ?int $ignorarCitaId): void
    {
        $choca = Cita::where('paciente_id', $paciente->id)
            ->whereDate('fecha', $fecha)
            ->where('estado', '<>', Cita::CANCELADA)
            ->where('hora_inicio', '<', $bloque['fin'])
            ->where('hora_fin', '>', $bloque['inicio'])
            ->when($ignorarCitaId, fn ($q) => $q->where('id', '!=', $ignorarCitaId))
            ->exists();

        if ($choca) {
            throw new ReglaCitaException('El paciente ya tiene una cita activa a esa hora.');
        }
    }

    /** SQLSTATE 23P01 (exclusion_violation): las restricciones EXCLUDE de la tabla citas rechazaron la superposición. */
    private function traducir(QueryException $e): ?\Throwable
    {
        if ($e->getCode() !== '23P01') {
            return null;
        }

        return str_contains($e->getMessage(), 'citas_paciente')
            ? new ReglaCitaException('El paciente ya tiene una cita activa a esa hora.')
            : new HorarioOcupadoException;
    }
}
