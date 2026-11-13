<?php

namespace App\Modules\AtencionClinica\CU16_RegistrarConsultaTratamiento;

use App\Models\Cita;
use App\Models\CitaEvento;
use App\Models\Consulta;
use App\Models\HistoriaClinica;
use App\Models\Medico;
use App\Models\Usuario;
use App\Modules\GestionCitas\Compartido\ReglaCitaException;
use App\Modules\GestionCitas\Compartido\TransicionesCita;
use App\Modules\GestionCitas\CU13_ControlarAsistenciaEstadosCita\EstadoCitaService;
use App\Services\BitacoraService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Registro de la consulta (diagnóstico, tratamiento y observaciones) de una cita. Solo el Médico con el permiso «puede marcar citas
 * como atendidas» y solo sobre sus propias citas Programadas o Confirmadas (también una ya Atendida que todavía no tiene consulta:
 * si el médico la marcó atendida en CU-13 sin registrar la consulta, quedaría una atención sin historia). En una sola transacción se
 * guarda la consulta en la historia clínica del paciente y la cita pasa a ATENDIDA con las transiciones compartidas. Si la cita ya
 * tiene consulta se muestra y se puede corregir (la cita no cambia de estado). La bitácora guarda quién y cuándo, pero nunca el
 * contenido clínico (diagnóstico, tratamiento).
 *
 * @CU CU-16 @RF RF16 @CI CIConsulta @CC CCConsulta @CE CECita (obtener, cambiarEstado), CEConsulta (crear, obtenerPorCita), CEHistoriaClinica (obtenerPorPaciente)
 */
class ConsultaService
{
    public const MENSAJE_AJENA = 'Solo puede registrar consultas de sus propias citas.';

    public const MENSAJE_SIN_PERMISO = 'No tiene asignado el permiso de marcar citas como atendidas: no puede registrar consultas.';

    public const MENSAJE_YA_TIENE = 'La cita ya tiene una consulta registrada: se muestra para que pueda corregirla.';

    public const MENSAJE_SIN_CONSULTA = 'La cita todavía no tiene una consulta para corregir.';

    public function __construct(private readonly TransicionesCita $transiciones) {}

    /**
     * Comprueba que el usuario sea el médico de la cita y tenga el permiso de marcar citas como atendidas.
     *
     * @CC CCConsulta (abrir)
     *
     * @throws ReglaCitaException con el motivo (seguro para mostrarlo)
     */
    public function verificarMedico(Cita $cita, Usuario $usuario): Medico
    {
        $medico = TransicionesCita::medicoDe($usuario);

        if (! $medico) {
            throw new ReglaCitaException($usuario->nombresRoles()->contains('Médico') ? EstadoCitaService::MENSAJE_SIN_FICHA_MEDICO : 'Solo el médico puede registrar consultas.');
        }
        if ($medico->id !== $cita->medico_id) {
            throw new ReglaCitaException(self::MENSAJE_AJENA);
        }
        if (! $medico->puede_marcar_atendida) {
            throw new ReglaCitaException(self::MENSAJE_SIN_PERMISO);
        }

        return $medico;
    }

    /**
     * ¿En qué estado se puede registrar una consulta nueva? Programada, Confirmada o (sin consulta todavía) Atendida.
     *
     * @throws ReglaCitaException
     */
    public function verificarEstado(Cita $cita): void
    {
        if (in_array($cita->estado, [Cita::PROGRAMADA, Cita::CONFIRMADA, Cita::ATENDIDA], true)) {
            return;
        }

        throw new ReglaCitaException('La cita está '.TransicionesCita::legible($cita->estado).': no se puede registrar la consulta.');
    }

    /**
     * Lo que el médico puede hacer con la cita en su agenda: «registrar» la consulta, «ver» la ya registrada, o nada. Nunca lanza errores.
     *
     * @CC CCConsulta (abrir)
     */
    public function accion(Cita $cita, Usuario $usuario): ?string
    {
        try {
            $this->verificarMedico($cita, $usuario);
        } catch (ReglaCitaException) {
            return null;
        }

        if ($cita->relationLoaded('consulta') ? $cita->consulta !== null : $cita->consulta()->exists()) {
            return 'ver';
        }

        try {
            $this->verificarEstado($cita);
        } catch (ReglaCitaException) {
            return null;
        }

        return 'registrar';
    }

    /**
     * Abre la cita para registrar (o corregir) la consulta: valida al médico y devuelve la consulta existente, si la hay.
     *
     * @CC CCConsulta (abrir) @CE CEConsulta (obtenerPorCita)
     *
     * @throws ReglaCitaException
     */
    public function abrir(Cita $cita, Usuario $usuario): ?Consulta
    {
        $this->verificarMedico($cita, $usuario);
        $consulta = Consulta::obtenerPorCita($cita->id);

        if (! $consulta) {
            $this->verificarEstado($cita);
        }

        return $consulta;
    }

