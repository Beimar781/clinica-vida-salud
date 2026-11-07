<?php

namespace Database\Seeders;

use App\Models\BloqueoAgenda;
use App\Models\Cita;
use App\Models\HorarioAtencion;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Usuario;
use App\Support\Codigos;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Citas de demostración en todos los estados y con distintos pagos (v30), pasadas y próximas. Cada una cae en un
 * día en que el médico atiende (y que no es feriado), en el bloque que corresponda a su horario.
 *
 * Casos cubiertos: Programada con efectivo Pendiente, Confirmada con tarjeta Pagada (con referencia de ejemplo),
 * Inasistencia con pago Pagado (reprogramable sin nuevo cobro, CU-10), Inasistencia con pago Pendiente, Atendida,
 * Cancelada con pago Pagado, servicios de laboratorio y enfermería, y una cita de mañana para una paciente que
 * recibe recordatorios (CU-22).
 *
 * Idempotente cita por cita: una cita ya sembrada se reconoce por médico + paciente + motivo y no se vuelve a
 * crear (aunque después se reprograme o cambie de estado). Si existe con monto 0 (las del Ciclo I) solo se le
 * completan los datos del pago, una única vez.
 */
class CitaSeeder extends Seeder
{
    /** Las referencias de los pagos con tarjeta de demostración llevan este prefijo para distinguirlas de las de Stripe. */
    private const REFERENCIA_DEMO = 'pi_DEMO_';

    public function run(): void
    {
        $recepcionId = Usuario::where('nombre_usuario', 'recepcion')->value('id');
        $efectivo = Cita::METODO_EFECTIVO;
        $tarjeta = Cita::METODO_TARJETA;
        $pagado = Cita::PAGO_PAGADO;
        $pendiente = Cita::PAGO_PENDIENTE;

        // [registro del médico, CI del paciente, días desde hoy, posición del bloque, estado, modalidad, motivo, método, pago]
        $citas = [
            ['RM-1001', '6543210', -14, 0, Cita::ATENDIDA, Cita::PRESENCIAL, 'Control general', $efectivo, $pagado],
            ['RM-1002', '9012345', -10, 1, Cita::ATENDIDA, Cita::PRESENCIAL, 'Control pediátrico', $tarjeta, $pagado],
            ['RM-1001', '5987654', -7, 2, Cita::INASISTENCIA, Cita::PRESENCIAL, 'Chequeo anual', $tarjeta, $pagado],
            ['RM-1003', '10234567', -3, 0, Cita::CANCELADA, Cita::PRESENCIAL, 'Control ginecológico', $tarjeta, $pagado],
            ['RM-1001', '6543210', 1, 0, Cita::CONFIRMADA, Cita::PRESENCIAL, 'Dolor de cabeza frecuente', $tarjeta, $pagado],
            ['RM-1001', '7123456', 1, 1, Cita::PROGRAMADA, Cita::PRESENCIAL, 'Control de presión arterial', $efectivo, $pendiente],
            ['RM-1002', '9012345', 2, 2, Cita::PROGRAMADA, Cita::PRESENCIAL, 'Vacunas', $efectivo, $pendiente],
            ['RM-1003', '10234567', 3, 0, Cita::PROGRAMADA, Cita::VIRTUAL, 'Consulta de resultados', $efectivo, $pendiente],
            ['RM-1004', '5987654', 4, 1, Cita::CONFIRMADA, Cita::PRESENCIAL, 'Limpieza dental', $efectivo, $pendiente],
            ['RM-1005', '6543210', 5, 0, Cita::PROGRAMADA, Cita::PRESENCIAL, 'Evaluación cardiológica', $efectivo, $pendiente],
            ['RM-1001', '11345678', 7, 3, Cita::PROGRAMADA, Cita::VIRTUAL, null, $efectivo, $pendiente],
            ['RM-1002', '7123456', 8, 0, Cita::CANCELADA, Cita::PRESENCIAL, null, $efectivo, $pendiente],
            // v30
            ['RM-1004', '4321876', -5, 0, Cita::INASISTENCIA, Cita::PRESENCIAL, 'Dolor de muela', $efectivo, $pendiente],
            ['RM-1006', '7123456', -2, 0, Cita::ATENDIDA, Cita::PRESENCIAL, 'Análisis de sangre', $efectivo, $pagado],
            ['RM-1006', '5987654', 3, 1, Cita::PROGRAMADA, Cita::PRESENCIAL, 'Perfil lipídico', $tarjeta, $pagado],
            ['RM-1007', '6543210', 1, 3, Cita::PROGRAMADA, Cita::PRESENCIAL, 'Curación de herida', $efectivo, $pendiente],
        ];

        foreach ($citas as $numero => [$registro, $ci, $dias, $posicion, $estado, $modalidad, $motivo, $metodo, $pago]) {
            $medico = Medico::with('especialidades')->where('nro_registro', $registro)->first();
            $paciente = Paciente::where('ci', $ci)->first();
            if (! $medico || ! $paciente) {
                continue;
            }

            $especialidad = $medico->especialidades->first();
            $existente = Cita::where('medico_id', $medico->id)->where('paciente_id', $paciente->id)->where('motivo', $motivo)->first();

            $datosPago = $this->datosPago($especialidad->precio, $metodo, $pago, $numero);

            if ($existente) {
                // Cita del Ciclo I: todavía tiene los valores por defecto que dejó la migración (EFECTIVO, PENDIENTE,
                // sin referencia). Se le completa el pago de la demostración; si ya se cobró o se cambió, no se toca.
                $sinPagoSembrado = $existente->metodo_pago === $efectivo && $existente->estado_pago === $pendiente
                    && $existente->referencia_pago === null && $existente->fecha_pago === null;

                if ($sinPagoSembrado && ($metodo !== $efectivo || $pago !== $pendiente)) {
                    $existente->update($datosPago + ['fecha_pago' => $pago === $pagado ? $this->fechaPago($existente->fecha, $existente->hora_inicio) : null]);
                } elseif ((float) $existente->monto === 0.0) {
                    $existente->update(['monto' => $especialidad->precio]);
                }

                continue;
            }

            [$fecha, $inicio, $fin] = $this->horarioLibre($medico, Carbon::today()->addDays($dias), $posicion);

            Cita::create([
                'codigo' => Codigos::cita(),
                'paciente_id' => $paciente->id,
                'medico_id' => $medico->id,
                'especialidad_id' => $especialidad->id,
                'fecha' => $fecha->toDateString(),
                'hora_inicio' => $inicio,
                'hora_fin' => $fin,
                'modalidad' => $modalidad,
                'motivo' => $motivo,
                'estado' => $estado,
                'motivo_cancelacion' => $estado === Cita::CANCELADA ? 'Cancelada a pedido del paciente' : null,
                'creado_por' => $recepcionId,
                'fecha_pago' => $pago === $pagado ? $this->fechaPago($fecha, $inicio) : null,
            ] + $datosPago);
        }
    }

