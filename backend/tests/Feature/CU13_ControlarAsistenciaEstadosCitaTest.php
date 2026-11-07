<?php

namespace Tests\Feature;

use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\GestionCitas\Compartido\TransicionesCita;
use App\Modules\GestionCitas\CU13_ControlarAsistenciaEstadosCita\EstadoCitaService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/** CU-13: Controlar asistencia y estados de la cita. Los métodos paso_N son las filas del plan de pruebas 8.1. */
class CU13_ControlarAsistenciaEstadosCitaTest extends SistemaTestCase
{
    private Usuario $recepcion;

    private Usuario $admin;

    private Usuario $usuarioMedico;

    private Medico $medico;

    private Paciente $paciente;

    private Especialidad $especialidad;

    private string $lunes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recepcion = $this->crearUsuario('t-recep-est', [Rol::RECEPCIONISTA]);
        $this->admin = $this->crearUsuario('t-admin-est', [Rol::ADMINISTRADOR]);
        $this->usuarioMedico = $this->crearUsuario('t-medico-est', [Rol::MEDICO]);
        $this->especialidad = $this->crearEspecialidad('T-Estados', precio: 100);
        $this->medico = $this->crearMedico(['usuario_id' => $this->usuarioMedico->id, 'puede_marcar_atendida' => true], [$this->especialidad]);
        $this->crearHorario($this->medico, 1, '08:00', '12:00', 30);
        $this->paciente = $this->crearPaciente(['nombres' => 'Estado', 'apellidos' => 'Tprueba']);
        $this->lunes = $this->proximaFecha(1);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ Plan de pruebas 8.1

    /** Paso 1: confirmar una cita (cita Programada) → la cita pasa a Confirmada y se registra el cambio. */
    #[Test]
    public function paso_1_confirmar_una_cita(): void
    {
        $cita = $this->cita();

        $this->actingAs($this->recepcion)->post(route('citas.estado', $cita), ['accion' => 'confirmar'])
            ->assertRedirect(route('control.index'))
            ->assertSessionHas('exito', "Cita {$cita->codigo} confirmada.");

        $this->assertSame(Cita::CONFIRMADA, $cita->fresh()->estado);
        $this->assertDatabaseHas('bitacora', ['accion' => 'CITA_CONFIRMADA', 'usuario_id' => $this->recepcion->id, 'detalle' => "Cita {$cita->codigo} confirmada (antes Programada)"]);

        $this->get(route('control.index', ['fecha' => $this->lunes]))->assertOk()->assertSee($cita->codigo)->assertSee('Confirmada');
    }

    /** Paso 2: registrar el cobro en efectivo de una cita (cita con pago en efectivo Pendiente) → el pago pasa a Pagado y queda registrado quién lo cobró. */
    #[Test]
    public function paso_2_registrar_el_cobro_en_efectivo_de_una_cita(): void
    {
        Http::fake(); // el cobro en efectivo no habla con Stripe
        $cita = $this->cita();
        $cita->update(['monto' => 100, 'metodo_pago' => 'EFECTIVO', 'estado_pago' => 'PENDIENTE']);

        $this->actingAs($this->recepcion)->post(route('citas.estado', $cita), ['accion' => 'cobrar'])
            ->assertSessionHas('exito', "Cobro de Bs 100,00 registrado en la cita {$cita->codigo}: el pago pasó a Pagado.");

        $cita->refresh();
        $this->assertSame(Cita::PAGO_PAGADO, $cita->estado_pago);
        $this->assertNotNull($cita->fecha_pago);
        $this->assertSame(Cita::PROGRAMADA, $cita->estado, 'El cobro no cambia el estado de la cita.');
        $this->assertDatabaseHas('bitacora', [
            'accion' => 'COBRO_REGISTRADO', 'usuario_id' => $this->recepcion->id,
            'detalle' => "Cobro en efectivo de Bs 100,00 de la cita {$cita->codigo} registrado por t-recep-est",
        ]);
        Http::assertNothingSent();
    }

