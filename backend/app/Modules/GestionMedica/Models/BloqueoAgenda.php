<?php

namespace App\Modules\GestionMedica\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @CU CU-06, CU-08 @RF RF06, RF08 @CE (tabla bloqueos_agenda; los diagramas no la nombran como clase): feriados y días sin atención.
 */
class BloqueoAgenda extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'bloqueos_agenda';

    protected $fillable = ['fecha', 'motivo', 'estado'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'estado' => 'boolean'];
    }
}