    /** Cuándo se pagó: a la hora de la cita si ya pasó; si la cita es futura, ahora (se pagó al reservarla). */
    private function fechaPago(Carbon $fecha, string $horaInicio): Carbon
    {
        return $fecha->copy()->setTimeFromTimeString($horaInicio)->min(Carbon::now());
    }

    /**
     * Monto (el precio de la especialidad o servicio) y datos del pago. Con tarjeta pagada lleva una referencia de
     * ejemplo; el sistema real guarda ahí el id del PaymentIntent de Stripe.
     *
     * @return array<string, mixed>
     */
    private function datosPago(string|float $precio, string $metodo, string $pago, int $numero): array
    {
        return [
            'monto' => $precio,
            'metodo_pago' => $metodo,
            'estado_pago' => $pago,
            'referencia_pago' => $metodo === Cita::METODO_TARJETA && $pago === Cita::PAGO_PAGADO
                ? self::REFERENCIA_DEMO.str_pad((string) ($numero + 1), 4, '0', STR_PAD_LEFT)
                : null,
        ];
    }

    /**
     * Primer día desde $desde en que el médico atiende (sin feriado) y el bloque número $posicion
     * (contado desde el primer horario del día).
     *
     * @return array{0: Carbon, 1: string, 2: string}
     */
    private function horarioLibre(Medico $medico, Carbon $desde, int $posicion): array
    {
        $bloqueados = BloqueoAgenda::where('estado', true)->pluck('fecha')->map->toDateString()->all();
        $horarios = HorarioAtencion::where('medico_id', $medico->id)->where('estado', true)
            ->orderBy('hora_inicio')->get()->groupBy('dia_semana');

        for ($fecha = $desde->copy(); ; $fecha->addDay()) {
            if (in_array($fecha->toDateString(), $bloqueados, true) || ! isset($horarios[$fecha->dayOfWeekIso])) {
                continue;
            }

            $bloque = $horarios[$fecha->dayOfWeekIso]->first();
            $inicio = Carbon::parse($bloque->hora_inicio)->addMinutes($posicion * $bloque->duracion_min);

            return [$fecha, $inicio->format('H:i'), $inicio->copy()->addMinutes($bloque->duracion_min)->format('H:i')];
        }
    }
}
