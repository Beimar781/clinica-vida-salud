<?php

namespace App\Modules\GestionMedica\CU06_GestionarHorariosDiasSinAtencion;

use App\Models\HorarioAtencion;
use App\Models\Medico;
use App\Models\Usuario;
use App\Services\BitacoraService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * Bloques de atención de cada médico por día de la semana (1 = lunes ... 7 = domingo). Las reglas que
 * valida el documento: la hora final es posterior a la inicial, la duración de la cita divide exactamente
 * el bloque (si no, se avisa cuántos minutos sobran) y un médico no tiene bloques superpuestos el mismo día
 * (se valida aquí y, como última barrera, con la restricción EXCLUDE de PostgreSQL).
 *
 * @CU CU-06 @RF RF06 @CI CIHorarios @CC CCHorarios @CE CEHorario, CEMedico
 */
class HorarioService
{
    public const DIAS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

    /**
     * Médicos activos entre los que se elige (precondición: debe haber al menos uno).
     *
     * @CC CCHorarios (crear) @CE CEMedico (obtener)
     *
     * @return Collection<int, Medico>
     */
    public function medicosActivos(): Collection
    {
        return Medico::with('especialidades')->where('estado', true)->orderBy('apellidos')->orderBy('nombres')->get();
    }

    /**
     * Agenda semanal del médico: para cada día (1 a 7) sus bloques ordenados por hora.
     *
     * @CC CCHorarios (mostrarAgenda) @CE CEMedico (obtener)
     *
     * @return array<int, Collection<int, HorarioAtencion>>
     */
    public function agendaSemanal(Medico $medico): array
    {
        $bloques = HorarioAtencion::where('medico_id', $medico->id)->orderBy('hora_inicio')->get()->groupBy('dia_semana');

        $agenda = [];
        foreach (array_keys(self::DIAS) as $dia) {
            $agenda[$dia] = $bloques->get($dia, new Collection);
        }

        return $agenda;
    }

    /** Minutos del día para «HH:MM» o «HH:MM:SS». */
    public static function minutos(string $hora): int
    {
        [$h, $m] = array_map('intval', explode(':', $hora));

        return $h * 60 + $m;
    }

    /** «HH:MM» (sin segundos) para mostrar o llenar un campo de hora. */
    public static function hhmm(string $hora): string
    {
        return substr($hora, 0, 5);
    }

    /**
     * La hora final debe ser posterior a la inicial.
     *
     * @CC CCHorarios (validarHoras)
     */
    public function validarHoras(string $inicio, string $fin): bool
    {
        return self::minutos($fin) > self::minutos($inicio);
    }

    /**
     * Minutos que sobran al dividir el bloque en citas de la duración indicada (0 = la divide exactamente).
     */
    public function minutosSobrantes(string $inicio, string $fin, int $duracion): int
    {
        return (self::minutos($fin) - self::minutos($inicio)) % $duracion;
    }

    /**
     * Cantidad de citas que caben en el bloque (por ejemplo 08:00 a 12:00 con 30 minutos = 8 citas).
     *
     * @CC CCHorarios (guardar, paso 5: «calcula la cantidad de citas del bloque»)
     */
    public function cantidadCitas(string $inicio, string $fin, int $duracion): int
    {
        return intdiv(self::minutos($fin) - self::minutos($inicio), $duracion);
    }

    /**
     * Devuelve el bloque ACTIVO del mismo médico y día con el que se superpondría (null si no hay).
     * Dos bloques contiguos (uno termina cuando empieza el otro) no se superponen.
     *
     * @CE CEHorario (haySuperposicion)
     */
    public function bloqueEnConflicto(int $medicoId, int $dia, string $inicio, string $fin, ?int $ignorarId = null): ?HorarioAtencion
    {
        $nuevoInicio = self::minutos($inicio);
        $nuevoFin = self::minutos($fin);

        return HorarioAtencion::where('medico_id', $medicoId)->where('dia_semana', $dia)->where('estado', true)
            ->when($ignorarId, fn ($q) => $q->where('id', '!=', $ignorarId))
            ->get()
            ->first(fn (HorarioAtencion $b) => $nuevoInicio < self::minutos($b->hora_fin) && $nuevoFin > self::minutos($b->hora_inicio));
    }

    /** @CE CEHorario (haySuperposicion) */
    public function haySuperposicion(int $medicoId, int $dia, string $inicio, string $fin, ?int $ignorarId = null): bool
    {
        return $this->bloqueEnConflicto($medicoId, $dia, $inicio, $fin, $ignorarId) !== null;
    }

