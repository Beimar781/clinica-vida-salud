<?php

namespace App\Modules\GestionMedica\CU04_GestionarEspecialidadesMedicas;

use App\Models\Especialidad;
use App\Models\Usuario;
use App\Services\BitacoraService;
use App\Support\Texto;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Catálogo de especialidades. El nombre se compara «normalizado» (minúsculas, sin tildes y sin espacios
 * sobrantes) para rechazar duplicados; nada se elimina, solo se desactiva.
 *
 * @CU CU-04 @RF RF04 @CI CIEspecialidad @CC CCEspecialidad @CE CEEspecialidad
 */
class EspecialidadService
{
    public const POR_PAGINA = 10;

    public const MENSAJE_DUPLICADO = 'Ya existe una especialidad con ese nombre (se comparan sin mayúsculas, tildes ni espacios sobrantes).';

    /**
     * Listado con su estado y la cantidad de médicos activos; busca por nombre sin importar tildes.
     *
     * @CC CCEspecialidad (mostrarListado) @CE CEEspecialidad
     */
    public function listar(?string $buscar = null): LengthAwarePaginator
    {
        $normalizado = Texto::normalizar((string) $buscar);

        return Especialidad::withCount(['medicos' => fn ($q) => $q->where('estado', true)])
            ->when($normalizado !== '', fn ($q) => $q->where(
                'nombre_normalizado', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $normalizado).'%'
            ))
            ->orderBy('nombre_normalizado')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();
    }

    /**
     * ¿Ya existe otra especialidad con el mismo nombre normalizado?
     *
     * @CC CCEspecialidad @CE CEEspecialidad (existeNombre)
     */
    public function existeNombre(string $nombre, ?int $ignorarId = null): bool
    {
        return Especialidad::where('nombre_normalizado', Texto::normalizar($nombre))
            ->when($ignorarId, fn ($q) => $q->where('id', '!=', $ignorarId))
            ->exists();
    }

    /**
     * Registra una especialidad nueva (activa).
     *
     * @CC CCEspecialidad (guardar) @CE CEEspecialidad (crear)
     *
     * @param  array{nombre: string, descripcion?: ?string}  $datos
     *
     * @throws ValidationException si el nombre normalizado ya existe
     */
    public function guardar(array $datos, Usuario $actor): Especialidad
    {
        $nombre = $this->limpiar($datos['nombre']);

        try {
            $especialidad = Especialidad::create([
                'nombre' => $nombre,
                'nombre_normalizado' => Texto::normalizar($nombre),
                'descripcion' => $this->descripcion($datos),
                'estado' => true,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Dos administradores guardando el mismo nombre a la vez: la restricción UNIQUE lo rechaza.
            throw ValidationException::withMessages(['nombre' => self::MENSAJE_DUPLICADO]);
        }

        BitacoraService::registrar('ESPECIALIDAD_CREADA', 'Especialidad', 'Especialidad registrada: '.$especialidad->nombre, $actor->id);

        return $especialidad;
    }

    /**
     * @CC CCEspecialidad (actualizar) @CE CEEspecialidad (actualizar)
     *
     * @param  array{nombre: string, descripcion?: ?string}  $datos
     *
     * @throws ValidationException si el nombre normalizado ya existe
     */
    public function actualizar(Especialidad $especialidad, array $datos, Usuario $actor): Especialidad
    {
        $nombre = $this->limpiar($datos['nombre']);

        try {
            $especialidad->update([
                'nombre' => $nombre,
                'nombre_normalizado' => Texto::normalizar($nombre),
                'descripcion' => $this->descripcion($datos),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['nombre' => self::MENSAJE_DUPLICADO]);
        }

        BitacoraService::registrar('ESPECIALIDAD_EDITADA', 'Especialidad', 'Especialidad actualizada: '.$especialidad->nombre, $actor->id);

        return $especialidad;
    }

    /**
     * Desactiva: deja de ofrecerse para nuevas citas, pero las citas históricas y los médicos se conservan.
     *
     * @CC CCEspecialidad (desactivar) @CE CEEspecialidad (desactivar)
     */
    public function desactivar(Especialidad $especialidad, Usuario $actor): void
    {
        $especialidad->update(['estado' => false]);
        BitacoraService::registrar('ESPECIALIDAD_DESACTIVADA', 'Especialidad', 'Especialidad desactivada: '.$especialidad->nombre, $actor->id);
    }

    /** @CC CCEspecialidad (actualizar) @CE CEEspecialidad (actualizar) */
    public function activar(Especialidad $especialidad, Usuario $actor): void
    {
        $especialidad->update(['estado' => true]);
        BitacoraService::registrar('ESPECIALIDAD_ACTIVADA', 'Especialidad', 'Especialidad activada: '.$especialidad->nombre, $actor->id);
    }

    /** Quita espacios sobrantes al guardar el nombre tal como lo escribió la persona. */
    private function limpiar(string $nombre): string
    {
        return trim(preg_replace('/\s+/u', ' ', $nombre));
    }

    private function descripcion(array $datos): ?string
    {
        $descripcion = trim((string) ($datos['descripcion'] ?? ''));

        return $descripcion === '' ? null : $descripcion;
    }
}