    /** Paso 3: transición de estado no permitida (marcar como atendida una cita Cancelada) → el sistema rechaza la transición e informa el motivo. */
    #[Test]
    public function paso_3_transicion_de_estado_no_permitida(): void
    {
        $cita = $this->cita(Cita::CANCELADA);

        $this->actingAs($this->usuarioMedico)->post(route('citas.estado', $cita), ['accion' => 'atendida'])
            ->assertRedirect(route('control.index'))
            ->assertSessionHas('error', 'La cita está cancelada: no se puede marcar como atendida. Las citas canceladas no cambian de estado (si hubo un error, el administrador puede corregirlo con una justificación).');

        $this->assertSame(Cita::CANCELADA, $cita->fresh()->estado);
    }

    /** Paso 4: registrar inasistencia antes de la hora (inasistencia de una cita futura) → el sistema no lo permite. */
    #[Test]
    public function paso_4_registrar_inasistencia_antes_de_la_hora(): void
    {
        $cita = $this->cita(Cita::CONFIRMADA);
        Carbon::setTestNow(Carbon::parse($this->lunes.' 08:00:00')); // la cita es a las 09:00

        $this->actingAs($this->recepcion)->post(route('citas.estado', $cita), ['accion' => 'inasistencia'])
            ->assertSessionHas('error', 'No se puede registrar la inasistencia antes de la hora de la cita ('.Carbon::parse($this->lunes)->format('d/m/Y').' 09:00).');

        $this->assertSame(Cita::CONFIRMADA, $cita->fresh()->estado);
    }

    /** Paso 5: un médico sin permiso intenta marcar una cita como atendida (médico sin permiso y cita Confirmada) → el sistema rechaza la acción. */
    #[Test]
    public function paso_5_un_medico_sin_permiso_intenta_marcar_una_cita_como_atendida(): void
    {
        $this->medico->update(['puede_marcar_atendida' => false]);
        $cita = $this->cita(Cita::CONFIRMADA);

        $this->actingAs($this->usuarioMedico)->post(route('citas.estado', $cita), ['accion' => 'atendida'])
            ->assertSessionHas('error', 'No tiene asignado el permiso de marcar citas como atendidas.');

        $this->assertSame(Cita::CONFIRMADA, $cita->fresh()->estado);
        // Ni siquiera se le ofrece el botón.
        $this->get(route('control.index', ['fecha' => $this->lunes]))->assertOk()->assertSee($cita->codigo)->assertDontSee('Marcar atendida');
    }

    /** Paso 6: el administrador corrige un estado (Cancelada a Programada con justificación) → el estado cambia, se guarda la justificación y queda en la bitácora. */
    #[Test]
    public function paso_6_el_administrador_corrige_un_estado(): void
    {
        $cita = $this->cita(Cita::CANCELADA);
        $cita->update(['motivo_cancelacion' => 'Cancelada por error de digitación']);

        $this->actingAs($this->admin)->post(route('citas.estado', $cita), ['accion' => 'corregir', 'estado_nuevo' => 'PROGRAMADA', 'justificacion' => 'Se canceló la cita equivocada'])
            ->assertSessionHas('exito', "Estado de la cita {$cita->codigo} corregido: de cancelada a programada. La justificación quedó en la bitácora.");

        $cita->refresh();
        $this->assertSame(Cita::PROGRAMADA, $cita->estado);
        $this->assertNull($cita->motivo_cancelacion, 'Deja de estar cancelada: se limpia el motivo de cancelación.');
        $this->assertDatabaseHas('bitacora', [
            'accion' => 'ESTADO_CORREGIDO', 'usuario_id' => $this->admin->id,
            'detalle' => "Estado de la cita {$cita->codigo} corregido: CANCELADA → PROGRAMADA. Justificación: Se canceló la cita equivocada",
        ]);
    }

    // ------------------------------------------------------------------ Médico: solo sus citas y su permiso

    #[Test]
    public function el_medico_con_permiso_marca_atendida_su_cita_confirmada(): void
    {
        $cita = $this->cita(Cita::CONFIRMADA);

        $this->actingAs($this->usuarioMedico)->get(route('control.index', ['fecha' => $this->lunes]))->assertOk()->assertSee('Marcar atendida');
        $this->post(route('citas.estado', $cita), ['accion' => 'atendida'])->assertSessionHas('exito', "Cita {$cita->codigo} marcada como atendida.");

        $this->assertSame(Cita::ATENDIDA, $cita->fresh()->estado);
        $this->assertDatabaseHas('bitacora', ['accion' => 'CITA_ATENDIDA', 'usuario_id' => $this->usuarioMedico->id, 'detalle' => "Cita {$cita->codigo} marcada como atendida por el médico {$this->medico->codigo}"]);
    }

