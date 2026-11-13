<?php

namespace App\Modules\GestionCitas\Compartido;

use App\Models\Cita;
use App\Models\CitaEvento;
use App\Models\Especialidad;
use App\Models\Medico;
use App\Models\Paciente;
use App\Support\Codigos;
use App\Support\Dinero;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Núcleo transaccional de las citas, compartido por CU-09 (registrar), CU-10 (reprogramar) y CU-11 (reservar en
 * línea) para no duplicar la lógica más delicada del sistema. Tres capas contra las citas superpuestas:
 *  1. el cálculo de disponibilidad (DisponibilidadService), que se VUELVE a hacer dentro de la transacción;
 *  2. el bloqueo de la fila del médico (lockForUpdate): dos personas que confirman a la vez se atienden una detrás de otra;
 *  3. las dos restricciones EXCLUDE de PostgreSQL (médico y paciente) como última barrera: si algo se escapa, la base
 *     de datos rechaza la segunda con SQLSTATE 23P01, que se captura FUERA de la transacción y se traduce a
 *     «El horario fue ocupado por otro usuario».
 *
 * Pago (v30): la cita guarda el monto (precio de la especialidad o servicio en ese momento), el método y el estado del
 * pago. Con efectivo queda PENDIENTE; con tarjeta el pago ya está autorizado en Stripe y se CAPTURA dentro de esta misma
 * transacción, justo antes de confirmarla (después de insertar la cita, así un 23P01 o cualquier regla lo detiene antes
 * de cobrar). Si algo falla, se CANCELA la autorización: no se cobra nada.
 *
 * @CU CU-09, CU-10, CU-11 @RF RF09, RF10, RF11 @CC CCRegistrarCita (validarReglas, registrar), CCReprogramarCita (reprogramar), CCReservarCita (reservar) @CE CECita (estaLibre, crear, reprogramar), CEPaciente, CEMedico
 */
class AgendarCitaService
{
    public const MENSAJE_PACIENTE_OCUPADO = 'El paciente ya tiene una cita activa a esa hora.';

    public const MENSAJE_PACIENTE_INACTIVO = 'El paciente está inactivo: no se le pueden registrar citas.';

    public function __construct(
        private readonly DisponibilidadService $disponibilidad,
        private readonly PagoStripeService $stripe,
    ) {}

    /**
     * Reglas del negocio sobre quién y con quién: paciente activo, médico activo, especialidad activa y que el
     * médico la ejerza. Se llama con datos recién leídos de la base.
     *
     * @CC CCRegistrarCita (validarReglas) @CE CEPaciente, CEMedico
     *
     * @throws ReglaCitaException
     */
    public function validarReglas(Paciente $paciente, Medico $medico, Especialidad $especialidad): void
    {
        if (! $paciente->estado) {
            throw new ReglaCitaException(self::MENSAJE_PACIENTE_INACTIVO);
        }
        if (! $medico->estado) {
            throw new ReglaCitaException(DisponibilidadService::MENSAJE_MEDICO_INACTIVO);
        }
        if (! $especialidad->estado) {
            throw new ReglaCitaException('La especialidad está inactiva: no se ofrece para nuevas citas.');
        }
        if (! $medico->especialidades()->whereKey($especialidad->id)->exists()) {
            throw new ReglaCitaException('El médico no atiende la especialidad elegida.');
        }
    }

    /**
     * Comprueba, SIN bloquear ni guardar nada, que la cita se podría registrar ahora (reglas, horario libre y paciente
     * sin otra cita a esa hora). Se usa antes de mandar a la persona a pagar con tarjeta: no tiene sentido cobrarle si ya
     * se sabe que no habrá cita. La verificación definitiva sigue siendo la de crear(), dentro de la transacción.
     *
     * @CC CCRegistrarCita (validarReglas, calcularLibres) @CE CECita (estaLibre)
     *
     * @throws HorarioOcupadoException
     * @throws ReglaCitaException
     */
    public function prevalidar(Paciente $paciente, Medico $medico, Especialidad $especialidad, string $fecha, string $inicio): void
    {
        $this->validarReglas($paciente->fresh(), $medico->fresh(), $especialidad->fresh());
        $bloque = $this->verificarBloque($medico, $fecha, $inicio, null);
        $this->verificarPaciente($paciente, $fecha, $bloque, null);
    }

