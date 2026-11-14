<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Historial de cambios de una cita (extra aprobado de CU-10, CU-12, CU-13 y CU-15): cada alta, confirmación, atención, inasistencia,
 * reprogramación, cancelación o corrección deja un evento con lo que cambió, para que el paciente vea la historia de SU cita
 * (la bitácora solo la ve el administrador). Se escribe al terminar con éxito la operación que cambia la cita y nunca la afecta si falla.
 *
 * @CU CU-10, CU-12, CU-13, CU-15 @CE CECita (historial de cambios)
 */
class CitaEvento extends Model
{
    public const REGISTRADA = 'REGISTRADA';

    public const CONFIRMADA = 'CONFIRMADA';

    public const ATENDIDA = 'ATENDIDA';

    public const INASISTENCIA = 'INASISTENCIA';

    public const REPROGRAMADA = 'REPROGRAMADA';

    public const CANCELADA = 'CANCELADA';

    public const ESTADO_CORREGIDO = 'ESTADO_CORREGIDO';

    public const UPDATED_AT = null;

    protected $table = 'cita_eventos';

    protected $fillable = [
        'cita_id', 'tipo', 'fecha_anterior', 'hora_anterior', 'fecha_nueva', 'hora_nueva',
        'estado_anterior', 'estado_nuevo', 'motivo', 'usuario_id', 'created_at',
    ];

    protected function casts(): array
    {
        return ['fecha_anterior' => 'date', 'fecha_nueva' => 'date', 'created_at' => 'datetime'];
    }

    /**
     * Deja el evento de un cambio de la cita. Se llama dentro de la operación, pero se escribe al confirmarla.
     *
     * @param  array{fecha_anterior?: ?string, hora_anterior?: ?string, fecha_nueva?: ?string, hora_nueva?: ?string, estado_anterior?: ?string, estado_nuevo?: ?string, motivo?: ?string}  $datos
     */
    public static function registrar(Cita $cita, string $tipo, ?int $usuarioId, array $datos = []): void
    {
        $fila = ['cita_id' => $cita->id, 'tipo' => $tipo, 'usuario_id' => $usuarioId] + $datos;

        // Después de que la operación principal terminó con éxito (si se deshace, el evento no se escribe). Un fallo aquí se reporta y se
        // ignora: guardar el historial nunca cancela ni muestra error en el registro, la reserva, la reprogramación, la cancelación ni el estado.
        DB::afterCommit(function () use ($fila) {
            try {
                DB::transaction(fn () => self::create($fila));
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    public function cita(): BelongsTo
    {
        return $this->belongsTo(Cita::class, 'cita_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function getHoraAnteriorCortaAttribute(): ?string
    {
        return $this->hora_anterior ? substr($this->hora_anterior, 0, 5) : null;
    }

    public function getHoraNuevaCortaAttribute(): ?string
    {
        return $this->hora_nueva ? substr($this->hora_nueva, 0, 5) : null;
    }
}