    #[Test]
    public function el_medico_solo_cambia_el_estado_de_sus_propias_citas_y_solo_ve_las_suyas(): void
    {
        $otroUsuario = $this->crearUsuario('t-medico-est-otro', [Rol::MEDICO]);
        $otroMedico = $this->crearMedico(['usuario_id' => $otroUsuario->id], [$this->especialidad]);
        $propia = $this->cita(Cita::CONFIRMADA);
        $ajena = $this->crearCita($otroMedico, $this->crearPaciente(), $this->especialidad, $this->lunes, '10:00', '10:30', Cita::CONFIRMADA);

        $this->actingAs($this->usuarioMedico)->get(route('control.index', ['fecha' => $this->lunes, 'medico' => $otroMedico->id]))->assertOk()
            ->assertSee($propia->codigo)->assertDontSee($ajena->codigo);

        $this->post(route('citas.estado', $ajena), ['accion' => 'atendida'])->assertSessionHas('error', 'Solo puede cambiar el estado de sus propias citas.');
        $this->assertSame(Cita::CONFIRMADA, $ajena->fresh()->estado);
    }

    #[Test]
    public function el_medico_no_confirma_ni_cobra_ni_registra_inasistencia_ni_corrige(): void
    {
        $cita = $this->cita(Cita::CONFIRMADA);
        Carbon::setTestNow(Carbon::parse($this->lunes.' 11:00:00'));
        $this->actingAs($this->usuarioMedico);

        foreach (['confirmar', 'cobrar', 'inasistencia'] as $accion) {
            $this->post(route('citas.estado', $cita), ['accion' => $accion])->assertSessionHas('error', 'Su rol no permite esta acción: la realiza la recepción.');
        }
        $this->post(route('citas.estado', $cita), ['accion' => 'corregir', 'estado_nuevo' => 'ATENDIDA', 'justificacion' => 'x'])
            ->assertSessionHas('error', 'Solo el administrador puede corregir el estado de una cita.');

        $this->assertSame(Cita::CONFIRMADA, $cita->fresh()->estado);
        $this->assertSame(Cita::PAGO_PENDIENTE, $cita->fresh()->estado_pago);
    }

    #[Test]
    public function la_recepcion_no_marca_atendida_y_el_medico_tampoco_una_cita_programada_sin_confirmar(): void
    {
        $confirmada = $this->cita(Cita::CONFIRMADA);
        $this->actingAs($this->recepcion)->post(route('citas.estado', $confirmada), ['accion' => 'atendida'])
            ->assertSessionHas('error', 'Solo el médico de la cita puede marcarla como atendida.');

        $programada = $this->cita(Cita::PROGRAMADA, '10:00');
        $this->actingAs($this->usuarioMedico)->post(route('citas.estado', $programada), ['accion' => 'atendida'])
            ->assertSessionHas('error', 'La cita está programada: no se puede marcar como atendida. Primero hay que confirmarla.');

        $this->assertSame(Cita::CONFIRMADA, $confirmada->fresh()->estado);
        $this->assertSame(Cita::PROGRAMADA, $programada->fresh()->estado);
    }

    #[Test]
    public function un_medico_sin_ficha_de_medico_no_ve_citas_ni_puede_cambiar_nada_y_recibe_un_aviso(): void
    {
        $sinFicha = $this->crearUsuario('t-medico-sin-ficha', [Rol::MEDICO]);
        $cita = $this->cita(Cita::CONFIRMADA);

        $this->actingAs($sinFicha)->get(route('control.index', ['fecha' => $this->lunes]))->assertOk()
            ->assertSee(EstadoCitaService::MENSAJE_SIN_FICHA_MEDICO)->assertDontSee($cita->codigo);
        $this->post(route('citas.estado', $cita), ['accion' => 'atendida'])->assertSessionHas('error', 'Solo puede cambiar el estado de sus propias citas.');
        $this->assertSame(Cita::CONFIRMADA, $cita->fresh()->estado);
    }

    // ------------------------------------------------------------------ Confirmar, inasistencia y cobro