    /** Texto de aviso cuando el bloque se superpone con otro. */
    public static function mensajeSuperposicion(HorarioAtencion $otro): string
    {
        return 'El bloque se superpone con otro horario del médico el '.mb_strtolower(self::DIAS[$otro->dia_semana])
            .' de '.self::hhmm($otro->hora_inicio).' a '.self::hhmm($otro->hora_fin).'.';
    }

    /** Texto de aviso cuando la duración no divide exactamente el bloque. */
    public static function mensajeDuracion(int $sobrantes): string
    {
        return "La duración no divide exactamente el bloque: sobran {$sobrantes} minutos. Corrija la duración o las horas.";
    }

    /**
     * Registra un bloque de atención para el médico.
     *
     * @CC CCHorarios (guardar) @CE CEHorario (crear)
     *
     * @param  array{dia_semana: int, hora_inicio: string, hora_fin: string, duracion_min: int}  $datos
     */
    public function guardar(Medico $medico, array $datos, Usuario $actor): HorarioAtencion
    {
        try {
            $horario = HorarioAtencion::create($this->campos($datos) + ['medico_id' => $medico->id, 'estado' => true]);
        } catch (QueryException $e) {
            throw $this->superposicionDeLaBase($e);
        }

        BitacoraService::registrar('HORARIO_CREADO', 'Horario', 'Horario de '.$this->descripcion($medico, $horario), $actor->id);

        return $horario;
    }

    /**
     * @CC CCHorarios (actualizar) @CE CEHorario (actualizar)
     *
     * @param  array{dia_semana: int, hora_inicio: string, hora_fin: string, duracion_min: int}  $datos
     */
    public function actualizar(HorarioAtencion $horario, array $datos, Usuario $actor): HorarioAtencion
    {
        try {
            $horario->update($this->campos($datos));
        } catch (QueryException $e) {
            throw $this->superposicionDeLaBase($e);
        }

        BitacoraService::registrar('HORARIO_EDITADO', 'Horario', 'Horario de '.$this->descripcion($horario->medico, $horario), $actor->id);

        return $horario;
    }

    /**
     * Desactiva el bloque: deja de ofrecerse en la disponibilidad (el registro se conserva).
     *
     * @CC CCHorarios (desactivar) @CE CEHorario (desactivar)
     */
    public function desactivar(HorarioAtencion $horario, Usuario $actor): void
    {
        $horario->update(['estado' => false]);
        BitacoraService::registrar('HORARIO_DESACTIVADO', 'Horario', 'Horario desactivado de '.$this->descripcion($horario->medico, $horario), $actor->id);
    }

    /**
     * Reactiva un bloque desactivado, siempre que no se superponga con otro activo.
     *
     * @CC CCHorarios (actualizar) @CE CEHorario (haySuperposicion, actualizar)
     *
     * @throws ValidationException
     */
    public function activar(HorarioAtencion $horario, Usuario $actor): void
    {
        $hora = fn (string $h) => self::hhmm($h);
        if ($otro = $this->bloqueEnConflicto($horario->medico_id, $horario->dia_semana, $hora($horario->hora_inicio), $hora($horario->hora_fin), $horario->id)) {
            throw ValidationException::withMessages(['horario' => self::mensajeSuperposicion($otro)]);
        }

        $horario->update(['estado' => true]);
        BitacoraService::registrar('HORARIO_ACTIVADO', 'Horario', 'Horario activado de '.$this->descripcion($horario->medico, $horario), $actor->id);
    }

    private function campos(array $datos): array
    {
        return [
            'dia_semana' => (int) $datos['dia_semana'],
            'hora_inicio' => $datos['hora_inicio'],
            'hora_fin' => $datos['hora_fin'],
            'duracion_min' => (int) $datos['duracion_min'],
        ];
    }

    private function descripcion(Medico $medico, HorarioAtencion $h): string
    {
        return "{$medico->codigo} {$medico->nombre_completo}: ".self::DIAS[$h->dia_semana].' '.self::hhmm($h->hora_inicio).'-'.self::hhmm($h->hora_fin)." ({$h->duracion_min} min)";
    }

    /** Dos usuarios guardando bloques que chocan a la vez: la EXCLUDE de PostgreSQL (23P01) es la última barrera. */
    private function superposicionDeLaBase(QueryException $e): \Throwable
    {
        if ($e->getCode() === '23P01') {
            return ValidationException::withMessages(['hora_inicio' => 'El bloque se superpone con otro horario del médico en ese día.']);
        }

        return $e;
    }
}
