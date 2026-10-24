<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @CU CU-07 @RF RF07 @CE CEHistoriaClinica (tabla historias_clinicas): una sola por paciente, se crea al registrarlo.
 */
class HistoriaClinica extends Model
{
    protected $table = 'historias_clinicas';

    protected $fillable = [
        'paciente_id', 'fecha_apertura', 'tipo_sangre', 'antecedentes_personales', 'alergias',
        'enfermedades_cronicas', 'medicamentos', 'observaciones',
    ];

    protected function casts(): array
    {
        return ['fecha_apertura' => 'date'];
    }

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'paciente_id');
    }

    public function antecedentesExternos(): HasMany
    {
        return $this->hasMany(AntecedenteExterno::class, 'historia_clinica_id');
    }
}
