<?php

namespace App\Modules\GestionMedica\CU05_GestionarMedicos;

use App\Models\Especialidad;
use App\Models\Medico;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\SeguridadUsuarios\CU03_GestionarUsuariosRoles\UsuarioService;
use App\Services\BitacoraService;
use App\Support\Codigos;
use App\Support\Texto;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Médicos de la clínica: código MED-0001 generado con la secuencia de PostgreSQL, número de registro
 * profesional único, una o más especialidades y cuenta de acceso opcional con rol Médico. Nada se elimina:
 * un médico inactivo no recibe nuevas citas pero conserva su historial.
 *
 * @CU CU-05 @RF RF05 @CI CIMedicos @CC CCMedicos @CE CEMedico, CEEspecialidad
 */
class MedicoService
{
    public const POR_PAGINA = 10;

    public const MENSAJE_REGISTRO_REPETIDO = 'El número de registro profesional ya está registrado.';

    public function __construct(private readonly UsuarioService $usuarios) {}

    /**
     * Listado con búsqueda por nombre, apellido, código, número de registro o especialidad.
     *
     * @CC CCMedicos (mostrarListado) @CE CEMedico (obtener)
     */
    public function listar(?string $buscar = null): LengthAwarePaginator
    {
        $texto = Texto::normalizar((string) $buscar);
        $patron = Texto::patronLike($texto);
        $sinTildes = fn (string $columna) => Texto::sqlSinTildes($columna);

        return Medico::with(['especialidades', 'usuario'])
            ->when($texto !== '', fn ($consulta) => $consulta->where(fn ($q) => $q
                ->whereRaw($sinTildes('nombres').' like ?', [$patron])
                ->orWhereRaw($sinTildes('apellidos').' like ?', [$patron])
                ->orWhereRaw($sinTildes("nombres || ' ' || apellidos").' like ?', [$patron])
                ->orWhereRaw($sinTildes('codigo').' like ?', [$patron])
                ->orWhereRaw($sinTildes('nro_registro').' like ?', [$patron])
                ->orWhereHas('especialidades', fn ($e) => $e->where('nombre_normalizado', 'like', $patron))))
            ->orderBy('apellidos')->orderBy('nombres')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();
    }

    /**
     * Especialidades activas que se pueden asignar.
     *
     * @CC CCMedicos (crear) @CE CEEspecialidad (listarActivas)
     *
     * @return Collection<int, Especialidad>
     */
    public function especialidadesActivas(): Collection
    {
        return Especialidad::where('estado', true)->orderBy('nombre_normalizado')->get();
    }

    /**
     * Al editar se ofrecen las activas y también las que el médico ya tenía (aunque se hayan desactivado).
     *
     * @CC CCMedicos (editar) @CE CEEspecialidad (listarActivas)
     *
     * @return Collection<int, Especialidad>
     */
    public function especialidadesParaEditar(Medico $medico): Collection
    {
        $actuales = $medico->especialidades()->pluck('especialidades.id');

        return Especialidad::where('estado', true)->orWhereIn('id', $actuales)->orderBy('nombre_normalizado')->get();
    }

    /** ¿Ya existe otro médico con ese número de registro profesional? (sin distinguir mayúsculas) */
    public function existeNroRegistro(string $nroRegistro, ?int $ignorarId = null): bool
    {
        return Medico::whereRaw('upper(nro_registro) = ?', [mb_strtoupper(trim($nroRegistro))])
            ->when($ignorarId, fn ($q) => $q->where('id', '!=', $ignorarId))
            ->exists();
    }

