<?php

namespace App\Modules\GestionCitas\CU13_ControlarAsistenciaEstadosCita;

use App\Models\Cita;
use App\Models\CitaEvento;
use App\Models\Medico;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\GestionCitas\Compartido\AgendarCitaService;
use App\Modules\GestionCitas\Compartido\HorarioOcupadoException;
use App\Modules\GestionCitas\Compartido\ReglaCitaException;
use App\Modules\GestionCitas\Compartido\TransicionesCita;
use App\Services\BitacoraService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Control de la asistencia y de los estados de las citas del día. La recepcionista confirma, registra el cobro en efectivo y la
 * inasistencia; el médico marca «atendida» solo en sus citas y si tiene el permiso; el administrador corrige un estado con una
 * justificación. Las reglas de quién puede qué están en Compartido/TransicionesCita; aquí se listan las citas, se aplica el
 * cambio dentro de una transacción (con la cita bloqueada) y se deja en la bitácora quién lo hizo y cuándo (la tabla citas no
 * tiene columnas para eso).
 *
 * @CU CU-13 @RF RF13 @CI CIEstadoCita @CC CCEstadoCita @CE CECita (citasDelDia, obtener, cambiarEstado)
 */
class EstadoCitaService
{
    /** Filtros de estado de la pantalla. */
    public const ESTADOS = [
        Cita::PROGRAMADA => 'Programada', Cita::CONFIRMADA => 'Confirmada', Cita::ATENDIDA => 'Atendida',
        Cita::CANCELADA => 'Cancelada', Cita::INASISTENCIA => 'Inasistencia',
    ];

    public const MENSAJE_SIN_FICHA_MEDICO = 'Tu cuenta no tiene una ficha de médico asociada. Pide al administrador que la vincule en «Médicos».';

    public function __construct(
        private readonly TransicionesCita $transiciones,
        private readonly AgendarCitaService $agendar,
    ) {}

    /** ¿El usuario ve las citas de todos los médicos (recepción y administrador) o solo las suyas (médico)? */
    public function veTodas(Usuario $usuario): bool
    {
        return $usuario->tieneRol(Rol::RECEPCIONISTA);
    }

    /** La ficha de médico del usuario (para el médico que no es personal de recepción). */
    public function medicoDe(Usuario $usuario): ?Medico
    {
        return TransicionesCita::medicoDe($usuario);
    }

    /**
     * Citas de la fecha, ordenadas por hora, con filtro opcional de estado y de médico. El médico (que no es de recepción) solo
     * ve las suyas, sin importar lo que pida.
     *
     * @CC CCEstadoCita (listar) @CE CECita (citasDelDia)
     *
     * @return Collection<int, Cita>
     */
    public function listar(Usuario $usuario, string $fecha, ?string $estado = null, ?int $medicoId = null): Collection
    {
        $consulta = Cita::with(['paciente', 'medico', 'especialidad'])
            ->whereDate('fecha', $fecha)
            ->when(array_key_exists((string) $estado, self::ESTADOS), fn ($q) => $q->where('estado', $estado))
            ->orderBy('hora_inicio')->orderBy('id');

        if ($this->veTodas($usuario)) {
            $consulta->when($medicoId, fn ($q) => $q->where('medico_id', $medicoId));
        } else {
            $propio = $this->medicoDe($usuario);
            $consulta->where('medico_id', $propio?->id ?? 0);
        }

        return $consulta->get();
    }

    /**
     * Acciones que se ofrecen para la cita a este usuario (las que TransicionesCita acepta ahora), en el orden de la pantalla.
     *
     * @CC CCEstadoCita (validarTransicion)
     *
     * @return list<string>
     */
    public function acciones(Cita $cita, Usuario $usuario, ?Carbon $ahora = null): array
    {
        return array_values(array_filter(
            TransicionesCita::ACCIONES,
            fn (string $accion) => $this->transiciones->disponible($cita, $accion, $usuario, $ahora)
        ));
    }

