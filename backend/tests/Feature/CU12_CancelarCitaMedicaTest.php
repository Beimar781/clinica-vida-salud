<?php

namespace Tests\Feature;

use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\GestionCitas\Compartido\AgendarCitaService;
use App\Modules\GestionCitas\Compartido\DisponibilidadService;
use App\Modules\GestionCitas\CU12_CancelarCitaMedica\CancelarCitaService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/** CU-12: Cancelar cita médica. Los métodos paso_N son las filas del plan de pruebas 8.1. */
class CU12_CancelarCitaMedicaTest extends SistemaTestCase
{
    private Usuario $recepcion;

    private Usuario $usuarioPaciente;

    private Paciente $paciente;

    private Especialidad $especialidad;

    private Medico $medico;

    private string $lunes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recepcion = $this->crearUsuario('t-recep-canc', [Rol::RECEPCIONISTA]);
        $this->usuarioPaciente = $this->crearUsuario('t-pac-canc', [Rol::PACIENTE]);
        $this->paciente = $this->crearPaciente(['usuario_id' => $this->usuarioPaciente->id, 'nombres' => 'Cancela', 'apellidos' => 'Tprueba']);
        $this->especialidad = $this->crearEspecialidad('T-Cancelar', precio: 100);
        $this->medico = $this->crearMedico([], [$this->especialidad]);
        $this->crearHorario($this->medico, 1, '08:00', '12:00', 30);
        $this->lunes = $this->proximaFecha(1);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ Plan de pruebas 8.1

    /** Paso 1: cancelar una cita programada (cita Programada y motivo de cancelación) → la cita queda Cancelada con su motivo y el horario se libera. */
    #[Test]
    public function paso_1_cancelar_una_cita_programada(): void
    {
        $cita = $this->cita();

        $this->actingAs($this->recepcion)->get(route('citas.cancelar', $cita))->assertOk()
            ->assertSee($cita->codigo)->assertSee('Motivo de la cancelación')->assertSee('Confirmar cancelación');

        $this->post(route('citas.cancelar.guardar', $cita), ['motivo' => 'El paciente viajó fuera de la ciudad'])
            ->assertRedirect(route('citas.index', ['q' => $cita->codigo]))
            ->assertSessionHas('exito', "Cita {$cita->codigo} cancelada. El horario quedó libre.");

        $cita->refresh();
        $this->assertSame(Cita::CANCELADA, $cita->estado);
        $this->assertSame('El paciente viajó fuera de la ciudad', $cita->motivo_cancelacion);
        $this->assertNotNull(app(DisponibilidadService::class)->calcularLibres($this->medico, $this->lunes)->libre('09:00'), 'El horario se libera.');
        $this->assertDatabaseHas('bitacora', [
            'accion' => 'CITA_CANCELADA', 'usuario_id' => $this->recepcion->id,
            'detalle' => "Cita {$cita->codigo} cancelada. Motivo: El paciente viajó fuera de la ciudad",
        ]);

        // El horario liberado se puede volver a agendar.
        $nueva = app(AgendarCitaService::class)->crear($this->crearPaciente(), $this->medico, $this->especialidad, $this->lunes, '09:00', Cita::PRESENCIAL, null, $this->recepcion->id);
        $this->assertSame('09:00', $nueva->hora_inicio_corta);
    }

