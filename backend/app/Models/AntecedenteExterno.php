<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @CU CU-07 @RF RF07 @CE CEAntecedente (tabla antecedentes_externos): atenciones previas en otros hospitales o clínicas.
 */
class AntecedenteExterno extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'antecedentes_externos';

    protected $fillable = [
        'historia_clinica_id', 'hospital_origen', 'fecha_atencion', 'especialidad', 'diagnostico',
        'tratamiento', 'observaciones', 'registrado_por',
    ];

    protected function casts(): array
    {
        return ['fecha_atencion' => 'date'];
    }

    public function historiaClinica(): BelongsTo
    {
        return $this->belongsTo(HistoriaClinica::class, 'historia_clinica_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por');
    }
}
