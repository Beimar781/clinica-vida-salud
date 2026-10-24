<?php

namespace App\Modules\GestionMedica\CU06_GestionarHorariosDiasSinAtencion;

use App\Models\BloqueoAgenda;
use App\Models\Cita;
use App\Models\Usuario;
use App\Services\BitacoraService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Feriados y días sin atención de la clínica (tabla bloqueos_agenda): en esas fechas no se ofrecen horarios.
 * Al registrar uno se listan las citas ya programadas que quedan afectadas para que se reprogramen.
 *
 * @CU CU-06 @RF RF06 @CI CIHorarios @CC CCHorarios @CE CEHorario, CEMedico
 */
class DiaSinAtencionService
{
    public const POR_PAGINA = 15;

    public const MENSAJE_REPETIDA = 'La fecha ya está registrada como día sin atención.';

    /**
     * Listado (las fechas más próximas primero) con la cantidad de citas programadas o confirmadas de cada día.
     *
     * @CC CCHorarios (mostrarAgenda)
     *
     * @return array{0: LengthAwarePaginator, 1: array<string, int>} los días y el conteo de citas afectadas por fecha
     */
    public function listar(): array
    {
        $dias = BloqueoAgenda::orderByRaw('fecha < current_date')->orderBy('fecha')->paginate(self::POR_PAGINA);

        $afectadas = Cita::whereIn('fecha', $dias->pluck('fecha')->map->toDateString())
            ->whereIn('estado', [Cita::PROGRAMADA, Cita::CONFIRMADA])
            ->selectRaw('fecha, count(*) as total')->groupBy('fecha')->pluck('total', 'fecha')
            ->mapWithKeys(fn ($total, $fecha) => [Carbon::parse($fecha)->toDateString() => (int) $total])
            ->all();

        return [$dias, $afectadas];
    }

    /**
     * Citas ya programadas (estado Programada o Confirmada) en esa fecha: son las que quedan afectadas.
     *
     * @CC CCHorarios (guardar, paso 7) @CE CEMedico (obtener)
     *
     * @return Collection<int, Cita>
     */
    public function citasAfectadas(Carbon|string $fecha): Collection
    {
        return Cita::with(['paciente', 'medico', 'especialidad'])
            ->whereDate('fecha', Carbon::parse($fecha)->toDateString())
            ->whereIn('estado', [Cita::PROGRAMADA, Cita::CONFIRMADA])
            ->orderBy('hora_inicio')
            ->get();
    }

    /**
     * ¿Ya hay un día sin atención registrado en esa fecha (activo o no)?
     *
     * @CE CEHorario
     */
    public function existeFecha(string $fecha): bool
    {
        return BloqueoAgenda::whereDate('fecha', $fecha)->exists();
    }

    /**
     * Registra el día sin atención (activo).
     *
     * @CC CCHorarios (guardar) @CE CEHorario (crear)
     *
     * @param  array{fecha: string, motivo: string}  $datos
     *
     * @throws ValidationException si la fecha ya estaba registrada
     */
    public function guardar(array $datos, Usuario $actor): BloqueoAgenda
    {
        try {
            $dia = BloqueoAgenda::create(['fecha' => $datos['fecha'], 'motivo' => trim($datos['motivo']), 'estado' => true]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['fecha' => self::MENSAJE_REPETIDA]);
        }

        BitacoraService::registrar(
            'DIA_SIN_ATENCION_CREADO', 'DiaSinAtencion',
            'Día sin atención registrado: '.$dia->fecha->format('d/m/Y').' ('.$dia->motivo.'); citas afectadas: '.$this->citasAfectadas($dia->fecha)->count(),
            $actor->id
        );

        return $dia;
    }

    /**
     * Desactiva el día: la fecha vuelve a estar disponible en la agenda.
     *
     * @CC CCHorarios (desactivar) @CE CEHorario (desactivar)
     */
    public function desactivar(BloqueoAgenda $dia, Usuario $actor): void
    {
        $dia->update(['estado' => false]);
        BitacoraService::registrar('DIA_SIN_ATENCION_DESACTIVADO', 'DiaSinAtencion', 'Día sin atención desactivado: '.$dia->fecha->format('d/m/Y').' ('.$dia->motivo.')', $actor->id);
    }

    /**
     * Vuelve a bloquear la fecha (solo si no es anterior a hoy).
     *
     * @CC CCHorarios (actualizar) @CE CEHorario (actualizar)
     *
     * @throws ValidationException
     */
    public function activar(BloqueoAgenda $dia, Usuario $actor): void
    {
        if ($dia->fecha->lt(Carbon::today())) {
            throw ValidationException::withMessages(['fecha' => 'No se puede volver a bloquear una fecha anterior a hoy.']);
        }

        $dia->update(['estado' => true]);
        BitacoraService::registrar('DIA_SIN_ATENCION_ACTIVADO', 'DiaSinAtencion', 'Día sin atención activado: '.$dia->fecha->format('d/m/Y').' ('.$dia->motivo.')', $actor->id);
    }
}
