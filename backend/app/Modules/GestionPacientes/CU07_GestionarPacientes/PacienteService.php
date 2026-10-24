<?php

namespace App\Modules\GestionPacientes\CU07_GestionarPacientes;

use App\Models\AntecedenteExterno;
use App\Models\HistoriaClinica;
use App\Models\Paciente;
use App\Models\Usuario;
use App\Services\BitacoraService;
use App\Support\Codigos;
use App\Support\Texto;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registro único de pacientes: CI único, código PAC-0001 generado con la secuencia de PostgreSQL,
 * historia clínica creada en la misma transacción (una sola por paciente) y antecedentes de otros
 * hospitales opcionales. Nada se elimina: un paciente inactivo conserva su historial de citas y su historia clínica.
 *
 * @CU CU-07 @RF RF07 @CI CIPacientes @CC CCPacientes @CE CEPaciente, CEHistoriaClinica, CEAntecedente
 */
class PacienteService
{
    public const POR_PAGINA = 10;

    public const MENSAJE_CI_REPETIDO = 'El CI ya está registrado.';

    /**
     * Listado con búsqueda por CI, nombre, apellido o código (sin distinguir mayúsculas).
     *
     * @CC CCPacientes (buscar) @CE CEPaciente (buscar)
     */
    public function listar(?string $buscar = null): LengthAwarePaginator
    {
        $texto = Texto::normalizar((string) $buscar);
        $patron = Texto::patronLike($texto);
        $sinTildes = fn (string $columna) => Texto::sqlSinTildes($columna);

        return Paciente::withCount('citas')
            ->when($texto !== '', fn ($consulta) => $consulta->where(fn ($q) => $q
                ->whereRaw($sinTildes('ci').' like ?', [$patron])
                ->orWhereRaw($sinTildes('nombres').' like ?', [$patron])
                ->orWhereRaw($sinTildes('apellidos').' like ?', [$patron])
                ->orWhereRaw($sinTildes("nombres || ' ' || apellidos").' like ?', [$patron])
                ->orWhereRaw($sinTildes('codigo').' like ?', [$patron])))
            ->orderBy('apellidos')->orderBy('nombres')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();
    }