    #[Test]
    public function solo_una_cita_programada_se_confirma(): void
    {
        $this->actingAs($this->recepcion);

        foreach ([Cita::CONFIRMADA, Cita::ATENDIDA, Cita::CANCELADA, Cita::INASISTENCIA] as $i => $estado) {
            $cita = $this->cita($estado, sprintf('%02d:00', 8 + $i));
            $this->post(route('citas.estado', $cita), ['accion' => 'confirmar'])->assertSessionHas('error', fn ($m) => str_starts_with($m, 'La cita está '.TransicionesCita::legible($estado).': no se puede confirmar.'));
            $this->assertSame($estado, $cita->fresh()->estado);
        }
    }

    #[Test]
    public function la_inasistencia_se_registra_desde_la_hora_de_la_cita_y_solo_en_citas_confirmadas(): void
    {
        $cita = $this->cita(Cita::CONFIRMADA);
        $this->actingAs($this->recepcion);

        Carbon::setTestNow(Carbon::parse($this->lunes.' 08:59:00'));
        $this->post(route('citas.estado', $cita), ['accion' => 'inasistencia'])->assertSessionHas('error');
        $this->assertSame(Cita::CONFIRMADA, $cita->fresh()->estado);

        Carbon::setTestNow(Carbon::parse($this->lunes.' 09:00:00'));
        $this->post(route('citas.estado', $cita), ['accion' => 'inasistencia'])->assertSessionHas('exito', "Inasistencia registrada en la cita {$cita->codigo}.");
        $this->assertSame(Cita::INASISTENCIA, $cita->fresh()->estado);
        $this->assertDatabaseHas('bitacora', ['accion' => 'INASISTENCIA_REGISTRADA', 'usuario_id' => $this->recepcion->id, 'detalle' => "Inasistencia registrada en la cita {$cita->codigo} (sin pago)"]);

        // Una cita programada (sin confirmar) no pasa directo a inasistencia.
        $programada = $this->cita(Cita::PROGRAMADA, '10:00');
        $this->post(route('citas.estado', $programada), ['accion' => 'inasistencia'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'La cita está programada: no se puede registrar la inasistencia.'));
    }

    #[Test]
    public function la_inasistencia_de_una_cita_pagada_conserva_el_pago_y_avisa_que_puede_reprogramarse(): void
    {
        $cita = $this->cita(Cita::CONFIRMADA);
        $cita->update(['monto' => 100, 'metodo_pago' => 'TARJETA', 'estado_pago' => 'PAGADO', 'referencia_pago' => 'pi_est_1', 'fecha_pago' => now()]);
        Carbon::setTestNow(Carbon::parse($this->lunes.' 10:00:00'));

        $this->actingAs($this->recepcion)->post(route('citas.estado', $cita), ['accion' => 'inasistencia'])
            ->assertSessionHas('exito', "Inasistencia registrada en la cita {$cita->codigo}. El pago se conserva: la cita puede reprogramarse sin un nuevo cobro (CU-10).")
            ->assertSessionHas('reprogramar', $cita->id);

        $cita->refresh();
        $this->assertSame(Cita::INASISTENCIA, $cita->estado);
        $this->assertSame(Cita::PAGO_PAGADO, $cita->estado_pago);
        $this->assertSame('pi_est_1', $cita->referencia_pago);

        // La agenda ofrece reprogramarla (CU-10).
        $this->get(route('control.index', ['fecha' => $this->lunes]))->assertOk()
            ->assertSee(route('citas.reprogramar', $cita), false)->assertSee('Reprogramarla ahora');
    }

    #[Test]
    public function el_cobro_solo_se_registra_en_citas_activas_con_efectivo_pendiente(): void
    {
        $this->actingAs($this->recepcion);

        $pagada = $this->cita(Cita::PROGRAMADA, '08:00');
        $pagada->update(['estado_pago' => 'PAGADO', 'fecha_pago' => now()]);
        $this->post(route('citas.estado', $pagada), ['accion' => 'cobrar'])->assertSessionHas('error', 'El pago de esta cita ya está registrado (Efectivo en la clínica).');

        $cancelada = $this->cita(Cita::CANCELADA, '09:00');
        $this->post(route('citas.estado', $cancelada), ['accion' => 'cobrar'])->assertSessionHas('error', 'La cita está cancelada: no se puede registrar un cobro.');

        $atendida = $this->cita(Cita::ATENDIDA, '10:00');
        $this->post(route('citas.estado', $atendida), ['accion' => 'cobrar'])->assertSessionHas('exito'); // se cobra al salir de la consulta
        $this->assertSame(Cita::PAGO_PAGADO, $atendida->fresh()->estado_pago);

        $inasistencia = $this->cita(Cita::INASISTENCIA, '11:00');
        $this->post(route('citas.estado', $inasistencia), ['accion' => 'cobrar'])->assertSessionHas('error', 'La cita está con inasistencia: no se registra un cobro nuevo.');
    }