    /**
     * Crea la cita en estado PROGRAMADA con su código CM-0001 (secuencia citas_codigo_seq) y su pago: efectivo
     * (PENDIENTE) o tarjeta ya autorizada en Stripe (se captura aquí y queda PAGADO con la referencia).
     *
     * @CC CCRegistrarCita (registrar), CCReservarCita (reservar) @CE CECita (estaLibre, crear)
     *
     * @param  string  $fecha  «YYYY-MM-DD»
     * @param  string  $inicio  «HH:MM» (debe ser el inicio de un bloque libre)
     *
     * @throws HorarioOcupadoException si el horario ya no está libre
     * @throws ReglaCitaException si una regla impide la cita (también PagoException si la captura falla)
     */
    public function crear(Paciente $paciente, Medico $medico, Especialidad $especialidad, string $fecha, string $inicio, string $modalidad, ?string $motivo, ?int $creadoPor, ?PagoCita $pago = null): Cita
    {
        $pago ??= PagoCita::efectivo();

        try {
            return DB::transaction(function () use ($paciente, $medico, $especialidad, $fecha, $inicio, $modalidad, $motivo, $creadoPor, $pago) {
                $medicoBloqueado = Medico::whereKey($medico->id)->lockForUpdate()->firstOrFail();
                $paciente = $paciente->fresh();
                $especialidad = $especialidad->fresh();

                $this->validarReglas($paciente, $medicoBloqueado, $especialidad);
                $bloque = $this->verificarBloque($medicoBloqueado, $fecha, $inicio, null);
                $this->verificarPaciente($paciente, $fecha, $bloque, null);

                // El monto es el precio de ESTE momento (copia: un cambio de precio posterior no altera la cita).
                $monto = number_format((float) $especialidad->precio, 2, '.', '');
                if ($pago->esTarjeta() && $pago->montoAutorizado !== $monto) {
                    throw new PagoException('El precio de «'.$especialidad->nombre.'» cambió mientras se realizaba el pago ('.Dinero::formato($pago->montoAutorizado).' → '.Dinero::formato($monto).'). No se cobró nada: vuelva a elegir el horario.');
                }

                $cita = Cita::create([
                    'codigo' => Codigos::cita(),
                    'paciente_id' => $paciente->id,
                    'medico_id' => $medicoBloqueado->id,
                    'especialidad_id' => $especialidad->id,
                    'fecha' => $fecha,
                    'hora_inicio' => $bloque['inicio'],
                    'hora_fin' => $bloque['fin'],
                    'modalidad' => $modalidad,
                    'motivo' => filled($motivo) ? trim($motivo) : null,
                    'estado' => Cita::PROGRAMADA,
                    'monto' => $monto,
                    'metodo_pago' => $pago->metodo,
                    'estado_pago' => $pago->esTarjeta() ? Cita::PAGO_PAGADO : Cita::PAGO_PENDIENTE,
                    'referencia_pago' => $pago->referencia,
                    'fecha_pago' => $pago->esTarjeta() ? now() : null,
                    'creado_por' => $creadoPor,
                ]);

                CitaEvento::registrar($cita, CitaEvento::REGISTRADA, $creadoPor, [
                    'fecha_nueva' => $fecha, 'hora_nueva' => $bloque['inicio'], 'estado_nuevo' => Cita::PROGRAMADA,
                ]);

                // Lo último antes de confirmar: si la captura falla se lanza PagoException y la transacción deshace la cita.
                if ($pago->esTarjeta()) {
                    $this->stripe->capturar($pago->referencia);
                }

                return $cita;
            });
        } catch (QueryException $e) {
            // Fuera de la transacción: dentro de ella PostgreSQL ya marcó la transacción como abortada.
            $this->cancelarAutorizacion($pago);
            throw $this->traducir($e) ?? $e;
        } catch (Throwable $e) {
            $this->cancelarAutorizacion($pago);
            throw $e;
        }
    }

