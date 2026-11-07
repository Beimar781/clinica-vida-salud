<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @CU CU-16, CU-17 @RF RF16, RF17 @CE CEConsulta (tabla consultas): la atención médica de una cita (diagnóstico, tratamiento y
 * observaciones). Una cita tiene como máximo una consulta (cita_id UNIQUE) y toda consulta pertenece a la historia clínica del paciente.
 */
class Consulta extends Model
{
    protected $table = 'consultas';

    protected $fillable = ['cita_id', 'historia_clinica_id', 'medico_id', 'diagnostico', 'tratamiento', 'observaciones'];

    public function cita(): BelongsTo
    {
        return $this->belongsTo(Cita::class, 'cita_id');
    }

    public function historiaClinica(): BelongsTo
    {
        return $this->belongsTo(HistoriaClinica::class, 'historia_clinica_id');
    }

    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class, 'medico_id');
    }

    /**
     * La consulta de una cita, si existe.
     *
     * @CE CEConsulta (obtenerPorCita)
     */
    public static function obtenerPorCita(int $citaId): ?self
    {
        return static::where('cita_id', $citaId)->first();
    }

    /**
     * Las consultas de una historia clínica, de la más reciente a la más antigua (por la fecha y hora de su cita).
     *
     * @CE CEConsulta (consultasDeHistoria)
     *
     * @return Collection<int, Consulta>
     */
    public static function consultasDeHistoria(int $historiaId): Collection
    {
        return static::with(['cita.especialidad', 'medico'])
            ->join('citas', 'citas.id', '=', 'consultas.cita_id')
            ->where('consultas.historia_clinica_id', $historiaId)
            ->orderByDesc('citas.fecha')->orderByDesc('citas.hora_inicio')->orderByDesc('consultas.id')
            ->select('consultas.*')
            ->get();
    }
}
