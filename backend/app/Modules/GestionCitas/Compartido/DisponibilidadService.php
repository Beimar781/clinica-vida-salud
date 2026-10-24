<?php

namespace App\Modules\GestionCitas\Compartido;

use App\Models\BloqueoAgenda;
use App\Models\Cita;
use App\Models\HorarioAtencion;
use App\Models\Medico;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Cálculo de los horarios libres de un médico en una fecha. Se implementa UNA sola vez aquí y lo reutilizan
 * CU-08 (consultar), CU-09 (registrar), CU-10 (reprogramar) y CU-11 (reservar en línea).
 *
 * Reglas: se rechaza la fecha pasada, el feriado o día sin atención y el día en que el médico no tiene horario;
 * cada bloque activo se divide según su duración; se descartan los bloques que chocan con una cita no cancelada
 * y, si la fecha es hoy, los que ya pasaron. Solo lee datos: nunca modifica nada.
 *
 * @CU CU-08 @RF RF08 @CI CIDisponibilidad @CC CCDisponibilidad @CE CEMedico (horariosLibres), CECita (citasDelDia)
 */
class DisponibilidadService
{
    public const MENSAJE_NO_ATIENDE = 'El médico no atiende en esa fecha';

    public const MENSAJE_SIN_HORARIOS = 'No hay horarios disponibles';

    public const MENSAJE_FECHA_PASADA = 'La fecha elegida ya pasó: elija hoy o una fecha futura.';

    public const MENSAJE_MEDICO_INACTIVO = 'El médico no está activo y no recibe citas.';

    /** «YYYY-MM-DD» o fecha ya parseada → día a las 00:00 en la zona horaria del sistema. */
    public static function dia(Carbon|string $fecha): Carbon
    {
        return $fecha instanceof Carbon
            ? $fecha->copy()->startOfDay()
            : Carbon::createFromFormat('Y-m-d', $fecha)->startOfDay();
    }

    /** Minutos desde las 00:00 para «HH:MM» o «HH:MM:SS». */
    public static function minutos(string $hora): int
    {
        [$h, $m] = array_map('intval', explode(':', $hora));

        return $h * 60 + $m;
    }

