<?php

namespace App\Modules\GestionMedica\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @CE CEEspecialidad (tabla especialidades)
 */
class Especialidad extends Model
{
    protected $table = 'especialidades';

    public $timestamps = false;

    protected $fillable = ['nombre', 'nombre_normalizado', 'descripcion', 'estado'];

    protected function casts(): array
    {
        return ['estado' => 'boolean'];
    }

    public function medicos(): BelongsToMany
    {
        return $this->belongsToMany(Medico::class, 'medico_especialidad', 'especialidad_id', 'medico_id');
    }
}
