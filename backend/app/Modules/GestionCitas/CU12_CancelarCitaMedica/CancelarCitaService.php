<?php

namespace App\Modules\GestionCitas\CU12_CancelarCitaMedica;

use App\Models\Cita;
use App\Models\Usuario;
use App\Modules\GestionCitas\Compartido\AgendarCitaService;
use App\Modules\GestionCitas\Compartido\ReglaCitaException;
use App\Modules\GestionCitas\Compartido\TransicionesCita;
use App\Services\BitacoraService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cancelación de citas: la recepcionista (y el administrador, que hereda recepción) cancela cualquier cita Programada o
 * Confirmada; el paciente solo las suyas y con 2 horas o más de anticipación. El motivo es obligatorio y se guarda en
 * `motivo_cancelacion`; la cita deja de ocupar el horario porque las restricciones EXCLUDE ignoran las canceladas.
 * Si la cita está pagada el pago NO se devuelve (política del documento): se avisa, se ofrece reprogramar (CU-10) y la
 * cancelación solo se hace si la persona lo confirma.
 *
 * @CU CU-12 @RF RF12 @CI CICancelarCita @CC CCCancelarCita @CE CECita (obtener, cancelar)
 */
class CancelarCitaService
{
    /** Horas mínimas de anticipación para que el paciente cancele por su cuenta. */
    public const ANTICIPACION_HORAS = 2;

    public const MENSAJE_ANTICIPACION = 'No se puede cancelar con menos de 2 horas de anticipación. Comunícate con recepción.';

    public const MENSAJE_CONFIRMAR_PAGADA = 'La cita ya está pagada y el pago no se devuelve. Marque la casilla para confirmar que se cancela sin devolución, o reprograme la cita.';

    public function __construct(private readonly TransicionesCita $transiciones) {}

    /** ¿Quien cancela es personal de recepción (o administrador) y no solo un paciente? */
    public function esPersonal(Usuario $usuario): bool
    {
        return $usuario->tieneRol('Recepcionista');
    }

    /**
     * Quién puede abrir la cancelación de esta cita: el personal de recepción cualquiera; el paciente solo las suyas.
     *
     * @CC CCCancelarCita (verificarPermiso)
     *
     * @throws AuthorizationException
     */
    public function verificarPermiso(Cita $cita, Usuario $usuario): void
    {
        if ($this->esPersonal($usuario)) {
            return;
        }

        if ($cita->paciente?->usuario_id !== $usuario->id) {
            throw new AuthorizationException('Solo puede cancelar sus propias citas.');
        }
    }

    /** ¿La cita está en un estado que se puede cancelar (Programada o Confirmada)? */
    public function puedeCancelarse(Cita $cita): bool
    {
        return $this->transiciones->puedeCancelarse($cita);
    }

    public function motivoNoCancelable(Cita $cita): string
    {
        return $this->transiciones->motivoNoCancelable($cita);
    }

    /** ¿Faltan 2 horas o más para la cita? (regla del paciente) */
    public function hayAnticipacion(Cita $cita, ?Carbon $ahora = null): bool
    {
        return ($ahora ?? Carbon::now())->lte($cita->inicio->copy()->subHours(self::ANTICIPACION_HORAS));
    }

    /** ¿Esta persona puede cancelar ahora esta cita? (estado, y 2 horas de anticipación si es paciente) */
    public function puedeCancelarAhora(Cita $cita, Usuario $usuario, ?Carbon $ahora = null): bool
    {
        return $this->puedeCancelarse($cita) && ($this->esPersonal($usuario) || $this->hayAnticipacion($cita, $ahora));
    }

    /**
     * Cancela la cita: cambia el estado a CANCELADA, guarda el motivo y libera el horario, todo dentro de una transacción
     * con la fila de la cita bloqueada (dos personas cancelando a la vez no pueden duplicar el cambio).
     *
     * @CC CCCancelarCita (cancelar) @CE CECita (cancelar)
     *
     * @return array{cita: Cita, pagada: bool}
     *
     * @throws AuthorizationException la cita no es del paciente
     * @throws ReglaCitaException estado que no se cancela, poca anticipación del paciente o falta confirmar la cita pagada
     */
    public function cancelar(Cita $cita, Usuario $usuario, string $motivo, bool $confirmaSinDevolucion, ?Carbon $ahora = null): array
    {
        return DB::transaction(function () use ($cita, $usuario, $motivo, $confirmaSinDevolucion, $ahora) {
            $actual = Cita::with('paciente')->whereKey($cita->id)->lockForUpdate()->firstOrFail();

            $this->verificarPermiso($actual, $usuario);

            if (! $this->puedeCancelarse($actual)) {
                throw new ReglaCitaException($this->motivoNoCancelable($actual));
            }
            if (! $this->esPersonal($usuario) && ! $this->hayAnticipacion($actual, $ahora)) {
                throw new ReglaCitaException(self::MENSAJE_ANTICIPACION);
            }

            $pagada = $actual->estaPagada();
            if ($pagada && ! $confirmaSinDevolucion) {
                throw new ReglaCitaException(self::MENSAJE_CONFIRMAR_PAGADA);
            }

            $motivo = trim($motivo);
            $this->transiciones->cambiarEstado($actual, Cita::CANCELADA, ['motivo_cancelacion' => $motivo]);

            BitacoraService::registrar(
                'CITA_CANCELADA', 'Cita',
                "Cita {$actual->codigo} cancelada".($pagada ? ' (pago conservado: no se devuelve)' : '').". Motivo: {$motivo}",
                $usuario->id
            );

            return ['cita' => $actual, 'pagada' => $pagada];
        });
    }

    /** ¿Se puede ofrecer reprogramar en lugar de cancelar? (solo personal de recepción: CU-10 es de la recepcionista) */
    public function sePuedeOfrecerReprogramar(Cita $cita, Usuario $usuario): bool
    {
        return $this->esPersonal($usuario) && AgendarCitaService::sePuedeReprogramar($cita);
    }
}