    /**
     * Comprueba que una cita que va a volver a ocupar su horario (por ejemplo, el administrador corrige CANCELADA -> PROGRAMADA)
     * no choque con otra cita: bloquea la fila del médico y revisa que ningún otro registro no cancelado se superponga con el
     * horario del médico ni con el del paciente. No aplica la regla de «fecha pasada»: corregir una cita antigua es válido.
     * Las restricciones EXCLUDE de la base siguen siendo la última barrera.
     *
     * @CC CCEstadoCita (cambiarEstado) @CE CECita (estaLibre)
     *
     * @throws HorarioOcupadoException
     * @throws ReglaCitaException
     */
    public function verificarEspacioDeCita(Cita $cita): void
    {
        Medico::whereKey($cita->medico_id)->lockForUpdate()->firstOrFail();

        $ocupado = Cita::where('medico_id', $cita->medico_id)->where('id', '<>', $cita->id)
            ->whereDate('fecha', $cita->fecha->toDateString())->where('estado', '<>', Cita::CANCELADA)
            ->where('hora_inicio', '<', $cita->hora_fin)->where('hora_fin', '>', $cita->hora_inicio)->exists();

        if ($ocupado) {
            throw new HorarioOcupadoException('El horario de esta cita ya fue ocupado por otra cita: no se puede restablecer. Reprograme o registre una cita nueva.');
        }

        $this->verificarPaciente($cita->paciente, $cita->fecha->toDateString(), ['inicio' => $cita->hora_inicio, 'fin' => $cita->hora_fin], $cita->id);
    }