    /** Paso 2: cancelar una cita que ya está pagada (cita Programada con pago registrado) → el sistema avisa que el pago no se devuelve y ofrece reprogramarla; si se confirma, se cancela sin devolución. */
    #[Test]
    public function paso_2_cancelar_una_cita_ya_pagada(): void
    {
        Http::fake(); // no hay devolución: no se habla con Stripe
        $cita = $this->citaPagada();

        $this->actingAs($this->recepcion)->get(route('citas.cancelar', $cita))->assertOk()
            ->assertSee('el pago no se devuelve')->assertSee('Bs 100,00')->assertSee('Reprogramar en su lugar')
            ->assertSee(route('citas.reprogramar', $cita), false)->assertSee('Entiendo que el pago no se devuelve');

        // Sin confirmar la cancelación sin devolución no se cancela.
        $this->post(route('citas.cancelar.guardar', $cita), ['motivo' => 'Cambio de planes'])
            ->assertRedirect(route('citas.cancelar', $cita))->assertSessionHas('error', CancelarCitaService::MENSAJE_CONFIRMAR_PAGADA);
        $this->assertSame(Cita::PROGRAMADA, $cita->fresh()->estado);

        // Confirmando, se cancela sin devolución y el pago queda registrado tal cual.
        $this->post(route('citas.cancelar.guardar', $cita), ['motivo' => 'Cambio de planes', 'confirmo_sin_devolucion' => '1'])
            ->assertSessionHas('exito', "Cita {$cita->codigo} cancelada. El horario quedó libre. El pago registrado no se devuelve.");

        $cita->refresh();
        $this->assertSame(Cita::CANCELADA, $cita->estado);
        $this->assertSame(Cita::PAGO_PAGADO, $cita->estado_pago, 'El pago se conserva.');
        $this->assertSame('pi_cancelar_1', $cita->referencia_pago);
        $this->assertDatabaseHas('bitacora', ['accion' => 'CITA_CANCELADA', 'detalle' => "Cita {$cita->codigo} cancelada (pago conservado: no se devuelve). Motivo: Cambio de planes"]);
        Http::assertNothingSent();
    }

    /** Paso 3: cancelar una cita atendida (cita en estado Atendida) → el sistema no permite cancelarla. */
    #[Test]
    public function paso_3_cancelar_una_cita_atendida(): void
    {
        $cita = $this->cita(Cita::ATENDIDA);

        $this->actingAs($this->recepcion)->get(route('citas.cancelar', $cita))->assertOk()
            ->assertSee('La cita está atendida: no se puede cancelar.')->assertDontSee('Confirmar cancelación');
        $this->post(route('citas.cancelar.guardar', $cita), ['motivo' => 'Intento'])
            ->assertSessionHas('error', 'La cita está atendida: no se puede cancelar.');

        $this->assertSame(Cita::ATENDIDA, $cita->fresh()->estado);
        $this->assertNull($cita->fresh()->motivo_cancelacion);
    }

    /** Paso 4: el paciente cancela con poca anticipación (paciente cancela su cita 1 hora antes) → el sistema rechaza la cancelación e indica comunicarse con recepción. */
    #[Test]
    public function paso_4_el_paciente_cancela_con_poca_anticipacion(): void
    {
        $cita = $this->cita();
        Carbon::setTestNow(Carbon::parse($this->lunes.' 08:00:00')); // la cita es a las 09:00: falta 1 hora

        $this->actingAs($this->usuarioPaciente)->get(route('citas.cancelar', $cita))->assertOk()
            ->assertSee('No se puede cancelar con menos de 2 horas de anticipación. Comunícate con recepción.')->assertDontSee('Confirmar cancelación');
        $this->post(route('citas.cancelar.guardar', $cita), ['motivo' => 'No puedo ir'])
            ->assertRedirect(route('citas.cancelar', $cita))->assertSessionHas('error', CancelarCitaService::MENSAJE_ANTICIPACION);

        $this->assertSame(Cita::PROGRAMADA, $cita->fresh()->estado);
    }

    // ------------------------------------------------------------------ Reglas internas de la ficha

    #[Test]
    public function el_motivo_es_obligatorio_y_tiene_limite(): void
    {
        $cita = $this->cita();

        $this->actingAs($this->recepcion)->from(route('citas.cancelar', $cita))->post(route('citas.cancelar.guardar', $cita), ['motivo' => '  '])
            ->assertSessionHasErrors(['motivo' => 'Ingrese el motivo de la cancelación.']);
        $this->post(route('citas.cancelar.guardar', $cita), ['motivo' => str_repeat('x', 201)])
            ->assertSessionHasErrors(['motivo' => 'El motivo no puede superar los 200 caracteres.']);
        $this->assertSame(Cita::PROGRAMADA, $cita->fresh()->estado);

        $this->post(route('citas.cancelar.guardar', $cita), ['motivo' => str_repeat('x', 200)])->assertSessionHasNoErrors();
        $this->assertSame(200, mb_strlen($cita->fresh()->motivo_cancelacion));
    }

