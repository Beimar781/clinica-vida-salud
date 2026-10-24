<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

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

    /**
     * Las especialidades activas, ordenadas por nombre.
     *
     * @CE CEEspecialidad (listarActivas)
     *
     * @return Collection<int, Especialidad>
     */
    public static function listarActivas(): Collection
    {
        return static::where('estado', true)->orderBy('nombre_normalizado')->get();
    }

    public function medicos(): BelongsToMany
    {
        return $this->belongsToMany(Medico::class, 'medico_especialidad', 'especialidad_id', 'medico_id');
    }
}