    /**
     * Cancela la autorización de Stripe cuando la cita no se pudo registrar: no se cobra nada. Si la cancelación
     * misma falla se deja en el log (la autorización vence sola en Stripe) y se sigue con el error original.
     */
    public function cancelarAutorizacion(PagoCita $pago): void
    {
        if (! $pago->esTarjeta() || blank($pago->referencia)) {
            return;
        }

        try {
            $this->stripe->cancelar($pago->referencia);
        } catch (Throwable $e) {
            Log::error('No se pudo cancelar la autorización del pago; vence sola en Stripe.', ['payment_intent' => $pago->referencia, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Cambia la fecha y hora de una cita conservando su código y su pago: desde PROGRAMADA, CONFIRMADA o INASISTENCIA ya
     * pagada; queda en PROGRAMADA y el horario anterior queda libre.
     *
     * @CC CCReprogramarCita (reprogramar) @CE CECita (obtener, reprogramar)
     *
     * @throws HorarioOcupadoException
     * @throws ReglaCitaException
     */
    public function mover(Cita $cita, string $fecha, string $inicio, ?int $usuarioId = null, ?string $motivoCambio = null): Cita
    {
        try {
            return DB::transaction(function () use ($cita, $fecha, $inicio, $usuarioId, $motivoCambio) {
                // Siempre en el mismo orden (primero el médico, luego la cita) para no provocar bloqueos cruzados.
                $medico = Medico::whereKey($cita->medico_id)->lockForUpdate()->firstOrFail();
                $actual = Cita::whereKey($cita->id)->lockForUpdate()->firstOrFail();

                if (! self::sePuedeReprogramar($actual)) {
                    throw new ReglaCitaException(self::mensajeNoReprogramable($actual));
                }
                if (! $medico->estado) {
                    throw new ReglaCitaException(DisponibilidadService::MENSAJE_MEDICO_INACTIVO);
                }

                $bloque = $this->verificarBloque($medico, $fecha, $inicio, $actual->id);
                if ($actual->fecha->toDateString() === $fecha && substr($actual->hora_inicio, 0, 5) === $bloque['inicio']) {
                    throw new ReglaCitaException('El nuevo horario es el mismo que ya tiene la cita. Elija otro.');
                }
                $this->verificarPaciente($actual->paciente, $fecha, $bloque, $actual->id);

                // Conserva el código y el pago (no hay nuevo cobro); la cita vuelve a PROGRAMADA y, como cambió la
                // fecha, el recordatorio de CU-22 se vuelve a enviar. El evento guarda el horario anterior y el nuevo.
                $anterior = ['fecha' => $actual->fecha->toDateString(), 'hora' => $actual->hora_inicio, 'estado' => $actual->estado];
                $actual->update([
                    'fecha' => $fecha,
                    'hora_inicio' => $bloque['inicio'],
                    'hora_fin' => $bloque['fin'],
                    'estado' => Cita::PROGRAMADA,
                    'recordatorio_enviado' => false,
                ]);

                CitaEvento::registrar($actual, CitaEvento::REPROGRAMADA, $usuarioId, [
                    'fecha_anterior' => $anterior['fecha'], 'hora_anterior' => $anterior['hora'],
                    'fecha_nueva' => $fecha, 'hora_nueva' => $bloque['inicio'],
                    'estado_anterior' => $anterior['estado'], 'estado_nuevo' => Cita::PROGRAMADA, 'motivo' => $motivoCambio,
                ]);

                return $actual;
            });
        } catch (QueryException $e) {
            throw $this->traducir($e) ?? $e;
        }
    }

    /**
     * Se pueden reprogramar las citas PROGRAMADA o CONFIRMADA y las que tienen INASISTENCIA pero ya están pagadas (el pago
     * no se devuelve: se reprograman sin un nuevo cobro). Atendida, cancelada o inasistencia sin pago: se registra una nueva (CU-09).
     */
    public static function sePuedeReprogramar(Cita $cita): bool
    {
        return in_array($cita->estado, [Cita::PROGRAMADA, Cita::CONFIRMADA], true)
            || ($cita->estado === Cita::INASISTENCIA && $cita->estado_pago === Cita::PAGO_PAGADO);
    }

    public static function mensajeNoReprogramable(Cita $cita): string
    {
        $estado = [
            'ATENDIDA' => 'atendida',
            'CANCELADA' => 'cancelada',
            'INASISTENCIA' => 'con inasistencia y sin pago',
        ][$cita->estado] ?? mb_strtolower($cita->estado);

        return "La cita está {$estado}: no se puede reprogramar. Registre una cita nueva.";
    }

    /**
     * Comprueba que el bloque que empieza a esa hora esté libre ese día; si no, explica por qué.
     *
     * @return array{inicio: string, fin: string}
     *
     * @throws ReglaCitaException fecha pasada, feriado, médico que no atiende, hora fuera de su horario o que ya pasó
     * @throws HorarioOcupadoException el bloque existe pero ya tiene una cita
     */
    private function verificarBloque(Medico $medico, string $fecha, string $inicio, ?int $ignorarCitaId): array
    {
        $resultado = $this->disponibilidad->calcularLibres($medico, $fecha, $ignorarCitaId);

        if (in_array($resultado->motivo, [Disponibilidad::FECHA_PASADA, Disponibilidad::DIA_SIN_ATENCION, Disponibilidad::MEDICO_INACTIVO, Disponibilidad::NO_ATIENDE], true)) {
            throw new ReglaCitaException($resultado->mensaje);
        }

        if ($libre = $resultado->libre($inicio)) {
            return $libre;
        }

        if ($resultado->bloque($inicio) === null) {
            throw new ReglaCitaException('La hora elegida está fuera del horario de atención del médico.');
        }

        $hoy = DisponibilidadService::dia($fecha)->isSameDay(Carbon::now());
        if ($hoy && DisponibilidadService::minutos($inicio) <= Carbon::now()->hour * 60 + Carbon::now()->minute) {
            throw new ReglaCitaException('Ese horario ya pasó. Elija uno de los horarios libres.');
        }

        throw new HorarioOcupadoException;
    }

    /**
     * El paciente no puede tener dos citas activas a la misma hora (aunque sean con médicos distintos).
     *
     * @param  array{inicio: string, fin: string}  $bloque
     *
     * @throws ReglaCitaException
     */
    private function verificarPaciente(Paciente $paciente, string $fecha, array $bloque, ?int $ignorarCitaId): void
    {
        $choca = Cita::where('paciente_id', $paciente->id)
            ->whereDate('fecha', $fecha)
            ->where('estado', '<>', Cita::CANCELADA)
            ->where('hora_inicio', '<', $bloque['fin'])
            ->where('hora_fin', '>', $bloque['inicio'])
            ->when($ignorarCitaId, fn ($q) => $q->where('id', '!=', $ignorarCitaId))
            ->exists();

        if ($choca) {
            throw new ReglaCitaException(self::MENSAJE_PACIENTE_OCUPADO);
        }
    }

    /** SQLSTATE 23P01 (exclusion_violation): las restricciones EXCLUDE de la tabla citas rechazaron la superposición. */
    private function traducir(QueryException $e): ?Throwable
    {
        if ($e->getCode() !== '23P01') {
            return null;
        }

        return str_contains($e->getMessage(), 'citas_paciente')
            ? new ReglaCitaException(self::MENSAJE_PACIENTE_OCUPADO)
            : new HorarioOcupadoException;
    }
}
