<?php

namespace App\Modules\GestionPacientes\CU15_ConsultarHistorialCitasPaciente;

use App\Models\Cita;
use App\Models\Paciente;
use App\Models\Usuario;
use App\Support\Texto;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Historial de citas de un paciente: todas sus citas a lo largo del tiempo (fecha y hora, médico, especialidad, estado, fecha de
 * registro y pago), con filtros por estado y por rango de fechas. La recepcionista y el administrador buscan al paciente por CI o
 * nombre (también los inactivos: su historial se conserva); el paciente ve directamente el suyo y nunca el de otro. Es la pantalla
 * «Mis citas» del paciente. Solo consulta: no modifica datos.
 *
 * @CU CU-15 @RF RF15 @CI CIHistorial @CC CCHistorial @CE CEPaciente (buscar), CECita (citasDelPaciente)
 */
class HistorialCitasService
{
    public const POR_PAGINA = 15;

    public const MAX_RESULTADOS = 15;

    public const MENSAJE_SIN_CITAS = 'El paciente aún no tiene citas.';

    public const MENSAJE_RANGO_INVALIDO = 'El rango de fechas no es válido: la fecha final debe ser igual o posterior a la inicial.';

    /** Filtros de estado: clave de la URL => texto y estados que incluye. */
    public const FILTROS = [
        'pendientes' => ['Pendientes (programadas y confirmadas)', [Cita::PROGRAMADA, Cita::CONFIRMADA]],
        'confirmadas' => ['Confirmadas', [Cita::CONFIRMADA]],
        'atendidas' => ['Atendidas', [Cita::ATENDIDA]],
        'canceladas' => ['Canceladas', [Cita::CANCELADA]],
        'inasistencias' => ['Inasistencias', [Cita::INASISTENCIA]],
    ];

    /** El personal (recepción y administrador) busca pacientes; el paciente solo ve el suyo. */
    public function esPersonal(Usuario $usuario): bool
    {
        return $usuario->tieneRol('Recepcionista');
    }

    /**
     * Busca pacientes por CI o nombre (sin distinguir mayúsculas ni tildes), incluidos los inactivos.
     *
     * @CC CCHistorial (buscar) @CE CEPaciente (buscar)
     *
     * @return Collection<int, Paciente>
     */
    public function buscarPacientes(?string $buscar): Collection
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

    /** La ficha de paciente de la cuenta (null si todavía no tiene). */
    public function pacienteDe(Usuario $usuario): ?Paciente
    {
        return $usuario->paciente;
    }

    /**
     * Error del rango de fechas (fin anterior al inicio) o null si es válido o está incompleto. Con un rango inválido no se consulta.
     */
    public function errorDeRango(?string $desde, ?string $hasta): ?string
    {
        return filled($desde) && filled($hasta) && $hasta < $desde ? self::MENSAJE_RANGO_INVALIDO : null;
    }

    /**
     * Citas del paciente, de la más reciente a la más antigua, con filtros opcionales de estado y de rango de fechas.
     *
     * @CC CCHistorial (consultar, filtrar) @CE CECita (citasDelPaciente)
     */
    public function citas(Paciente $paciente, ?string $filtro = null, ?string $desde = null, ?string $hasta = null): LengthAwarePaginator
    {
        return Cita::with(['medico', 'especialidad'])
            ->where('paciente_id', $paciente->id)
            ->when(array_key_exists((string) $filtro, self::FILTROS), fn ($q) => $q->whereIn('estado', self::FILTROS[$filtro][1]))
            ->when(filled($desde), fn ($q) => $q->whereDate('fecha', '>=', $desde))
            ->when(filled($hasta), fn ($q) => $q->whereDate('fecha', '<=', $hasta))
            ->orderByDesc('fecha')->orderByDesc('hora_inicio')->orderByDesc('id')
            ->paginate(self::POR_PAGINA)->withQueryString();
    }

    /** Opciones del filtro para la lista desplegable. */
    public static function opcionesFiltro(): array
    {
        return array_map(fn (array $f) => $f[0], self::FILTROS);
    }
}