    /** ¿Se muestra la inasistencia deshabilitada («aún no es la hora»)? Para la cita confirmada que todavía no empezó. */
    public function inasistenciaPendienteDeHora(Cita $cita, Usuario $usuario, ?Carbon $ahora = null): bool
    {
        return $cita->estado === Cita::CONFIRMADA
            && $usuario->tieneRol(Rol::RECEPCIONISTA)
            && ($ahora ?? Carbon::now())->lt($cita->inicio);
    }

    /**
     * Aplica la acción a la cita dentro de una transacción con la cita bloqueada: valida la transición para el rol y el estado
     * actual, actualiza el estado (o el pago) y registra en la bitácora quién lo hizo.
     *
     * @CC CCEstadoCita (validarTransicion, cambiarEstado) @CE CECita (cambiarEstado)
     *
     * @param  array{estado_nuevo?: ?string, justificacion?: ?string}  $datos
     * @return array{cita: Cita, mensaje: string, reprogramar: bool}
     *
     * @throws ReglaCitaException la transición no está permitida (se informa el motivo)
     * @throws HorarioOcupadoException al restablecer una cita cuyo horario ya fue ocupado
     */
    public function aplicar(Cita $cita, string $accion, Usuario $usuario, array $datos = []): array
    {
        try {
            return DB::transaction(function () use ($cita, $accion, $usuario, $datos) {
                $actual = Cita::with(['paciente', 'medico', 'especialidad'])->whereKey($cita->id)->lockForUpdate()->firstOrFail();

                $this->transiciones->validarTransicion($actual, $accion, $usuario);

                return match ($accion) {
                    TransicionesCita::CONFIRMAR => $this->confirmar($actual, $usuario),
                    TransicionesCita::COBRAR => $this->cobrar($actual, $usuario),
                    TransicionesCita::ATENDIDA => $this->marcarAtendida($actual, $usuario),
                    TransicionesCita::INASISTENCIA => $this->registrarInasistencia($actual, $usuario),
                    TransicionesCita::CORREGIR => $this->corregir($actual, $usuario, (string) ($datos['estado_nuevo'] ?? ''), trim((string) ($datos['justificacion'] ?? ''))),
                };
            });
        } catch (QueryException $e) {
            // 23P01: las restricciones EXCLUDE rechazaron que la cita vuelva a ocupar un horario ya tomado.
            if ($e->getCode() === '23P01') {
                throw new HorarioOcupadoException('El horario de esta cita ya fue ocupado por otra cita: no se puede restablecer.');
            }

            throw $e;
        }
    }

    private function confirmar(Cita $cita, Usuario $usuario): array
    {
        $this->transiciones->cambiarEstado($cita, Cita::CONFIRMADA);
        CitaEvento::registrar($cita, CitaEvento::CONFIRMADA, $usuario->id, ['estado_anterior' => Cita::PROGRAMADA, 'estado_nuevo' => Cita::CONFIRMADA]);
        BitacoraService::registrar('CITA_CONFIRMADA', 'Cita', "Cita {$cita->codigo} confirmada (antes Programada)", $usuario->id);

        return $this->resultado($cita, "Cita {$cita->codigo} confirmada.");
    }

    private function cobrar(Cita $cita, Usuario $usuario): array
    {
        $cita->update(['estado_pago' => Cita::PAGO_PAGADO, 'fecha_pago' => now()]);
        BitacoraService::registrar(
            'COBRO_REGISTRADO', 'Cita',
            "Cobro en efectivo de {$cita->monto_texto} de la cita {$cita->codigo} registrado por {$usuario->nombre_usuario}",
            $usuario->id
        );

        return $this->resultado($cita, "Cobro de {$cita->monto_texto} registrado en la cita {$cita->codigo}: el pago pasó a Pagado.");
    }