    /**
     * Guarda la consulta en la historia clínica del paciente y pasa la cita a ATENDIDA, todo en una transacción con la cita bloqueada.
     *
     * @CC CCConsulta (registrar) @CE CEConsulta (crear), CECita (cambiarEstado), CEHistoriaClinica (obtenerPorPaciente)
     *
     * @param  array{diagnostico: string, tratamiento?: ?string, observaciones?: ?string}  $datos
     *
     * @throws ReglaCitaException
     */
    public function registrar(Cita $cita, Usuario $usuario, array $datos): Consulta
    {
        try {
            return DB::transaction(function () use ($cita, $usuario, $datos) {
                $actual = Cita::with('paciente.historiaClinica')->whereKey($cita->id)->lockForUpdate()->firstOrFail();
                $medico = $this->verificarMedico($actual, $usuario);

                if (Consulta::obtenerPorCita($actual->id)) {
                    throw new ReglaCitaException(self::MENSAJE_YA_TIENE);
                }
                $this->verificarEstado($actual);

                $historia = $actual->paciente->historiaClinica ?? HistoriaClinica::crearParaPaciente($actual->paciente);

                $consulta = Consulta::create($this->campos($datos) + [
                    'cita_id' => $actual->id, 'historia_clinica_id' => $historia->id, 'medico_id' => $medico->id,
                ]);

                // La cita pasa a ATENDIDA con las transiciones compartidas: una Programada primero queda Confirmada (atender la cita
                // implica que el paciente llegó), igual que si la recepción la hubiera confirmado.
                if ($actual->estado === Cita::PROGRAMADA) {
                    $this->transiciones->cambiarEstado($actual, Cita::CONFIRMADA);
                    CitaEvento::registrar($actual, CitaEvento::CONFIRMADA, $usuario->id, ['estado_anterior' => Cita::PROGRAMADA, 'estado_nuevo' => Cita::CONFIRMADA]);
                }
                if ($actual->estado !== Cita::ATENDIDA) {
                    $antes = $actual->estado;
                    $this->transiciones->cambiarEstado($actual, Cita::ATENDIDA);
                    CitaEvento::registrar($actual, CitaEvento::ATENDIDA, $usuario->id, ['estado_anterior' => $antes, 'estado_nuevo' => Cita::ATENDIDA]);
                }

                BitacoraService::registrar(
                    'CONSULTA_REGISTRADA', 'Consulta',
                    "Consulta registrada en la cita {$actual->codigo} (paciente {$actual->paciente->codigo}, médico {$medico->codigo}); la cita pasó a Atendida",
                    $usuario->id
                );

                return $consulta;
            });
        } catch (UniqueConstraintViolationException) {
            // Dos envíos a la vez: la restricción UNIQUE de cita_id deja solo una consulta.
            throw new ReglaCitaException(self::MENSAJE_YA_TIENE);
        }
    }

    /**
     * Corrige la consulta ya registrada (la cita no cambia de estado).
     *
     * @CC CCConsulta (registrar) @CE CEConsulta (obtenerPorCita)
     *
     * @param  array{diagnostico: string, tratamiento?: ?string, observaciones?: ?string}  $datos
     *
     * @throws ReglaCitaException
     */
    public function corregir(Cita $cita, Usuario $usuario, array $datos): Consulta
    {
        return DB::transaction(function () use ($cita, $usuario, $datos) {
            $actual = Cita::with('paciente')->whereKey($cita->id)->lockForUpdate()->firstOrFail();
            $medico = $this->verificarMedico($actual, $usuario);

            $consulta = Consulta::obtenerPorCita($actual->id);
            if (! $consulta) {
                throw new ReglaCitaException(self::MENSAJE_SIN_CONSULTA);
            }

            $consulta->update($this->campos($datos));

            BitacoraService::registrar(
                'CONSULTA_CORREGIDA', 'Consulta',
                "Consulta de la cita {$actual->codigo} corregida (paciente {$actual->paciente->codigo}, médico {$medico->codigo})",
                $usuario->id
            );

            return $consulta;
        });
    }

    /** Diagnóstico sin espacios sobrantes; tratamiento y observaciones vacíos se guardan como null. */
    private function campos(array $datos): array
    {
        $limpiar = fn (?string $texto) => ($texto = trim((string) $texto)) === '' ? null : $texto;

        return [
            'diagnostico' => trim((string) $datos['diagnostico']),
            'tratamiento' => $limpiar($datos['tratamiento'] ?? null),
            'observaciones' => $limpiar($datos['observaciones'] ?? null),
        ];
    }
}
