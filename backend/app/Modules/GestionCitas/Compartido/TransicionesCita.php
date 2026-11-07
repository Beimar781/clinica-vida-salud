<?php

namespace App\Modules\GestionCitas\Compartido;

use App\Models\Cita;
use App\Models\Medico;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Support\Carbon;

/**
 * Reglas del ciclo de vida de una cita (diagrama de estados 6.2.3), compartidas por CU-12 (cancelar), CU-13 (controlar
 * asistencia y estados) y, más adelante, CU-16 (registrar consulta). Un solo lugar decide qué cambio de estado se permite
 * y a quién:
 *
 *   PROGRAMADA  -> CONFIRMADA | CANCELADA
 *   CONFIRMADA  -> ATENDIDA | INASISTENCIA | CANCELADA
 *   INASISTENCIA pagada -> PROGRAMADA (solo por CU-10, reprogramar)
 *   ATENDIDA y CANCELADA no cambian (solo el Administrador las corrige, con justificación y registro en la bitácora).
 *
 * Acciones de CU-13 y quién las hace: confirmar, cobrar (efectivo) e inasistencia: Recepcionista (y el Administrador, que
 * hereda recepción); atendida: el Médico de la cita con el permiso «puede marcar citas como atendidas»; corregir: solo el
 * Administrador, con justificación.
 *
 * @CU CU-12, CU-13 @RF RF12, RF13 @CC CCCancelarCita (verificarPermiso), CCEstadoCita (validarTransicion, cambiarEstado) @CE CECita (cambiarEstado)
 */
class TransicionesCita
{
    public const CONFIRMAR = 'confirmar';

    public const COBRAR = 'cobrar';

    public const ATENDIDA = 'atendida';

    public const INASISTENCIA = 'inasistencia';

    public const CORREGIR = 'corregir';

    public const ACCIONES = [self::CONFIRMAR, self::COBRAR, self::ATENDIDA, self::INASISTENCIA, self::CORREGIR];

    /** Texto de cada acción para botones y avisos. */
    public const TEXTOS = [
        self::CONFIRMAR => 'Confirmar',
        self::COBRAR => 'Registrar cobro',
        self::ATENDIDA => 'Marcar atendida',
        self::INASISTENCIA => 'Registrar inasistencia',
        self::CORREGIR => 'Corregir estado',
    ];

    /** Transiciones permitidas entre estados (sin contar CU-10, que devuelve la inasistencia pagada a PROGRAMADA). */
    private const PERMITIDAS = [
        Cita::PROGRAMADA => [Cita::CONFIRMADA, Cita::CANCELADA],
        Cita::CONFIRMADA => [Cita::ATENDIDA, Cita::INASISTENCIA, Cita::CANCELADA],
        Cita::ATENDIDA => [],
        Cita::CANCELADA => [],
        Cita::INASISTENCIA => [],
    ];

    /** Estado legible para los avisos («cancelada», «con inasistencia»). */
    private const LEGIBLES = [
        Cita::PROGRAMADA => 'programada', Cita::CONFIRMADA => 'confirmada', Cita::ATENDIDA => 'atendida',
        Cita::CANCELADA => 'cancelada', Cita::INASISTENCIA => 'con inasistencia',
    ];

    /** ¿Se permite pasar de un estado a otro por las vías normales? */
    public function permitida(string $desde, string $hasta): bool
    {
        return in_array($hasta, self::PERMITIDAS[$desde] ?? [], true);
    }

    /** Los estados a los que puede pasar una cita en el estado dado. */
    public function destinos(string $estado): array
    {
        return self::PERMITIDAS[$estado] ?? [];
    }

    /** Estado que produce la acción (confirmar -> CONFIRMADA...); null para cobrar y corregir. */
    public function estadoDeLaAccion(string $accion): ?string
    {
        return [self::CONFIRMAR => Cita::CONFIRMADA, self::ATENDIDA => Cita::ATENDIDA, self::INASISTENCIA => Cita::INASISTENCIA][$accion] ?? null;
    }

    public static function legible(string $estado): string
    {
        return self::LEGIBLES[$estado] ?? mb_strtolower($estado);
    }

    /** ¿La cita puede cancelarse (CU-12)? Solo Programada o Confirmada. */
    public function puedeCancelarse(Cita $cita): bool
    {
        return $this->permitida($cita->estado, Cita::CANCELADA);
    }

    /** Mensaje cuando una cita no se puede cancelar (atendida, ya cancelada o con inasistencia). */
    public function motivoNoCancelable(Cita $cita): string
    {
        return 'La cita está '.self::legible($cita->estado).': no se puede cancelar.';
    }

    /**
     * Valida que el usuario pueda aplicar la acción a la cita en su estado actual. Lanza ReglaCitaException con el motivo
     * (seguro para mostrar). `$ahora` permite probar la regla «la inasistencia no se registra antes de la hora de la cita».
     *
     * @CC CCEstadoCita (validarTransicion)
     *
     * @throws ReglaCitaException
     */
    public function validarTransicion(Cita $cita, string $accion, Usuario $usuario, ?Carbon $ahora = null): void
    {
        if (! in_array($accion, self::ACCIONES, true)) {
            throw new ReglaCitaException('La acción solicitada no es válida.');
        }

        $this->validarRol($cita, $accion, $usuario);

        match ($accion) {
            self::CONFIRMAR => $this->exigirEstado($cita, [Cita::PROGRAMADA], 'confirmar'),
            self::ATENDIDA => $this->exigirEstado($cita, [Cita::CONFIRMADA], 'marcar como atendida'),
            self::INASISTENCIA => $this->validarInasistencia($cita, $ahora ?? Carbon::now()),
            self::COBRAR => $this->validarCobro($cita),
            self::CORREGIR => null,
        };
    }

