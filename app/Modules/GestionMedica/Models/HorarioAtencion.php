<?php

namespace App\Modules\GestionMedica\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @CE CEHorarioAtencion (tabla horarios_atencion). dia_semana: 1 = lunes ... 7 = domingo.
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

    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class, 'medico_id');
    }
}