    #[Test]
    public function una_cita_confirmada_se_cancela_y_una_cancelada_o_con_inasistencia_no(): void
    {
        $this->actingAs($this->recepcion);

        $confirmada = $this->cita(Cita::CONFIRMADA);
        $this->post(route('citas.cancelar.guardar', $confirmada), ['motivo' => 'Se canceló'])->assertSessionHas('exito');
        $this->assertSame(Cita::CANCELADA, $confirmada->fresh()->estado);

        $this->post(route('citas.cancelar.guardar', $confirmada), ['motivo' => 'Otra vez'])->assertSessionHas('error', 'La cita está cancelada: no se puede cancelar.');
        $this->assertSame('Se canceló', $confirmada->fresh()->motivo_cancelacion, 'Una segunda cancelación no pisa el motivo.');

        $inasistencia = $this->cita(Cita::INASISTENCIA, '10:00');
        $this->post(route('citas.cancelar.guardar', $inasistencia), ['motivo' => 'No'])->assertSessionHas('error', 'La cita está con inasistencia: no se puede cancelar.');
        $this->assertSame(Cita::INASISTENCIA, $inasistencia->fresh()->estado);
    }

    #[Test]
    public function el_paciente_cancela_su_propia_cita_con_2_horas_o_mas_y_no_con_menos(): void
    {
        $cita = $this->cita();
        $this->actingAs($this->usuarioPaciente);

        // Exactamente 2 horas antes: se permite.
        Carbon::setTestNow(Carbon::parse($this->lunes.' 07:00:00'));
        $this->assertTrue(app(CancelarCitaService::class)->puedeCancelarAhora($cita, $this->usuarioPaciente));
        // Un minuto menos: no.
        Carbon::setTestNow(Carbon::parse($this->lunes.' 07:01:00'));
        $this->assertFalse(app(CancelarCitaService::class)->puedeCancelarAhora($cita, $this->usuarioPaciente));
        $this->post(route('citas.cancelar.guardar', $cita), ['motivo' => 'Tarde'])->assertSessionHas('error', CancelarCitaService::MENSAJE_ANTICIPACION);

        Carbon::setTestNow(Carbon::parse($this->lunes.' 07:00:00'));
        $this->post(route('citas.cancelar.guardar', $cita), ['motivo' => 'Me surgió un viaje'])
            ->assertSessionHas('exito', "Cita {$cita->codigo} cancelada. El horario quedó libre.")
            ->assertRedirect(); // vuelve a «Mis citas» (o al panel mientras esa pantalla no exista)

        $cita->refresh();
        $this->assertSame(Cita::CANCELADA, $cita->estado);
        $this->assertDatabaseHas('bitacora', ['accion' => 'CITA_CANCELADA', 'usuario_id' => $this->usuarioPaciente->id]);
    }

    #[Test]
    public function un_paciente_no_cancela_ni_ve_la_cancelacion_de_la_cita_de_otro(): void
    {
        $ajena = $this->crearCita($this->medico, $this->crearPaciente(['nombres' => 'Ajena']), $this->especialidad, $this->lunes, '10:00', '10:30');
        $otro = $this->crearUsuario('t-pac-otro', [Rol::PACIENTE]);
        $this->crearPaciente(['usuario_id' => $otro->id]);

        foreach ([$this->usuarioPaciente, $otro] as $usuario) {
            $this->actingAs($usuario)->get(route('citas.cancelar', $ajena))->assertForbidden();
            $this->post(route('citas.cancelar.guardar', $ajena), ['motivo' => 'Intento ajeno'])->assertForbidden();
        }

        // Un paciente sin ficha tampoco: no tiene citas propias.
        $sinFicha = $this->crearUsuario('t-pac-sinficha-canc', [Rol::PACIENTE]);
        $this->actingAs($sinFicha)->post(route('citas.cancelar.guardar', $ajena), ['motivo' => 'Intento'])->assertForbidden();

        $this->assertSame(Cita::PROGRAMADA, $ajena->fresh()->estado);
        $this->assertNull($ajena->fresh()->motivo_cancelacion);
    }

