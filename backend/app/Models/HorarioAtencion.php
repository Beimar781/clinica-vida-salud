<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @CU CU-06, CU-08 @RF RF06, RF08 @CE CEHorario (tabla horarios_atencion; «duracion_min» se muestra como «duración»). dia_semana: 1 = lunes ... 7 = domingo.
 */
class HorarioAtencion extends Model
{
    protected $table = 'horarios_atencion';

    public $timestamps = false;

    protected $fillable = ['medico_id', 'dia_semana', 'hora_inicio', 'hora_fin', 'duracion_min', 'estado'];

    protected function casts(): array
    {
        return ['dia_semana' => 'integer', 'duracion_min' => 'integer', 'estado' => 'boolean'];
    }

    /** «HH:MM» de la hora inicial (la columna time llega como HH:MM:SS). */
    public function getHoraInicioCortaAttribute(): string
    {
        return substr($this->hora_inicio, 0, 5);
    }

    public function getHoraFinCortaAttribute(): string
    {
        return substr($this->hora_fin, 0, 5);
    }

    /** Cantidad de citas que caben en el bloque (por ejemplo 08:00 a 12:00 con 30 minutos = 8). */
    public function getCantidadCitasAttribute(): int
    {
        [$hi, $mi] = array_map('intval', explode(':', $this->hora_inicio));
        [$hf, $mf] = array_map('intval', explode(':', $this->hora_fin));

        return intdiv(($hf * 60 + $mf) - ($hi * 60 + $mi), $this->duracion_min);
    }

    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class, 'medico_id');
    }
}