    /** CI sin espacios sobrantes y en mayúsculas (la letra del complemento, por ejemplo «1234567-1A»). */
    public static function normalizarCI(string $ci): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $ci)));
    }

    /**
     * El paciente que ya tiene ese CI (para mostrarlo cuando se intenta repetir).
     *
     * @CE CEPaciente (buscar)
     */
    public function buscarPorCI(string $ci): ?Paciente
    {
        return Paciente::with('historiaClinica')->where('ci', self::normalizarCI($ci))->first();
    }

    /**
     * ¿Ya existe otro paciente con ese CI?
     *
     * @CC CCPacientes @CE CEPaciente (existeCI)
     */
    public function existeCI(string $ci, ?int $ignorarId = null): bool
    {
        return Paciente::where('ci', self::normalizarCI($ci))
            ->when($ignorarId, fn ($q) => $q->where('id', '!=', $ignorarId))
            ->exists();
    }

    /**
     * Antecedentes de otros hospitales de un paciente (los más recientes primero).
     *
     * @CE CEAntecedente
     *
     * @return Collection<int, AntecedenteExterno>
     */
    public function antecedentes(Paciente $paciente): Collection
    {
        $historia = $paciente->historiaClinica;

        return $historia
            ? $historia->antecedentesExternos()->orderByDesc('fecha_atencion')->orderByDesc('id')->get()
            : new Collection;
    }

    /**
     * Registra al paciente: genera su código, crea su historia clínica y, si viene de otro hospital, guarda
     * sus antecedentes. Todo en una sola transacción: si algo falla no queda nada a medias.
     *
     * @CC CCPacientes (guardar) @CE CEPaciente (crear), CEHistoriaClinica (crearParaPaciente), CEAntecedente (crear)
     *
     * @param  array<string, mixed>  $datos  campos de PacienteRequest
     *
     * @throws ValidationException si el CI ya está registrado
     */
    public function guardar(array $datos, Usuario $actor): Paciente
    {
        try {
            return DB::transaction(function () use ($datos, $actor) {
                $paciente = Paciente::create($this->campos($datos) + ['codigo' => Codigos::paciente(), 'estado' => true]);

                // Una sola historia clínica por paciente (paciente_id es UNIQUE en la tabla).
                $historia = HistoriaClinica::create(['paciente_id' => $paciente->id]);
                $antecedente = $this->guardarAntecedente($historia, $datos, $actor);

                BitacoraService::registrar(
                    'PACIENTE_CREADO', 'Paciente',
                    "Paciente registrado: {$paciente->codigo} {$paciente->nombre_completo} (CI {$paciente->ci}); historia clínica creada"
                    .($antecedente ? "; antecedente de {$antecedente->hospital_origen}" : ''),
                    $actor->id
                );

                return $paciente;
            });
        } catch (UniqueConstraintViolationException) {
            // Dos personas registrando el mismo CI a la vez: la restricción UNIQUE de la base lo rechaza.
            throw ValidationException::withMessages(['ci' => self::MENSAJE_CI_REPETIDO]);
        }
    }

    /**
     * Actualiza los datos del paciente y, si se indicó, agrega un antecedente de otro hospital.
     *
     * @CC CCPacientes (actualizar) @CE CEPaciente (actualizar), CEAntecedente (crear)
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws ValidationException si el CI pertenece a otro paciente
     */
    public function actualizar(Paciente $paciente, array $datos, Usuario $actor): Paciente
    {
        try {
            DB::transaction(function () use ($paciente, $datos, $actor) {
                $paciente->update($this->campos($datos));

                // La historia clínica se crea al registrar; si por algún motivo faltara, se completa aquí.
                $historia = $paciente->historiaClinica ?? HistoriaClinica::create(['paciente_id' => $paciente->id]);
                $antecedente = $this->guardarAntecedente($historia, $datos, $actor);

                BitacoraService::registrar(
                    'PACIENTE_EDITADO', 'Paciente',
                    "Paciente actualizado: {$paciente->codigo} {$paciente->nombre_completo}".($antecedente ? "; antecedente agregado de {$antecedente->hospital_origen}" : ''),
                    $actor->id
                );
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['ci' => self::MENSAJE_CI_REPETIDO]);
        }

        return $paciente->refresh();
    }

    /**
     * Desactiva: se conserva todo su historial de citas y su historia clínica.
     *
     * @CC CCPacientes @CE CEPaciente (actualizar)
     */
    public function desactivar(Paciente $paciente, Usuario $actor): void
    {
        $paciente->update(['estado' => false]);
        BitacoraService::registrar('PACIENTE_DESACTIVADO', 'Paciente', "Paciente desactivado: {$paciente->codigo} {$paciente->nombre_completo}", $actor->id);
    }

    /** @CC CCPacientes @CE CEPaciente (actualizar) */
    public function activar(Paciente $paciente, Usuario $actor): void
    {
        $paciente->update(['estado' => true]);
        BitacoraService::registrar('PACIENTE_ACTIVADO', 'Paciente', "Paciente activado: {$paciente->codigo} {$paciente->nombre_completo}", $actor->id);
    }

    /** Campos de la tabla pacientes (sin código ni estado). */
    private function campos(array $datos): array
    {
        $texto = fn (string $clave): ?string => ($valor = trim((string) ($datos[$clave] ?? ''))) === '' ? null : $valor;

        return [
            'ci' => self::normalizarCI($datos['ci']),
            'nombres' => trim($datos['nombres']),
            'apellidos' => trim($datos['apellidos']),
            'fecha_nacimiento' => $datos['fecha_nacimiento'],
            'sexo' => $texto('sexo'),
            'telefono' => trim($datos['telefono']),
            'correo' => ($correo = $texto('correo')) === null ? null : mb_strtolower($correo),
            'direccion' => $texto('direccion'),
            'emergencia_nombre' => $texto('emergencia_nombre'),
            'emergencia_parentesco' => $texto('emergencia_parentesco'),
            'emergencia_telefono' => $texto('emergencia_telefono'),
            'recibe_recordatorios' => (bool) ($datos['recibe_recordatorios'] ?? false),
            'mostrar_contacto_medico' => (bool) ($datos['mostrar_contacto_medico'] ?? false),
        ];
    }

    /** Guarda el antecedente de otro hospital si se marcó «viene de otro hospital». */
    private function guardarAntecedente(HistoriaClinica $historia, array $datos, Usuario $actor): ?AntecedenteExterno
    {
        if (! ($datos['con_antecedente'] ?? false)) {
            return null;
        }

        $texto = fn (string $clave): ?string => ($valor = trim((string) ($datos[$clave] ?? ''))) === '' ? null : $valor;

        return AntecedenteExterno::create([
            'historia_clinica_id' => $historia->id,
            'hospital_origen' => trim($datos['ant_hospital_origen']),
            'fecha_atencion' => $texto('ant_fecha_atencion'),
            'especialidad' => $texto('ant_especialidad'),
            'diagnostico' => trim($datos['ant_diagnostico']),
            'tratamiento' => $texto('ant_tratamiento'),
            'observaciones' => $texto('ant_observaciones'),
            'registrado_por' => $actor->id,
        ]);
    }
}
