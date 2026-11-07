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
    /** Tipos del catálogo (v30): consulta médica, servicio de laboratorio o de enfermería. */
    public const CONSULTA = 'CONSULTA';

    public const LABORATORIO = 'LABORATORIO';

    public const ENFERMERIA = 'ENFERMERIA';

    /** Valor guardado => texto en pantalla. */
    public const TIPOS = [
        self::CONSULTA => 'Consulta médica',
        self::LABORATORIO => 'Laboratorio',
        self::ENFERMERIA => 'Enfermería',
    ];

    protected $table = 'especialidades';

    public $timestamps = false;

    protected $fillable = ['nombre', 'nombre_normalizado', 'descripcion', 'tipo', 'precio', 'estado'];

    protected function casts(): array
    {
        return ['estado' => 'boolean', 'precio' => 'decimal:2'];
    }

    /** Texto del tipo para la interfaz («Consulta médica», «Laboratorio», «Enfermería»). */
    public function getTipoTextoAttribute(): string
    {
        return self::TIPOS[$this->tipo] ?? (string) $this->tipo;
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