    /** ¿Se ofrece la acción en pantalla? (la misma validación, sin lanzar el error). */
    public function disponible(Cita $cita, string $accion, Usuario $usuario, ?Carbon $ahora = null): bool
    {
        try {
            $this->validarTransicion($cita, $accion, $usuario, $ahora);

            return true;
        } catch (ReglaCitaException) {
            return false;
        }
    }

    /**
     * Aplica el nuevo estado a la cita (sin comprobar roles: ya se validó). Es el único lugar que escribe el estado en
     * CU-12, CU-13 y CU-16. `$extra` agrega otros campos (por ejemplo, motivo_cancelacion).
     *
     * @CC CCEstadoCita (cambiarEstado) @CE CECita (cambiarEstado)
     *
     * @param  array<string, mixed>  $extra
     */
    public function cambiarEstado(Cita $cita, string $nuevo, array $extra = []): Cita
    {
        $cita->update(['estado' => $nuevo] + $extra);

        return $cita;
    }

    /** El Médico de la cita (por su cuenta), o null si el usuario no es médico o no tiene ficha de médico. */
    public static function medicoDe(Usuario $usuario): ?Medico
    {
        return $usuario->nombresRoles()->contains(Rol::MEDICO) ? Medico::where('usuario_id', $usuario->id)->first() : null;
    }

    private function validarRol(Cita $cita, string $accion, Usuario $usuario): void
    {
        $esMedico = $usuario->nombresRoles()->contains(Rol::MEDICO);
        $esRecepcion = $usuario->tieneRol(Rol::RECEPCIONISTA); // el Administrador hereda recepción
        $esAdmin = $usuario->nombresRoles()->contains(Rol::ADMINISTRADOR);

        switch ($accion) {
            case self::CORREGIR:
                if (! $esAdmin) {
                    throw new ReglaCitaException('Solo el administrador puede corregir el estado de una cita.');
                }
                break;

            case self::ATENDIDA:
                if (! $esMedico) {
                    throw new ReglaCitaException('Solo el médico de la cita puede marcarla como atendida.');
                }
                $medico = self::medicoDe($usuario);
                if (! $medico || $medico->id !== $cita->medico_id) {
                    throw new ReglaCitaException('Solo puede cambiar el estado de sus propias citas.');
                }
                if (! $medico->puede_marcar_atendida) {
                    throw new ReglaCitaException('No tiene asignado el permiso de marcar citas como atendidas.');
                }
                break;

            default: // confirmar, cobrar e inasistencia: recepción (y administrador)
                if (! $esRecepcion) {
                    throw new ReglaCitaException('Su rol no permite esta acción: la realiza la recepción.');
                }
        }
    }

    /** @param  list<string>  $estados  estados desde los que se permite la acción */
    private function exigirEstado(Cita $cita, array $estados, string $verbo): void
    {
        if (in_array($cita->estado, $estados, true)) {
            return;
        }

        $detalle = match ($cita->estado) {
            Cita::CANCELADA, Cita::ATENDIDA => ' Las citas '.($cita->estado === Cita::CANCELADA ? 'canceladas' : 'atendidas').' no cambian de estado (si hubo un error, el administrador puede corregirlo con una justificación).',
            Cita::PROGRAMADA => ' Primero hay que confirmarla.',
            Cita::INASISTENCIA => ' Para darle otra fecha se reprograma (CU-10).',
            default => '',
        };

        throw new ReglaCitaException('La cita está '.self::legible($cita->estado).': no se puede '.$verbo.'.'.$detalle);
    }

    private function validarInasistencia(Cita $cita, Carbon $ahora): void
    {
        $this->exigirEstado($cita, [Cita::CONFIRMADA], 'registrar la inasistencia');

        if ($ahora->lt($cita->inicio)) {
            throw new ReglaCitaException('No se puede registrar la inasistencia antes de la hora de la cita ('.$cita->inicio->format('d/m/Y H:i').').');
        }
    }

    private function validarCobro(Cita $cita): void
    {
        if ($cita->estado === Cita::CANCELADA) {
            throw new ReglaCitaException('La cita está cancelada: no se puede registrar un cobro.');
        }
        if ($cita->estado_pago === Cita::PAGO_PAGADO) {
            throw new ReglaCitaException('El pago de esta cita ya está registrado ('.$cita->metodo_pago_texto.').');
        }
        if ($cita->metodo_pago !== Cita::METODO_EFECTIVO) {
            throw new ReglaCitaException('Solo se registra el cobro de las citas con pago en efectivo.');
        }
        if (! in_array($cita->estado, [Cita::PROGRAMADA, Cita::CONFIRMADA, Cita::ATENDIDA], true)) {
            throw new ReglaCitaException('La cita está '.self::legible($cita->estado).': no se registra un cobro nuevo.');
        }
    }
}