    #[Test]
    public function el_personal_de_recepcion_y_el_administrador_cancelan_sin_limite_de_anticipacion(): void
    {
        $admin = $this->crearUsuario('t-admin-canc', [Rol::ADMINISTRADOR]);
        Carbon::setTestNow(Carbon::parse($this->lunes.' 08:50:00')); // la cita es en 10 minutos

        $uno = $this->cita();
        $this->actingAs($this->recepcion)->post(route('citas.cancelar.guardar', $uno), ['motivo' => 'Llamó para avisar'])->assertSessionHas('exito');
        $this->assertSame(Cita::CANCELADA, $uno->fresh()->estado);

        $dos = $this->cita(Cita::PROGRAMADA, '10:00');
        $this->actingAs($admin)->post(route('citas.cancelar.guardar', $dos), ['motivo' => 'Corrección administrativa'])->assertSessionHas('exito');
        $this->assertSame(Cita::CANCELADA, $dos->fresh()->estado);
    }

    #[Test]
    public function solo_recepcion_administrador_y_paciente_acceden_a_la_cancelacion(): void
    {
        $cita = $this->cita();
        $this->get(route('citas.cancelar', $cita))->assertRedirect('/login');

        $medico = $this->crearUsuario('t-medico-canc', [Rol::MEDICO]);
        $this->actingAs($medico)->get(route('citas.cancelar', $cita))->assertForbidden();
        $this->post(route('citas.cancelar.guardar', $cita), ['motivo' => 'Intento'])->assertForbidden();

        $this->assertSame(Cita::PROGRAMADA, $cita->fresh()->estado);
    }

    #[Test]
    public function la_cita_pagada_con_tarjeta_tambien_se_cancela_sin_devolucion_ni_llamar_a_stripe(): void
    {
        Http::fake();
        $cita = $this->citaPagada();
        $cita->update(['metodo_pago' => 'TARJETA']);

        $this->actingAs($this->usuarioPaciente)->get(route('citas.cancelar', $cita))->assertOk()
            ->assertSee('Tarjeta (Stripe)')->assertSee('el pago no se devuelve')->assertSee('pide en recepción que reprogramen tu cita')
            ->assertDontSee('Reprogramar en su lugar');

        $this->post(route('citas.cancelar.guardar', $cita), ['motivo' => 'No voy a poder', 'confirmo_sin_devolucion' => '1'])->assertSessionHas('exito');

        $this->assertSame(Cita::CANCELADA, $cita->fresh()->estado);
        $this->assertSame(Cita::PAGO_PAGADO, $cita->fresh()->estado_pago);
        Http::assertNothingSent();
    }

    #[Test]
    public function el_listado_de_citas_ofrece_cancelar_solo_en_citas_programadas_o_confirmadas(): void
    {
        $programada = $this->cita();
        $atendida = $this->cita(Cita::ATENDIDA, '10:00');

        $this->actingAs($this->recepcion)->get(route('citas.index', ['q' => $programada->codigo]))->assertOk()
            ->assertSee(route('citas.cancelar', $programada), false);
        $this->get(route('citas.index', ['q' => $atendida->codigo]))->assertOk()
            ->assertDontSee(route('citas.cancelar', $atendida), false);
    }

    #[Test]
    public function cancelar_dos_veces_a_la_vez_deja_una_sola_cancelacion(): void
    {
        $cita = $this->cita();
        $this->actingAs($this->recepcion);

        $this->post(route('citas.cancelar.guardar', $cita), ['motivo' => 'Primera'])->assertSessionHas('exito');
        $this->post(route('citas.cancelar.guardar', $cita), ['motivo' => 'Segunda'])->assertSessionHas('error');

        $this->assertSame('Primera', $cita->fresh()->motivo_cancelacion);
        $this->assertSame(1, \DB::table('bitacora')->where('accion', 'CITA_CANCELADA')->where('detalle', 'like', "Cita {$cita->codigo}%")->count());
    }

    /** Cita del paciente de la prueba con el médico de la prueba (por defecto a las 09:00 del lunes, Programada). */
    private function cita(string $estado = Cita::PROGRAMADA, string $inicio = '09:00'): Cita
    {
        $fin = Carbon::parse($inicio)->addMinutes(30)->format('H:i');

        return $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, $inicio, $fin, $estado);
    }

    private function citaPagada(): Cita
    {
        $cita = $this->cita();
        $cita->update(['monto' => 100, 'metodo_pago' => 'EFECTIVO', 'estado_pago' => 'PAGADO', 'referencia_pago' => 'pi_cancelar_1', 'fecha_pago' => now()]);

        return $cita->fresh();
    }
}
