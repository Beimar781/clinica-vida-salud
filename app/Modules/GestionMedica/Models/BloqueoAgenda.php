<?php

namespace App\Modules\GestionMedica\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @CE CEBloqueoAgenda (tabla bloqueos_agenda): feriados y días sin atención.
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
