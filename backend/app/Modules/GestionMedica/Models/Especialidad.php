<?php

namespace App\Modules\GestionMedica\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @CU CU-04, CU-05 @RF RF04, RF05 @CE CEEspecialidad (tabla especialidades)
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
