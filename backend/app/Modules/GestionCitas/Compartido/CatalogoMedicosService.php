<?php

namespace App\Modules\GestionCitas\Compartido;

use App\Models\Especialidad;
use App\Models\Medico;
use Illuminate\Database\Eloquent\Collection;

/**
 * Listas de médicos y especialidades que ofrecen las pantallas de GestionCitas (consultar, registrar y reservar):
 * solo médicos activos y especialidades activas.
 *
 * @CU CU-08, CU-09, CU-11 @RF RF08, RF09, RF11 @CI CIDisponibilidad, CIRegistrarCita, CIReservarCita @CC CCDisponibilidad, CCRegistrarCita, CCReservarCita @CE CEMedico, CEEspecialidad
 */
class CatalogoMedicosService
{
    /**
     * Médicos activos con al menos un horario de atención activo (precondición de CU-08).
     *
     * @CE CEMedico (horariosLibres)
     *
     * @return Collection<int, Medico>
     */
    public function medicosConHorario(): Collection
    {
        return Medico::with('especialidades')->where('estado', true)
            ->whereHas('horarios', fn ($q) => $q->where('estado', true))
            ->orderBy('apellidos')->orderBy('nombres')->get();
    }

    /**
     * Especialidades activas que tienen al menos un médico activo.
     *
     * @CC CCRegistrarCita (buscar), CCReservarCita (buscar) @CE CEEspecialidad
     *
     * @return Collection<int, Especialidad>
     */
    public function especialidadesConMedicos(): Collection
    {
        return Especialidad::where('estado', true)
            ->whereHas('medicos', fn ($q) => $q->where('estado', true))
            ->orderBy('nombre_normalizado')->get();
    }

    /**
     * Médicos activos de una especialidad activa, opcionalmente de una ubicación (consultorio o sede).
     *
     * @CC CCRegistrarCita (buscar), CCReservarCita (buscar) @CE CEMedico (buscar)
     *
     * @return Collection<int, Medico>
     */
    public function medicosDe(?int $especialidadId, ?string $ubicacion = null): Collection
    {
        if (! $especialidadId) {
            return new Collection;
        }

        return Medico::with('especialidades')->where('estado', true)
            ->whereHas('especialidades', fn ($q) => $q->where('especialidades.id', $especialidadId)->where('especialidades.estado', true))
            ->when(filled($ubicacion), fn ($q) => $q->where('ubicacion', trim($ubicacion)))
            ->orderBy('apellidos')->orderBy('nombres')->get();
    }

    /**
     * Ubicaciones (consultorio o sede) de los médicos activos, para el filtro de la búsqueda.
     *
     * @return list<string>
     */
    public function ubicaciones(?int $especialidadId = null): array
    {
        return Medico::where('estado', true)->whereNotNull('ubicacion')
            ->when($especialidadId, fn ($q) => $q->whereHas('especialidades', fn ($e) => $e->where('especialidades.id', $especialidadId)))
            ->distinct()->orderBy('ubicacion')->pluck('ubicacion')->all();
    }
}