    /**
     * Registra al médico: genera su código, guarda sus especialidades y, si se indicó, crea su cuenta
     * de usuario con rol Médico. Todo en una sola transacción.
     *
     * @CC CCMedicos (guardar) @CE CEMedico (crear)
     *
     * @param  array<string, mixed>  $datos  campos de MedicoRequest
     *
     * @throws ValidationException si el número de registro ya existe
     */
    public function guardar(array $datos, Usuario $actor): Medico
    {
        try {
            $medico = DB::transaction(function () use ($datos, $actor) {
                $medico = Medico::create($this->campos($datos) + [
                    'codigo' => Codigos::medico(),
                    'estado' => true,
                ]);
                $medico->especialidades()->sync($datos['especialidades']);

                $cuenta = $this->crearCuentaSiSeIndico($medico, $datos, $actor);

                BitacoraService::registrar(
                    'MEDICO_CREADO', 'Medico',
                    "Médico registrado: {$medico->codigo} {$medico->nombre_completo} (registro {$medico->nro_registro})".($cuenta ? "; cuenta {$cuenta->nombre_usuario}" : ''),
                    $actor->id
                );

                return $medico;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['nro_registro' => self::MENSAJE_REGISTRO_REPETIDO]);
        }

        return $medico;
    }

    /**
     * Actualiza los datos y las especialidades; si el médico no tenía cuenta y se indicó una, la crea.
     *
     * @CC CCMedicos (actualizar) @CE CEMedico (actualizar)
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws ValidationException
     */
    public function actualizar(Medico $medico, array $datos, Usuario $actor): Medico
    {
        try {
            DB::transaction(function () use ($medico, $datos, $actor) {
                $medico->update($this->campos($datos));
                $medico->especialidades()->sync($datos['especialidades']);

                $cuenta = $medico->usuario_id === null ? $this->crearCuentaSiSeIndico($medico, $datos, $actor) : null;

                BitacoraService::registrar(
                    'MEDICO_EDITADO', 'Medico',
                    "Médico actualizado: {$medico->codigo} {$medico->nombre_completo}".($cuenta ? "; cuenta creada {$cuenta->nombre_usuario}" : ''),
                    $actor->id
                );
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['nro_registro' => self::MENSAJE_REGISTRO_REPETIDO]);
        }

        return $medico->refresh();
    }

    /**
     * Desactiva: no recibe nuevas citas, pero sus citas históricas y su historial se conservan.
     *
     * @CC CCMedicos (desactivar) @CE CEMedico (desactivar)
     */
    public function desactivar(Medico $medico, Usuario $actor): void
    {
        $medico->update(['estado' => false]);
        BitacoraService::registrar('MEDICO_DESACTIVADO', 'Medico', "Médico desactivado: {$medico->codigo} {$medico->nombre_completo}", $actor->id);
    }

    /** @CC CCMedicos (actualizar) @CE CEMedico (actualizar) */
    public function activar(Medico $medico, Usuario $actor): void
    {
        $medico->update(['estado' => true]);
        BitacoraService::registrar('MEDICO_ACTIVADO', 'Medico', "Médico activado: {$medico->codigo} {$medico->nombre_completo}", $actor->id);
    }

    /** Campos propios de la tabla medicos (sin código, estado ni cuenta). */
    private function campos(array $datos): array
    {
        $texto = fn (string $clave): ?string => ($valor = trim((string) ($datos[$clave] ?? ''))) === '' ? null : $valor;

        return [
            'nro_registro' => mb_strtoupper(trim($datos['nro_registro'])),
            'nombres' => trim($datos['nombres']),
            'apellidos' => trim($datos['apellidos']),
            'telefono' => $texto('telefono'),
            'correo' => ($correo = $texto('correo')) === null ? null : mb_strtolower($correo),
            'ubicacion' => $texto('ubicacion'),
            'puede_marcar_atendida' => (bool) ($datos['puede_marcar_atendida'] ?? false),
        ];
    }

    /** Crea la cuenta de acceso (rol Médico) con el correo del médico y la enlaza si se marcó la casilla. */
    private function crearCuentaSiSeIndico(Medico $medico, array $datos, Usuario $actor): ?Usuario
    {
        if (! ($datos['crear_cuenta'] ?? false)) {
            return null;
        }

        $cuenta = $this->usuarios->guardar([
            'nombre_usuario' => $datos['nombre_usuario'],
            'correo' => $medico->correo,
            'password' => $datos['password'],
            'roles' => [Rol::where('nombre', Rol::MEDICO)->value('id')],
        ], $actor);

        $medico->update(['usuario_id' => $cuenta->id]);

        return $cuenta;
    }
}
