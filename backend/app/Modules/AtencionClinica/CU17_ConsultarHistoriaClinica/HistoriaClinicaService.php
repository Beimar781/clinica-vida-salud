<?php

namespace App\Modules\AtencionClinica\CU17_ConsultarHistoriaClinica;

use App\Models\AntecedenteExterno;
use App\Models\Consulta;
use App\Models\HistoriaClinica;
use App\Models\Paciente;
use App\Models\Usuario;
use App\Services\BitacoraService;
use App\Support\Texto;
use Illuminate\Database\Eloquent\Collection;

/**
 * Consulta de la historia clínica de un paciente en modo de SOLO LECTURA: sus datos generales (tipo de sangre, alergias,
 * enfermedades crónicas, medicamentos y antecedentes), las consultas registradas en la clínica ordenadas por fecha (con el médico,
 * el diagnóstico y el tratamiento) y los antecedentes de otros hospitales. La búsqueda es por CI o nombre (el médico puede
 * consultar a cualquier paciente: necesita ver los antecedentes de pacientes derivados). Cada consulta de una historia queda en la
 * bitácora (quién y cuándo) sin guardar datos clínicos. Nada de lo que hace este servicio modifica la historia.
 *
 * @CU CU-17 @RF RF17 @CI CIHistoriaClinica @CC CCHistoriaClinica @CE CEHistoriaClinica (obtenerPorPaciente), CEConsulta (consultasDeHistoria), CEAntecedente (antecedentesDeHistoria)
 */
class HistoriaClinicaService
{
    public const MAX_RESULTADOS = 15;

    public const MENSAJE_SIN_CONSULTAS = 'No hay consultas registradas';

    public const MENSAJE_SIN_ANTECEDENTES = 'Sin antecedentes de otros hospitales.';

    /**
     * Busca pacientes por CI o nombre (sin distinguir mayúsculas ni tildes), incluidos los inactivos: su historia se conserva.
     *
     * @CC CCHistoriaClinica (buscar) @CE CEPaciente (buscar)
     *
     * @return Collection<int, Paciente>
     */
    public function buscar(?string $buscar): Collection
    {
        $texto = Texto::normalizar((string) $buscar);
        if ($texto === '') {
            return new Collection;
        }

        $patron = Texto::patronLike($texto);
        $sinTildes = fn (string $columna) => Texto::sqlSinTildes($columna);

        return Paciente::where(fn ($q) => $q
            ->whereRaw($sinTildes('ci').' like ?', [$patron])
            ->orWhereRaw($sinTildes('nombres').' like ?', [$patron])
            ->orWhereRaw($sinTildes('apellidos').' like ?', [$patron])
            ->orWhereRaw($sinTildes("nombres || ' ' || apellidos").' like ?', [$patron]))
            ->orderBy('apellidos')->orderBy('nombres')->limit(self::MAX_RESULTADOS)->get();
    }

    /**
     * Reúne la historia del paciente (datos generales, consultas por fecha y antecedentes externos) y deja constancia en la bitácora.
     * No crea ni modifica nada: un paciente sin historia abierta se muestra con los datos vacíos.
     *
     * @CC CCHistoriaClinica (consultar) @CE CEHistoriaClinica (obtenerPorPaciente), CEConsulta (consultasDeHistoria), CEAntecedente (antecedentesDeHistoria)
     *
     * @return array{historia: ?HistoriaClinica, consultas: Collection, antecedentes: Collection}
     */
    public function consultar(Paciente $paciente, Usuario $medico): array
    {
        $historia = $paciente->historiaClinica;

        BitacoraService::registrar('HISTORIA_CONSULTADA', 'HistoriaClinica', "Historia clínica del paciente {$paciente->codigo} consultada", $medico->id);

        return [
            'historia' => $historia,
            'consultas' => $historia ? Consulta::consultasDeHistoria($historia->id) : new Collection,
            'antecedentes' => $historia ? $this->antecedentes($historia) : new Collection,
        ];
    }

    /**
     * Antecedentes de otros hospitales o clínicas, los más recientes primero (los que no tienen fecha, al final).
     *
     * @CE CEAntecedente (antecedentesDeHistoria)
     *
     * @return Collection<int, AntecedenteExterno>
     */
    public function antecedentes(HistoriaClinica $historia): Collection
    {
        return AntecedenteExterno::where('historia_clinica_id', $historia->id)
            ->orderByRaw('fecha_atencion desc nulls last')->orderByDesc('id')->get();
    }
}
