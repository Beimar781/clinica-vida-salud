<?php

namespace App\Modules\GestionMedica\CU04_GestionarEspecialidadesServicios;

use App\Models\Especialidad;
use App\Models\Usuario;
use App\Services\BitacoraService;
use App\Support\Dinero;
use App\Support\Texto;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Catálogo de especialidades y servicios (consulta médica, laboratorio y enfermería) con su precio. El nombre
 * se compara «normalizado» (minúsculas, sin tildes y sin espacios sobrantes) para rechazar duplicados; nada se
 * elimina, solo se desactiva. Cambiar el precio no altera las citas ya registradas (guardan su propio monto).
 *
 * @CU CU-04 @RF RF04 @CI CIEspecialidad @CC CCEspecialidad @CE CEEspecialidad
 */
class EspecialidadService
{
    public const POR_PAGINA = 10;

    public const MENSAJE_DUPLICADO = 'Ya existe una especialidad con ese nombre (se comparan sin mayúsculas, tildes ni espacios sobrantes).';

    /**
     * Listado con tipo, precio, estado y la cantidad de médicos activos; busca por nombre sin importar tildes y
     * filtra por tipo (CONSULTA, LABORATORIO o ENFERMERIA; cualquier otro valor se ignora).
     *
     * @CC CCEspecialidad (mostrarListado) @CE CEEspecialidad
     */
    public function listar(?string $buscar = null, ?string $tipo = null): LengthAwarePaginator
    {
        $normalizado = Texto::normalizar((string) $buscar);

        return Especialidad::withCount(['medicos' => fn ($q) => $q->where('estado', true)])
            ->when(array_key_exists((string) $tipo, Especialidad::TIPOS), fn ($q) => $q->where('tipo', $tipo))
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
     * Registra una especialidad o servicio nuevo (activo).
     *
     * @CC CCEspecialidad (guardar) @CE CEEspecialidad (crear)
     *
     * @param  array{nombre: string, tipo: string, precio: string|float, descripcion?: ?string}  $datos
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
                'tipo' => $datos['tipo'],
                'precio' => $datos['precio'],
                'descripcion' => $this->descripcion($datos),
                'estado' => true,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Dos administradores guardando el mismo nombre a la vez: la restricción UNIQUE lo rechaza.
            throw ValidationException::withMessages(['nombre' => self::MENSAJE_DUPLICADO]);
        }

        BitacoraService::registrar(
            'ESPECIALIDAD_CREADA', 'Especialidad',
            'Especialidad registrada: '.$especialidad->nombre.' ('.$this->resumen($especialidad->fresh()).')',
            $actor->id
        );

        return $especialidad->fresh();
    }

    /**
     * @CC CCEspecialidad (actualizar) @CE CEEspecialidad (actualizar)
     *
     * @param  array{nombre: string, tipo: string, precio: string|float, descripcion?: ?string}  $datos
     *
     * @throws ValidationException si el nombre normalizado ya existe
     */
    public function actualizar(Especialidad $especialidad, array $datos, Usuario $actor): Especialidad
    {
        $nombre = $this->limpiar($datos['nombre']);
        $antes = $this->resumen($especialidad);

        try {
            $especialidad->update([
                'nombre' => $nombre,
                'nombre_normalizado' => Texto::normalizar($nombre),
                'tipo' => $datos['tipo'],
                'precio' => $datos['precio'],
                'descripcion' => $this->descripcion($datos),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['nombre' => self::MENSAJE_DUPLICADO]);
        }

        $especialidad = $especialidad->fresh();
        $despues = $this->resumen($especialidad);
        BitacoraService::registrar(
            'ESPECIALIDAD_EDITADA', 'Especialidad',
            'Especialidad actualizada: '.$especialidad->nombre.' ('.($antes === $despues ? $despues : $antes.' → '.$despues).')',
            $actor->id
        );

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

    /** «Consulta médica, Bs 100,00»: lo que se anota en la bitácora de altas y ediciones. */
    private function resumen(Especialidad $especialidad): string
    {
        return $especialidad->tipo_texto.', '.Dinero::formato($especialidad->precio);
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