    // ------------------------------------------------------------------ Corrección del administrador

    #[Test]
    public function la_correccion_exige_la_justificacion_el_nuevo_estado_y_ser_administrador(): void
    {
        $cita = $this->cita(Cita::ATENDIDA);

        $this->actingAs($this->admin)->post(route('citas.estado', $cita), ['accion' => 'corregir', 'estado_nuevo' => 'CONFIRMADA', 'justificacion' => '  '])
            ->assertSessionHas('error', 'Ingrese la justificación de la corrección: es obligatoria y queda en la bitácora.');
        $this->post(route('citas.estado', $cita), ['accion' => 'corregir', 'justificacion' => 'Falta el estado'])
            ->assertSessionHas('error', 'Elija el nuevo estado de la cita.');
        $this->post(route('citas.estado', $cita), ['accion' => 'corregir', 'estado_nuevo' => 'ATENDIDA', 'justificacion' => 'Igual'])
            ->assertSessionHas('error', 'La cita ya está atendida: elija un estado distinto.');
        $this->post(route('citas.estado', $cita), ['accion' => 'corregir', 'estado_nuevo' => 'CONFIRMADA', 'justificacion' => str_repeat('j', 151)])
            ->assertSessionHas('error', 'La justificación no puede superar los 150 caracteres.');
        $this->assertSame(Cita::ATENDIDA, $cita->fresh()->estado);

        // La recepcionista no corrige estados.
        $this->actingAs($this->recepcion)->post(route('citas.estado', $cita), ['accion' => 'corregir', 'estado_nuevo' => 'CONFIRMADA', 'justificacion' => 'Intento'])
            ->assertSessionHas('error', 'Solo el administrador puede corregir el estado de una cita.');
        $this->assertSame(Cita::ATENDIDA, $cita->fresh()->estado);
        $this->assertDatabaseMissing('bitacora', ['accion' => 'ESTADO_CORREGIDO', 'detalle' => "Estado de la cita {$cita->codigo} corregido: ATENDIDA → CONFIRMADA. Justificación: Intento"]);
    }

    #[Test]
    public function al_corregir_una_cancelada_se_verifica_que_su_horario_siga_libre(): void
    {
        $cancelada = $this->cita(Cita::CANCELADA);
        // Otra cita ocupó el horario que dejó libre la cancelada.
        $this->crearCita($this->medico, $this->crearPaciente(), $this->especialidad, $this->lunes, '09:00', '09:30');

        $this->actingAs($this->admin)->post(route('citas.estado', $cancelada), ['accion' => 'corregir', 'estado_nuevo' => 'PROGRAMADA', 'justificacion' => 'Error al cancelar'])
            ->assertSessionHas('error', 'El horario de esta cita ya fue ocupado por otra cita: no se puede restablecer. Reprograme o registre una cita nueva.');

        $this->assertSame(Cita::CANCELADA, $cancelada->fresh()->estado);

        // Si el paciente tiene otra cita a la misma hora con otro médico, tampoco.
        $otroMedico = $this->crearMedico([], [$this->especialidad]);
        $cancelada2 = $this->cita(Cita::CANCELADA, '10:00');
        $this->crearCita($otroMedico, $this->paciente, $this->especialidad, $this->lunes, '10:00', '10:30');
        $this->post(route('citas.estado', $cancelada2), ['accion' => 'corregir', 'estado_nuevo' => 'CONFIRMADA', 'justificacion' => 'Error'])
            ->assertSessionHas('error', 'El paciente ya tiene una cita activa a esa hora.');
        $this->assertSame(Cita::CANCELADA, $cancelada2->fresh()->estado);
    }

