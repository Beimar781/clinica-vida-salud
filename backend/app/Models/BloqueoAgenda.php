<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @CU CU-06, CU-08 @RF RF06, RF08 @CE (tabla bloqueos_agenda; los diagramas no la nombran como clase): feriados y días sin atención.
 */
class BloqueoAgenda extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'bloqueos_agenda';

    protected $fillable = ['fecha', 'motivo', 'estado', 'registrado_por'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'estado' => 'boolean'];
    }

    /** Usuario que registró el día sin atención (v30: registrado_por, NOT NULL). */
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por');
    }
}
