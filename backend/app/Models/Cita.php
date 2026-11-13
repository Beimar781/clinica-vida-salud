<?php

namespace App\Models;

use App\Support\Dinero;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

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

    /** Momento en que empieza la cita (fecha + hora de inicio, en la zona horaria del sistema). */
    public function getInicioAttribute(): Carbon
    {
        return $this->fecha->copy()->setTimeFromTimeString($this->hora_inicio);
    }

    /** Momento en que termina la cita (fecha + hora final). */
    public function getFinAttribute(): Carbon
    {
        return $this->fecha->copy()->setTimeFromTimeString($this->hora_fin);
    }

    /** ¿Ya empezó (o pasó) la hora de la cita? */
    public function getYaPasoAttribute(): bool
    {
        return $this->inicio->lte(Carbon::now());
    }

    /**
     * «Vencida · pendiente de cierre»: sigue Programada o Confirmada pero su hora final ya pasó. Es una señal calculada (no un estado):
     * recepción la cierra con Atender/Inasistencia/Cancelar según corresponda, con su bitácora.
     */
    public function getVencidaAttribute(): bool
    {
        return in_array($this->estado, [self::PROGRAMADA, self::CONFIRMADA], true) && $this->fin->lt(Carbon::now());
    }

    /** ¿Se le puede enviar un recordatorio ahora? Programada o Confirmada y que todavía no haya empezado. */
    public function getRecordableAttribute(): bool
    {
        return in_array($this->estado, [self::PROGRAMADA, self::CONFIRMADA], true) && ! $this->ya_paso;
    }

    /** Pago pendiente de verdad: falta cobrar y el monto es mayor que cero (un servicio sin costo no tiene nada por cobrar). */
    public function getCobroPendienteAttribute(): bool
    {
        return $this->estado_pago === self::PAGO_PENDIENTE && (float) $this->monto > 0;
    }

    /** Atendida pero con el pago todavía pendiente (hay que cobrarla). */
    public function getAtendidaSinCobroAttribute(): bool
    {
        return $this->estado === self::ATENDIDA && $this->cobro_pendiente;
    }

    /** Citas vencidas (SQL): Programada o Confirmada cuya hora final ya pasó. */
    public function scopeVencidas(Builder $consulta): Builder
    {
        return $consulta->whereIn('estado', [self::PROGRAMADA, self::CONFIRMADA])
            ->whereRaw('(fecha + hora_fin) < ?', [Carbon::now()->format('Y-m-d H:i:s')]);
    }

    /** Citas con el pago registrado (estado_pago = PAGADO). */
    public function scopePagadas(Builder $consulta): Builder
    {
        return $consulta->where('estado_pago', self::PAGO_PAGADO);
    }

    /** «Efectivo en la clínica» o «Tarjeta (Stripe)». */
    public function getMetodoPagoTextoAttribute(): string
    {
        return $this->metodo_pago === self::METODO_TARJETA ? 'Tarjeta (Stripe)' : 'Efectivo en la clínica';
    }

    /** «Pagado» o «Pendiente». */
    public function getEstadoPagoTextoAttribute(): string
    {
        return $this->estado_pago === self::PAGO_PAGADO ? 'Pagado' : 'Pendiente';
    }

    /** «Bs 100,00». */
    public function getMontoTextoAttribute(): string
    {
        return Dinero::formato($this->monto);
    }

    /** Resumen para la bitácora: «Tarjeta (Stripe), Pagado, Bs 100,00». */
    public function resumenPago(): string
    {
        return $this->metodo_pago_texto.', '.$this->estado_pago_texto.', '.$this->monto_texto;
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

    /** Historial de cambios de la cita (del más antiguo al más reciente). */
    public function eventos(): HasMany
    {
        return $this->hasMany(CitaEvento::class, 'cita_id')->orderBy('created_at')->orderBy('id');
    }

    /** La consulta registrada en esta cita (CU-16); una cita tiene como máximo una. */
    public function consulta(): HasOne
    {
        return $this->hasOne(Consulta::class, 'cita_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'creado_por');
    }
}