    #[Test]
    public function el_administrador_puede_corregir_una_cita_de_una_fecha_pasada_a_atendida(): void
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, '2020-01-06', '09:00', '09:30', Cita::CANCELADA);

        $this->actingAs($this->admin)->post(route('citas.estado', $cita), ['accion' => 'corregir', 'estado_nuevo' => 'ATENDIDA', 'justificacion' => 'La paciente sí asistió'])
            ->assertSessionHas('exito');

        $this->assertSame(Cita::ATENDIDA, $cita->fresh()->estado);
    }

    #[Test]
    public function corregir_a_cancelada_guarda_la_justificacion_como_motivo_y_a_programada_reinicia_el_recordatorio(): void
    {
        $this->actingAs($this->admin);

        $programada = $this->cita(Cita::PROGRAMADA);
        $this->post(route('citas.estado', $programada), ['accion' => 'corregir', 'estado_nuevo' => 'CANCELADA', 'justificacion' => 'Duplicada'])->assertSessionHas('exito');
        $this->assertSame('Corrección del administrador: Duplicada', $programada->fresh()->motivo_cancelacion);

        $atendida = $this->cita(Cita::ATENDIDA, '10:00');
        $atendida->update(['recordatorio_enviado' => true]);
        $this->post(route('citas.estado', $atendida), ['accion' => 'corregir', 'estado_nuevo' => 'PROGRAMADA', 'justificacion' => 'Se marcó por error'])->assertSessionHas('exito');
        $this->assertSame(Cita::PROGRAMADA, $atendida->fresh()->estado);
        $this->assertFalse($atendida->fresh()->recordatorio_enviado);
    }

    // ------------------------------------------------------------------ Pantalla y permisos

    #[Test]
    public function la_agenda_del_dia_se_filtra_por_fecha_estado_y_medico_y_dice_cuando_no_hay_citas(): void
    {
        $otroMedico = $this->crearMedico([], [$this->especialidad]);
        $programada = $this->cita(Cita::PROGRAMADA);
        $atendida = $this->cita(Cita::ATENDIDA, '10:00');
        $deOtro = $this->crearCita($otroMedico, $this->crearPaciente(), $this->especialidad, $this->lunes, '11:00', '11:30');
        $this->actingAs($this->recepcion);

        $this->get(route('control.index', ['fecha' => $this->lunes]))->assertOk()
            ->assertSee($programada->codigo)->assertSee($atendida->codigo)->assertSee($deOtro->codigo);
        $this->get(route('control.index', ['fecha' => $this->lunes, 'estado' => 'ATENDIDA']))->assertOk()
            ->assertSee($atendida->codigo)->assertDontSee($programada->codigo);
        $this->get(route('control.index', ['fecha' => $this->lunes, 'medico' => $otroMedico->id]))->assertOk()
            ->assertSee($deOtro->codigo)->assertDontSee($programada->codigo);
        $this->get(route('control.index', ['fecha' => '2031-01-01']))->assertOk()->assertSee('No hay citas para la fecha seleccionada.');

        // Una fecha inválida se reemplaza por hoy y un estado inventado se ignora, sin error.
        $this->get(route('control.index', ['fecha' => 'ayer', 'estado' => 'XYZ']))->assertOk()
            ->assertSee(Carbon::today()->format('d/m/Y'));
    }

    #[Test]
    public function cada_rol_ve_solo_las_acciones_que_le_corresponden(): void
    {
        $this->cita(Cita::PROGRAMADA);

        $this->actingAs($this->recepcion)->get(route('control.index', ['fecha' => $this->lunes]))->assertOk()
            ->assertSee('Confirmar')->assertSee('Registrar cobro')->assertDontSee('Corregir estado')->assertDontSee('Marcar atendida');
        $this->actingAs($this->admin)->get(route('control.index', ['fecha' => $this->lunes]))->assertOk()
            ->assertSee('Confirmar')->assertSee('Corregir estado')->assertSee('Justificación (obligatoria');
        $this->actingAs($this->usuarioMedico)->get(route('control.index', ['fecha' => $this->lunes]))->assertOk()
            ->assertDontSee('Registrar cobro')->assertDontSee('Corregir estado');
    }

    #[Test]
    public function la_inasistencia_de_una_cita_confirmada_futura_se_muestra_deshabilitada_con_su_hora(): void
    {
        $this->cita(Cita::CONFIRMADA);
        Carbon::setTestNow(Carbon::parse($this->lunes.' 07:00:00'));

        $this->actingAs($this->recepcion)->get(route('control.index', ['fecha' => $this->lunes]))->assertOk()
            ->assertSee('Inasistencia (desde las 09:00)');
    }

    #[Test]
    public function los_pacientes_no_acceden_y_sin_sesion_se_pide_iniciar_sesion(): void
    {
        $cita = $this->cita();
        $this->get(route('control.index'))->assertRedirect('/login');
        $this->post(route('citas.estado', $cita), ['accion' => 'confirmar'])->assertRedirect('/login');

        $paciente = $this->crearUsuario('t-pac-est', [Rol::PACIENTE]);
        $this->actingAs($paciente)->get(route('control.index'))->assertForbidden();
        $this->post(route('citas.estado', $cita), ['accion' => 'confirmar'])->assertForbidden();

        $this->assertSame(Cita::PROGRAMADA, $cita->fresh()->estado);
    }

    #[Test]
    public function una_accion_invalida_o_repetida_no_cambia_nada_y_avisa(): void
    {
        $cita = $this->cita();
        $this->actingAs($this->recepcion);

        $this->post(route('citas.estado', $cita), ['accion' => 'borrar'])->assertRedirect(route('control.index'))->assertSessionHas('error', 'La acción solicitada no es válida.');
        $this->post(route('citas.estado', $cita), [])->assertSessionHas('error', 'Elija la acción que desea realizar.');

        $this->post(route('citas.estado', $cita), ['accion' => 'confirmar'])->assertSessionHas('exito');
        $this->post(route('citas.estado', $cita), ['accion' => 'confirmar'])->assertSessionHas('error'); // doble clic: la segunda ya no aplica
        $this->assertSame(1, DB::table('bitacora')->where('accion', 'CITA_CONFIRMADA')->where('detalle', 'like', "Cita {$cita->codigo}%")->count());
    }

    #[Test]
    public function despues_de_la_accion_se_vuelve_a_la_agenda_con_los_mismos_filtros(): void
    {
        $cita = $this->cita();

        $this->actingAs($this->recepcion)->post(route('citas.estado', $cita), [
            'accion' => 'confirmar', 'volver_fecha' => $this->lunes, 'volver_estado' => 'PROGRAMADA', 'volver_medico' => $this->medico->id,
        ])->assertRedirect(route('control.index', ['fecha' => $this->lunes, 'estado' => 'PROGRAMADA', 'medico' => $this->medico->id]));
    }

    #[Test]
    public function las_transiciones_permitidas_son_las_del_diagrama_de_estados(): void
    {
        $t = app(TransicionesCita::class);

        $this->assertSame([Cita::CONFIRMADA, Cita::CANCELADA], $t->destinos(Cita::PROGRAMADA));
        $this->assertSame([Cita::ATENDIDA, Cita::INASISTENCIA, Cita::CANCELADA], $t->destinos(Cita::CONFIRMADA));
        foreach ([Cita::ATENDIDA, Cita::CANCELADA, Cita::INASISTENCIA] as $final) {
            $this->assertSame([], $t->destinos($final), "{$final} no cambia por las vías normales.");
        }
        $this->assertTrue($t->permitida(Cita::PROGRAMADA, Cita::CONFIRMADA));
        $this->assertFalse($t->permitida(Cita::PROGRAMADA, Cita::ATENDIDA));
        $this->assertFalse($t->permitida(Cita::CANCELADA, Cita::PROGRAMADA));
        $this->assertTrue($t->puedeCancelarse((new Cita)->forceFill(['estado' => Cita::CONFIRMADA])));
        $this->assertFalse($t->puedeCancelarse((new Cita)->forceFill(['estado' => Cita::ATENDIDA])));
    }

    /** Cita del paciente de la prueba con el médico de la prueba (por defecto Programada, 09:00 del lunes, efectivo pendiente). */
    private function cita(string $estado = Cita::PROGRAMADA, string $inicio = '09:00'): Cita
    {
        $fin = Carbon::parse($inicio)->addMinutes(30)->format('H:i');

        return $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, $inicio, $fin, $estado);
    }
}
