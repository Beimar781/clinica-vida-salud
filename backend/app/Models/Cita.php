<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @CU CU-08 a CU-11 @RF RF08 a RF11 @CE CECita (tabla citas). El código CM-0001 sale de citas_codigo_seq.
 */
class Cita extends Model
{
    public const PROGRAMADA = 'PROGRAMADA';

    public const CONFIRMADA = 'CONFIRMADA';

    public const ATENDIDA = 'ATENDIDA';

    public const CANCELADA = 'CANCELADA';

    public const INASISTENCIA = 'INASISTENCIA';

    public const PRESENCIAL = 'PRESENCIAL';

    public const VIRTUAL = 'VIRTUAL';

    /** Método de pago (v30): TARJETA = Stripe, EFECTIVO = lo cobra la recepción. */
    public const METODO_TARJETA = 'TARJETA';

    public const METODO_EFECTIVO = 'EFECTIVO';

    /** Estado del pago. */
    public const PAGO_PENDIENTE = 'PENDIENTE';

    public const PAGO_PAGADO = 'PAGADO';

    protected $table = 'citas';

    protected $fillable = [
        'codigo', 'paciente_id', 'medico_id', 'especialidad_id', 'fecha', 'hora_inicio', 'hora_fin',
        'modalidad', 'motivo', 'estado', 'motivo_cancelacion', 'monto', 'metodo_pago', 'estado_pago',
        'referencia_pago', 'fecha_pago', 'recordatorio_enviado', 'creado_por',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'monto' => 'decimal:2',
            'fecha_pago' => 'datetime',
            'recordatorio_enviado' => 'boolean',
        ];
    }

    /** Citas con el pago registrado (estado_pago = PAGADO). */
    public function scopePagadas(Builder $consulta): Builder
    {
        return $consulta->where('estado_pago', self::PAGO_PAGADO);
    }

    public function estaPagada(): bool
    {
        return $this->estado_pago === self::PAGO_PAGADO;
    }

    /** Las citas canceladas no ocupan horario (WHERE estado <> 'CANCELADA' en las restricciones EXCLUDE). */
    public function ocupaHorario(): bool
    {
        return $this->estado !== self::CANCELADA;
    }

    /** «HH:MM» de la hora de inicio (la columna time llega como HH:MM:SS). */
    public function getHoraInicioCortaAttribute(): string
    {
        return substr($this->hora_inicio, 0, 5);
    }

    public function getHoraFinCortaAttribute(): string
    {
        return substr($this->hora_fin, 0, 5);
    }

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'paciente_id');
    }

    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class, 'medico_id');
    }

    public function especialidad(): BelongsTo
    {
        return $this->belongsTo(Especialidad::class, 'especialidad_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'creado_por');
    }
}