    /** «HH:MM» para minutos desde las 00:00. */
    public static function hora(int $minutos): string
    {
        return sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);
    }

    /**
     * ¿Se puede ofrecer algún horario ese día? Devuelve null si el día es válido o [motivo, mensaje] si no:
     * fecha pasada, feriado o día sin atención, médico inactivo o día en que el médico no atiende.
     *
     * @CC CCDisponibilidad (verificarDia)
     *
     * @return ?array{0: string, 1: string}
     */
    public function verificarDia(Medico $medico, Carbon|string $fecha): ?array
    {
        $dia = self::dia($fecha);

        if ($dia->lt(Carbon::today())) {
            return [Disponibilidad::FECHA_PASADA, self::MENSAJE_FECHA_PASADA];
        }

        $bloqueo = BloqueoAgenda::where('estado', true)->whereDate('fecha', $dia->toDateString())->first();
        if ($bloqueo) {
            return [Disponibilidad::DIA_SIN_ATENCION, 'No hay atención el '.$dia->format('d/m/Y').' (feriado o día sin atención): '.$bloqueo->motivo.'.'];
        }

        if (! $medico->estado) {
            return [Disponibilidad::MEDICO_INACTIVO, self::MENSAJE_MEDICO_INACTIVO];
        }

        $atiende = HorarioAtencion::where('medico_id', $medico->id)->where('estado', true)->where('dia_semana', $dia->dayOfWeekIso)->exists();
        if (! $atiende) {
            return [Disponibilidad::NO_ATIENDE, self::MENSAJE_NO_ATIENDE];
        }

        return null;
    }

    /**
     * Citas no canceladas del médico en esa fecha (las que ocupan horario).
     *
     * @CE CECita (citasDelDia)
     *
     * @return Collection<int, Cita>
     */
    public function citasDelDia(Medico $medico, Carbon|string $fecha, ?int $ignorarCitaId = null): Collection
    {
        return Cita::where('medico_id', $medico->id)
            ->whereDate('fecha', self::dia($fecha)->toDateString())
            ->where('estado', '<>', Cita::CANCELADA)
            ->when($ignorarCitaId, fn ($q) => $q->where('id', '!=', $ignorarCitaId))
            ->orderBy('hora_inicio')
            ->get();
    }

    /**
     * Horarios libres del médico en la fecha. Pasos del flujo de CU-08: verificar el día, tomar los horarios activos
     * del día de la semana, dividirlos en bloques según su duración, descartar los bloques con cita no cancelada y,
     * si la fecha es hoy, los que ya pasaron.
     *
     * @CC CCDisponibilidad (calcularLibres) @CE CEMedico (horariosLibres), CECita (citasDelDia)
     *
     * @param  ?int  $ignorarCitaId  una cita que se está moviendo (CU-10): su propio horario no cuenta como ocupado
     * @param  ?Carbon  $ahora  momento de referencia (por defecto, ahora)
     */
    public function calcularLibres(Medico $medico, Carbon|string $fecha, ?int $ignorarCitaId = null, ?Carbon $ahora = null): Disponibilidad
    {
        $dia = self::dia($fecha);

        if ($problema = $this->verificarDia($medico, $dia)) {
            return new Disponibilidad([], [], $problema[1], $problema[0]);
        }

        $todos = $this->bloquesDelDia($medico, $dia);
        $ocupadas = $this->citasDelDia($medico, $dia, $ignorarCitaId)
            ->map(fn (Cita $c) => [self::minutos($c->hora_inicio), self::minutos($c->hora_fin)])->all();

        $ahora ??= Carbon::now();
        $minutosAhora = $dia->isSameDay($ahora) ? $ahora->hour * 60 + $ahora->minute : null;

        $libres = array_values(array_filter($todos, function (array $bloque) use ($ocupadas, $minutosAhora) {
            $inicio = self::minutos($bloque['inicio']);
            $fin = self::minutos($bloque['fin']);

            if ($minutosAhora !== null && $inicio <= $minutosAhora) {
                return false; // hoy: el bloque ya empezó o pasó
            }

            foreach ($ocupadas as [$citaInicio, $citaFin]) {
                if ($inicio < $citaFin && $fin > $citaInicio) {
                    return false; // choca con una cita no cancelada
                }
            }

            return true;
        }));

        return $libres === []
            ? new Disponibilidad([], $todos, self::MENSAJE_SIN_HORARIOS, Disponibilidad::SIN_HORARIOS)
            : new Disponibilidad($libres, $todos, null, null);
    }

    /**
     * Primer horario libre en los próximos días (para decirle al paciente cuándo puede reservar).
     *
     * @return ?array{fecha: string, inicio: string}
     */
    public function proximoHorarioLibre(Medico $medico, int $dias = 14): ?array
    {
        for ($i = 0; $i < $dias; $i++) {
            $dia = Carbon::today()->addDays($i);
            $resultado = $this->calcularLibres($medico, $dia);

            if ($resultado->hayLibres()) {
                return ['fecha' => $dia->toDateString(), 'inicio' => $resultado->libres[0]['inicio']];
            }
        }

        return null;
    }

    /**
     * Divide cada horario activo del día de la semana en bloques de su duración (08:00 a 12:00 con 30 min = 8 bloques).
     *
     * @return list<array{inicio: string, fin: string}>
     */
    private function bloquesDelDia(Medico $medico, Carbon $dia): array
    {
        $bloques = [];

        $horarios = HorarioAtencion::where('medico_id', $medico->id)->where('estado', true)
            ->where('dia_semana', $dia->dayOfWeekIso)->orderBy('hora_inicio')->get();

        foreach ($horarios as $horario) {
            $fin = self::minutos($horario->hora_fin);
            for ($inicio = self::minutos($horario->hora_inicio); $inicio + $horario->duracion_min <= $fin; $inicio += $horario->duracion_min) {
                $bloques[] = ['inicio' => self::hora($inicio), 'fin' => self::hora($inicio + $horario->duracion_min)];
            }
        }

        return $bloques;
    }
}
