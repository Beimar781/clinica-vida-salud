<?php

namespace App\Modules\GestionCitas\CU14_ConsultarAgendaMedica;

use App\Models\Cita;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\GestionCitas\Compartido\Disponibilidad;
use App\Modules\GestionCitas\Compartido\DisponibilidadService;
use App\Modules\GestionCitas\Compartido\TransicionesCita;
use App\Modules\GestionPacientes\CU15_ConsultarHistorialCitasPaciente\HistorialCitasService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Agenda de un médico en una fecha o rango de fechas: sus citas ordenadas por hora (con paciente, especialidad, estado y motivo) y,
 * si se consulta un solo día, sus horarios libres (el mismo cálculo de CU-08). El médico ve solo su propia agenda; la recepcionista y
 * el administrador eligen el médico. Al abrir una cita se muestran los datos básicos del paciente y, si el paciente lo permite, su
 * teléfono y correo (la privacidad `mostrar_contacto_medico` se aplica al rol Médico). Solo consulta: no modifica datos.
 *
 * @CU CU-14 @RF RF14 @CI CIAgenda @CC CCAgenda @CE CECita (citasPorPeriodo), CEMedico (horariosLibres)
 */
class AgendaService
{
    /** Máximo de días de un rango (inclusive). */
    public const MAX_DIAS = 31;

    public const MENSAJE_SIN_CITAS = 'No hay citas para la fecha seleccionada';

    public const MENSAJE_RANGO_INVALIDO = 'El rango de fechas no es válido: la fecha final debe ser igual o posterior a la inicial.';

    public const MENSAJE_RANGO_LARGO = 'El rango de fechas no puede superar los 31 días.';

    public const MENSAJE_SIN_FICHA_MEDICO = 'Tu cuenta no tiene una ficha de médico asociada. Pide al administrador que la vincule en «Médicos».';

    public function __construct(private readonly DisponibilidadService $disponibilidad) {}

    /** ¿Elige el médico (recepción y administrador) o ve solo su propia agenda (médico)? */
    public function veTodos(Usuario $usuario): bool
    {
        return $usuario->tieneRol(Rol::RECEPCIONISTA);
    }

    /** Ficha del médico de la cuenta (null si no es médico o no tiene ficha de médico). */
    public function medicoPropio(Usuario $usuario): ?Medico
    {
        return TransicionesCita::medicoDe($usuario);
    }

    /**
     * Médicos entre los que se elige. El médico solo se tiene a sí mismo.
     *
     * @CC CCAgenda (filtrar)
     *
     * @return Collection<int, Medico>
     */
    public function medicosElegibles(Usuario $usuario): Collection
    {
        if ($this->veTodos($usuario)) {
            return Medico::with('especialidades')->orderByDesc('estado')->orderBy('apellidos')->orderBy('nombres')->get();
        }

        $propio = $this->medicoPropio($usuario);

        return new Collection($propio ? [$propio->load('especialidades')] : []);
    }

    /**
     * El médico cuya agenda se consulta: el pedido (recepción y administrador) o siempre el propio (médico, aunque pida otro).
     *
     * @CC CCAgenda (consultar)
     */
    public function medicoConsultado(Usuario $usuario, ?int $pedido): ?Medico
    {
        if ($this->veTodos($usuario)) {
            return $pedido ? Medico::find($pedido) : null;
        }

        return $this->medicoPropio($usuario);
    }

    /** Error del rango (fin anterior al inicio o más de 31 días) o null si es válido. Con un rango inválido no se consulta. */
    public function errorDeRango(string $desde, ?string $hasta): ?string
    {
        if (blank($hasta)) {
            return null;
        }
        if ($hasta < $desde) {
            return self::MENSAJE_RANGO_INVALIDO;
        }
        if (Carbon::parse($desde)->diffInDays(Carbon::parse($hasta)) + 1 > self::MAX_DIAS) {
            return self::MENSAJE_RANGO_LARGO;
        }

        return null;
    }

    /**
     * Citas del médico en el período, ordenadas por fecha y hora, con filtro opcional de estado (los mismos de CU-15).
     *
     * @CC CCAgenda (consultar, filtrar) @CE CECita (citasPorPeriodo)
     *
     * @return Collection<int, Cita>
     */
    public function citas(Medico $medico, string $desde, ?string $hasta, ?string $filtro = null): Collection
    {
        return Cita::with(['paciente', 'especialidad', 'consulta'])
            ->where('medico_id', $medico->id)
            ->whereDate('fecha', '>=', $desde)->whereDate('fecha', '<=', filled($hasta) ? $hasta : $desde)
            ->when(array_key_exists((string) $filtro, HistorialCitasService::FILTROS), fn ($q) => $q->whereIn('estado', HistorialCitasService::FILTROS[$filtro][1]))
            ->orderBy('fecha')->orderBy('hora_inicio')->orderBy('id')
            ->get();
    }

    /**
     * Horarios libres del médico en un solo día (incluye CU-08). Con un rango no se muestran.
     *
     * @CC CCAgenda (consultar) @CE CEMedico (horariosLibres)
     */
    public function libres(Medico $medico, string $fecha): Disponibilidad
    {
        return $this->disponibilidad->calcularLibres($medico, $fecha);
    }

    /**
     * Cantidad de citas pendientes (programadas y confirmadas), atendidas, canceladas y con inasistencia.
     *
     * @param  Collection<int, Cita>  $citas
     * @return array{pendientes: int, atendidas: int, canceladas: int, inasistencias: int}
     */
    public function resumen(Collection $citas): array
    {
        return [
            'pendientes' => $citas->whereIn('estado', [Cita::PROGRAMADA, Cita::CONFIRMADA])->count(),
            'atendidas' => $citas->where('estado', Cita::ATENDIDA)->count(),
            'canceladas' => $citas->where('estado', Cita::CANCELADA)->count(),
            'inasistencias' => $citas->where('estado', Cita::INASISTENCIA)->count(),
        ];
    }

    /**
     * ¿El usuario tiene permiso para ver el motivo de la cita? Lo ven la recepción y el administrador y el médico de esa cita; quien
     * no tenga permiso ve el resto de los datos sin el motivo.
     *
     * @CC CCAgenda (consultar)
     */
    public function puedeVerMotivo(Usuario $usuario, Cita $cita): bool
    {
        if ($usuario->tieneRol(Rol::RECEPCIONISTA)) {
            return true;
        }

        $propio = $this->medicoPropio($usuario);

        return $propio !== null && $propio->id === $cita->medico_id;
    }

    /**
     * ¿Se muestran el teléfono y el correo del paciente? Siempre para recepción y administrador; el médico solo si el paciente no
     * activó la privacidad (`mostrar_contacto_medico`).
     */
    public function puedeVerContacto(Usuario $usuario, Paciente $paciente): bool
    {
        return $usuario->tieneRol(Rol::RECEPCIONISTA) || $paciente->mostrar_contacto_medico;
    }

    /** Edad en años cumplidos del paciente. */
    public static function edad(Paciente $paciente): int
    {
        return (int) $paciente->fecha_nacimiento->age;
    }

    public static function textoSexo(?string $sexo): string
    {
        return ['M' => 'Masculino', 'F' => 'Femenino'][$sexo] ?? 'No indicado';
    }
}