    private function marcarAtendida(Cita $cita, Usuario $usuario): array
    {
        $sinCobro = $cita->cobro_pendiente;
        $antes = $cita->estado;
        $this->transiciones->cambiarEstado($cita, Cita::ATENDIDA);
        CitaEvento::registrar($cita, CitaEvento::ATENDIDA, $usuario->id, ['estado_anterior' => $antes, 'estado_nuevo' => Cita::ATENDIDA]);
        BitacoraService::registrar(
            'CITA_ATENDIDA', 'Cita',
            "Cita {$cita->codigo} marcada como atendida por el médico {$cita->medico->codigo}".($sinCobro ? ' (con el pago pendiente: falta registrar el cobro)' : ''),
            $usuario->id
        );

        return $this->resultado($cita, "Cita {$cita->codigo} marcada como atendida.".($sinCobro ? " Atención: el pago de {$cita->monto_texto} sigue pendiente; recepción debe registrar el cobro en «Control de citas»." : ''));
    }

    private function registrarInasistencia(Cita $cita, Usuario $usuario): array
    {
        $pagada = $cita->estaPagada();
        $antes = $cita->estado;
        $this->transiciones->cambiarEstado($cita, Cita::INASISTENCIA);
        CitaEvento::registrar($cita, CitaEvento::INASISTENCIA, $usuario->id, ['estado_anterior' => $antes, 'estado_nuevo' => Cita::INASISTENCIA]);
        BitacoraService::registrar(
            'INASISTENCIA_REGISTRADA', 'Cita',
            "Inasistencia registrada en la cita {$cita->codigo}".($pagada ? ' (pago conservado: puede reprogramarse)' : ' (sin pago)'),
            $usuario->id
        );

        return $this->resultado(
            $cita,
            "Inasistencia registrada en la cita {$cita->codigo}.".($pagada ? ' El pago se conserva: la cita puede reprogramarse sin un nuevo cobro (CU-10).' : ''),
            reprogramar: $pagada
        );
    }

    /**
     * Corrección del administrador: cualquier estado distinto del actual, con justificación obligatoria. Si la cita estaba
     * cancelada y vuelve a ocupar horario, se verifica antes que el horario siga libre.
     */
    private function corregir(Cita $cita, Usuario $usuario, string $nuevo, string $justificacion): array
    {
        if (! array_key_exists($nuevo, self::ESTADOS)) {
            throw new ReglaCitaException('Elija el nuevo estado de la cita.');
        }
        if ($nuevo === $cita->estado) {
            throw new ReglaCitaException('La cita ya está '.TransicionesCita::legible($cita->estado).': elija un estado distinto.');
        }
        if ($justificacion === '') {
            throw new ReglaCitaException('Ingrese la justificación de la corrección: es obligatoria y queda en la bitácora.');
        }
        if (mb_strlen($justificacion) > 150) {
            throw new ReglaCitaException('La justificación no puede superar los 150 caracteres.');
        }

        $antes = $cita->estado;

        if ($antes === Cita::CANCELADA && $nuevo !== Cita::CANCELADA) {
            $this->agendar->verificarEspacioDeCita($cita);
        }

        $extra = ['motivo_cancelacion' => $nuevo === Cita::CANCELADA ? 'Corrección del administrador: '.$justificacion : null];
        if ($nuevo === Cita::PROGRAMADA) {
            $extra['recordatorio_enviado'] = false; // vuelve a recibir su recordatorio
        }
        $this->transiciones->cambiarEstado($cita, $nuevo, $extra);
        CitaEvento::registrar($cita, CitaEvento::ESTADO_CORREGIDO, $usuario->id, ['estado_anterior' => $antes, 'estado_nuevo' => $nuevo, 'motivo' => $justificacion]);

        BitacoraService::registrar(
            'ESTADO_CORREGIDO', 'Cita',
            "Estado de la cita {$cita->codigo} corregido: {$antes} → {$nuevo}. Justificación: {$justificacion}",
            $usuario->id
        );

        return $this->resultado($cita, "Estado de la cita {$cita->codigo} corregido: de ".TransicionesCita::legible($antes).' a '.TransicionesCita::legible($nuevo).'. La justificación quedó en la bitácora.');
    }

    /** @return array{cita: Cita, mensaje: string, reprogramar: bool} */
    private function resultado(Cita $cita, string $mensaje, bool $reprogramar = false): array
    {
        return ['cita' => $cita->refresh(), 'mensaje' => $mensaje, 'reprogramar' => $reprogramar];
    }
}
