<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @CU CU-07, CU-09, CU-11 @RF RF07 @CE CEPaciente (tabla pacientes). El código PAC-0001 sale de pacientes_codigo_seq.
 */
class Paciente extends Model
{
    /**
     * Aviso para una cuenta con rol Paciente que todavía no tiene ficha en `pacientes` (por ejemplo, la creó el administrador
     * en CU-03). El v30 resuelve el caso con CU-20 (vincula la ficha por CI y fecha de nacimiento).
     */
    public const MENSAJE_SIN_FICHA = 'Tu cuenta aún no tiene ficha de paciente. Pide en recepción que la registre o usa «Crear cuenta» con tu CI.';

    protected $table = 'pacientes';

    public $timestamps = false;

    protected $fillable = [
        'usuario_id', 'codigo', 'ci', 'nombres', 'apellidos', 'fecha_nacimiento', 'sexo', 'telefono',
        'correo', 'direccion', 'emergencia_nombre', 'emergencia_parentesco', 'emergencia_telefono',
        'recibe_recordatorios', 'mostrar_contacto_medico', 'estado',
    ];

    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
            'recibe_recordatorios' => 'boolean',
            'mostrar_contacto_medico' => 'boolean',
            'estado' => 'boolean',
        ];
    }

    public function getNombreCompletoAttribute(): string
    {
        return $this->nombres.' '.$this->apellidos;
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function historiaClinica(): HasOne
    {
        return $this->hasOne(HistoriaClinica::class, 'paciente_id');
    }

    public function citas(): HasMany
    {
        return $this->hasMany(Cita::class, 'paciente_id');
    }
}
