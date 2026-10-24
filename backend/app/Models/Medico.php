<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @CU CU-05, CU-08 a CU-11 @RF RF05 @CE CEMedico (tabla medicos). El código MED-0001 sale de medicos_codigo_seq.
 */
class Medico extends Model
{
    protected $table = 'medicos';

    public $timestamps = false;

    protected $fillable = [
        'usuario_id', 'codigo', 'nro_registro', 'nombres', 'apellidos', 'telefono', 'correo',
        'ubicacion', 'puede_marcar_atendida', 'estado',
    ];

    protected function casts(): array
    {
        return ['puede_marcar_atendida' => 'boolean', 'estado' => 'boolean'];
    }

    public function getNombreCompletoAttribute(): string
    {
        return $this->nombres.' '.$this->apellidos;
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function especialidades(): BelongsToMany
    {
        return $this->belongsToMany(Especialidad::class, 'medico_especialidad', 'medico_id', 'especialidad_id');
    }

    public function horarios(): HasMany
    {
        return $this->hasMany(HorarioAtencion::class, 'medico_id');
    }

    public function citas(): HasMany
    {
        return $this->hasMany(Cita::class, 'medico_id');
    }
}
