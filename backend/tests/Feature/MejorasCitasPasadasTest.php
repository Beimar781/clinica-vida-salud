<?php

namespace Tests\Feature;

use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * Mejoras posteriores a las pruebas del sistema desplegado: citas pasadas en «Mis citas» (CU-15), señal «Vencida · pendiente de cierre»
 * (CU-13, CU-14, CU-18), aviso de atendida con pago pendiente (CU-13, CU-16, CU-18) y alias /agenda-medica (CU-14).
 * «Ahora» se fija en el 04/03/2035 10:00 para que las citas queden claramente pasadas, próximas o futuras.
 */
class MejorasCitasPasadasTest extends SistemaTestCase
{
    private Usuario $recepcion;

    private Usuario $usuarioPaciente;

    private Usuario $usuarioMedico;

    private Paciente $paciente;

    private Medico $medico;

    private Especialidad $especialidad;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2035-03-04 10:00:00'));
        $this->recepcion = $this->crearUsuario('t-mej-recep', [Rol::RECEPCIONISTA]);
        $this->usuarioPaciente = $this->crearUsuario('t-mej-pac', [Rol::PACIENTE]);
        $this->usuarioMedico = $this->crearUsuario('t-mej-med', [Rol::MEDICO]);
        $this->paciente = $this->crearPaciente(['usuario_id' => $this->usuarioPaciente->id, 'nombres' => 'Mejora', 'apellidos' => 'Tpaciente']);
        $this->especialidad = $this->crearEspecialidad('T-Mejoras', precio: 100);
        $this->medico = $this->crearMedico(['usuario_id' => $this->usuarioMedico->id, 'nombres' => 'Doctor', 'apellidos' => 'Tmejoras', 'puede_marcar_atendida' => true], [$this->especialidad]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cita(string $inicio, string $estado = Cita::PROGRAMADA, string $fecha = '2035-03-04', array $extra = []): Cita
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $fecha, $inicio, date('H:i', strtotime($inicio) + 1800), $estado);
        $cita->update($extra + ['monto' => 100, 'metodo_pago' => 'EFECTIVO', 'estado_pago' => 'PENDIENTE']);

        return $cita->fresh();
    }

    // ---------- 1. Mis citas: citas pasadas ----------

    #[Test]
    public function mis_citas_dice_que_la_cita_ya_paso_y_no_ofrece_cancelar(): void
    {
        $pasada = $this->cita('08:00'); // ya terminó (son las 10:00)

        $pantalla = $this->actingAs($this->usuarioPaciente)->get(route('historial.index'))->assertOk()
            ->assertSee($pasada->codigo)->assertSee('Esta cita ya pasó')->assertDontSee('Faltan menos de 2 h');
        $this->assertStringNotContainsString(route('citas.cancelar', $pasada), $pantalla->getContent(), 'No se ofrece cancelar una cita pasada.');
        $this->assertStringNotContainsString('Reprogramar', $pantalla->getContent());
    }

    #[Test]
    public function mis_citas_mantiene_el_aviso_de_menos_de_2_horas_para_las_citas_proximas_y_el_boton_para_las_lejanas(): void
    {
        $proxima = $this->cita('11:00');             // dentro de 1 hora
        $lejana = $this->cita('09:00', fecha: '2035-03-06');

        $pantalla = $this->actingAs($this->usuarioPaciente)->get(route('historial.index'))->assertOk()
            ->assertSee('Faltan menos de 2 h')->assertDontSee('Esta cita ya pasó');
        $this->assertStringNotContainsString(route('citas.cancelar', $proxima), $pantalla->getContent());
        $this->assertStringContainsString(route('citas.cancelar', $lejana), $pantalla->getContent());
    }

    #[Test]
    public function el_paciente_tampoco_puede_cancelar_una_cita_pasada_por_la_url(): void
    {
        $pasada = $this->cita('08:00');

        $this->actingAs($this->usuarioPaciente)->post(route('citas.cancelar.guardar', $pasada), ['motivo' => 'Ya pasó'])->assertRedirect();
        $this->assertSame(Cita::PROGRAMADA, $pasada->fresh()->estado);
    }

    // ---------- 2. Vencidas: Programada o Confirmada con la hora ya pasada ----------

    #[Test]
    public function el_modelo_marca_como_vencida_solo_la_cita_abierta_cuya_hora_final_ya_paso(): void
    {
        $this->assertTrue($this->cita('08:00')->vencida);
        $this->assertTrue($this->cita('08:30', Cita::CONFIRMADA)->vencida);
        $this->assertFalse($this->cita('10:00')->vencida, 'Si está en curso no está vencida.');
        $this->assertFalse($this->cita('11:00')->vencida);
        $this->assertFalse($this->cita('09:00', Cita::ATENDIDA)->vencida);
        $this->assertFalse($this->cita('12:00', Cita::CANCELADA)->vencida);
        $this->assertFalse($this->cita('13:00', Cita::INASISTENCIA)->vencida);
        $this->assertFalse($this->cita('08:00', fecha: '2035-03-05')->vencida);
    }

    #[Test]
    public function control_de_citas_agenda_y_mis_citas_muestran_la_senal_de_vencida_sin_cambiar_el_estado(): void
    {
        $vencida = $this->cita('08:00', Cita::CONFIRMADA);
        $enOrden = $this->cita('11:00');

        $this->actingAs($this->recepcion)->get(route('control.index', ['fecha' => '2035-03-04']))->assertOk()
            ->assertSee('Vencida · pendiente de cierre')->assertSee($vencida->codigo)->assertSee('fila-alerta', false);
        $this->get(route('agenda.index', ['medico' => $this->medico->id, 'desde' => '2035-03-04']))->assertOk()->assertSee('Vencida · pendiente de cierre');
        $this->actingAs($this->usuarioPaciente)->get(route('historial.index'))->assertOk()->assertSee('Vencida · pendiente de cierre');

        $this->assertSame(Cita::CONFIRMADA, $vencida->fresh()->estado, 'Es una señal: el estado no se toca.');
        $this->assertSame(Cita::PROGRAMADA, $enOrden->fresh()->estado);
        $this->assertSame(1, substr_count($this->actingAs($this->recepcion)->get(route('control.index', ['fecha' => '2035-03-04']))->getContent(), 'Vencida · pendiente de cierre'));
    }

    #[Test]
    public function una_cita_vencida_se_cierra_con_las_acciones_de_siempre_y_deja_la_bitacora(): void
    {
        $vencida = $this->cita('08:00', Cita::CONFIRMADA);

        // La recepción la cierra como inasistencia (la hora ya pasó): queda en la bitácora y deja de estar vencida.
        $this->actingAs($this->recepcion)->post(route('citas.estado', $vencida), ['accion' => 'inasistencia'])->assertRedirect();

        $this->assertSame(Cita::INASISTENCIA, $vencida->fresh()->estado);
        $this->assertFalse($vencida->fresh()->vencida);
        $this->assertDatabaseHas('bitacora', ['accion' => 'INASISTENCIA_REGISTRADA', 'usuario_id' => $this->recepcion->id]);
    }

    #[Test]
    public function nadie_marca_automaticamente_la_cita_como_atendida_solo_el_medico_de_la_cita(): void
    {
        $vencida = $this->cita('08:00', Cita::CONFIRMADA);

        $this->actingAs($this->recepcion)->post(route('citas.estado', $vencida), ['accion' => 'atendida'])->assertRedirect();
        $this->assertSame(Cita::CONFIRMADA, $vencida->fresh()->estado, 'La recepción no puede marcarla atendida.');

        $this->actingAs($this->usuarioMedico)->post(route('citas.estado', $vencida), ['accion' => 'atendida'])->assertRedirect();
        $this->assertSame(Cita::ATENDIDA, $vencida->fresh()->estado);
    }

    #[Test]
    public function el_reporte_cuenta_las_vencidas_y_marca_cada_una_en_el_detalle_y_en_el_csv(): void
    {
        $admin = $this->crearUsuario('t-mej-admin', [Rol::ADMINISTRADOR]);
        $vencida = $this->cita('08:00');
        $this->cita('11:00');
        $filtros = ['tipo' => 'por_estado', 'desde' => '2035-03-04', 'hasta' => '2035-03-04', 'ia' => 'sin', 'medico' => $this->medico->id];

        $respuesta = $this->actingAs($admin)->get('/reportes?'.http_build_query($filtros))->assertOk()
            ->assertSee('Vencidas (pendientes de cierre)')->assertSee('Vencida · pendiente de cierre');
        $reporte = $respuesta->viewData('reporte');
        $this->assertSame(2, $reporte['totales']['total']);
        $this->assertSame(1, $reporte['totales']['vencidas']);

        $csv = $this->get('/reportes/exportar/csv?'.http_build_query($filtros))->streamedContent();
        $this->assertStringContainsString('Vencidas (pendientes de cierre);1', $csv);
        $this->assertStringContainsString($vencida->codigo, $csv);
        $this->assertStringContainsString('Programada (vencida: pendiente de cierre)', $csv);
        $this->get('/reportes/exportar/pdf?'.http_build_query($filtros))->assertOk();
    }

    // ---------- 3. Atendida con el pago pendiente ----------

    #[Test]
    public function control_de_citas_advierte_antes_de_marcar_atendida_con_el_pago_pendiente(): void
    {
        $this->cita('09:00', Cita::CONFIRMADA, extra: ['estado_pago' => 'PENDIENTE']);
        $pagada = $this->cita('09:30', Cita::CONFIRMADA, extra: ['estado_pago' => 'PAGADO', 'fecha_pago' => now()]);

        $pantalla = $this->actingAs($this->usuarioMedico)->get(route('control.index', ['fecha' => '2035-03-04']))->assertOk()
            ->assertSee('Pago pendiente: al marcarla atendida, recepción deberá cobrarla.')
            ->assertSee('El pago de esta cita está pendiente. ¿Marcarla como atendida de todos modos?');
        $this->assertSame(1, substr_count($pantalla->getContent(), 'onsubmit="return confirm('), 'Solo la cita con el pago pendiente pide confirmar.');
        $this->assertSame(Cita::CONFIRMADA, $pagada->fresh()->estado);
    }

    #[Test]
    public function al_marcar_atendida_con_pago_pendiente_se_avisa_se_registra_y_se_resalta_el_cobro(): void
    {
        $cita = $this->cita('09:00', Cita::CONFIRMADA);

        $this->actingAs($this->usuarioMedico)->post(route('citas.estado', $cita), ['accion' => 'atendida'])
            ->assertRedirect()->assertSessionHas('exito', fn ($m) => str_contains($m, 'marcada como atendida') && str_contains($m, 'sigue pendiente') && str_contains($m, 'Bs 100,00'));

        $this->assertSame(Cita::ATENDIDA, $cita->fresh()->estado);
        $this->assertSame('PENDIENTE', $cita->fresh()->estado_pago, 'No se cobra sola.');
        $this->assertStringContainsString('con el pago pendiente', DB::table('bitacora')->where('accion', 'CITA_ATENDIDA')->latest('id')->value('detalle'));

        $this->actingAs($this->recepcion)->get(route('control.index', ['fecha' => '2035-03-04']))->assertOk()
            ->assertSee('Atendida sin cobrar')->assertSee('fila-alerta', false)->assertSee('registre el cobro de Bs 100,00');

        // La recepción registra el cobro y la advertencia desaparece.
        $this->post(route('citas.estado', $cita), ['accion' => 'cobrar'])->assertRedirect();
        $this->get(route('control.index', ['fecha' => '2035-03-04']))->assertOk()->assertDontSee('Atendida sin cobrar');
    }

    #[Test]
    public function marcar_atendida_con_la_cita_pagada_no_trae_advertencia(): void
    {
        $cita = $this->cita('09:00', Cita::CONFIRMADA, extra: ['estado_pago' => 'PAGADO', 'fecha_pago' => now()]);

        $this->actingAs($this->usuarioMedico)->post(route('citas.estado', $cita), ['accion' => 'atendida'])
            ->assertSessionHas('exito', fn ($m) => ! str_contains($m, 'pendiente'));
        $this->assertStringNotContainsString('pago pendiente', DB::table('bitacora')->where('accion', 'CITA_ATENDIDA')->latest('id')->value('detalle'));
    }

    #[Test]
    public function la_consulta_clinica_tambien_avisa_si_la_cita_queda_atendida_con_el_pago_pendiente(): void
    {
        $cita = $this->cita('09:00', Cita::CONFIRMADA);

        $this->actingAs($this->usuarioMedico)->post(route('consultas.guardar', $cita), ['diagnostico' => 'Control'])
            ->assertRedirect()->assertSessionHas('exito', fn ($m) => str_contains($m, 'pasó a Atendida') && str_contains($m, 'sigue pendiente'));
        $this->assertSame(Cita::ATENDIDA, $cita->fresh()->estado);
    }

    #[Test]
    public function los_reportes_cuentan_las_atendidas_por_cobrar_sin_mezclarlas_con_los_ingresos(): void
    {
        $admin = $this->crearUsuario('t-mej-admin2', [Rol::ADMINISTRADOR]);
        $this->cita('08:00', Cita::ATENDIDA);                                                                   // por cobrar: Bs 100
        $this->cita('08:30', Cita::ATENDIDA, extra: ['estado_pago' => 'PAGADO', 'fecha_pago' => '2035-03-04 08:50:00']); // cobrada
        $filtros = ['desde' => '2035-03-04', 'hasta' => '2035-03-04', 'ia' => 'sin', 'medico' => $this->medico->id];

        $citas = $this->actingAs($admin)->get('/reportes?'.http_build_query($filtros + ['tipo' => 'por_estado']))->assertOk()
            ->assertSee('Atendidas con el pago pendiente')->viewData('reporte');
        $this->assertSame(1, $citas['totales']['atendidas_sin_cobro']);
        $this->assertSame(100.0, $citas['totales']['por_cobrar_atendidas']);

        $ingresos = $this->get('/reportes?'.http_build_query($filtros + ['tipo' => 'ingresos']))->assertOk()->viewData('reporte');
        $this->assertSame(1, $ingresos['totales']['total'], 'Solo la cobrada cuenta como ingreso.');
        $this->assertSame(100.0, $ingresos['totales']['ingreso_total']);
    }

    // ---------- 4. /agenda-medica ----------

    #[Test]
    public function agenda_medica_redirige_a_agenda_con_los_mismos_permisos_y_conserva_los_filtros(): void
    {
        $this->get('/agenda-medica')->assertRedirect(route('login'));

        $admin = $this->crearUsuario('t-mej-admin3', [Rol::ADMINISTRADOR]);
        foreach ([$this->recepcion, $admin, $this->usuarioMedico] as $usuario) {
            $this->actingAs($usuario)->get('/agenda-medica?desde=2035-03-04&hasta=2035-03-10')
                ->assertRedirect('/agenda?desde=2035-03-04&hasta=2035-03-10');
        }
        $this->actingAs($this->usuarioPaciente)->get('/agenda-medica')->assertForbidden();
        $this->followingRedirects()->actingAs($this->usuarioMedico)->get('/agenda-medica')->assertOk()->assertSee('Agenda médica');
    }

    // ---------- 5. Agenda: hoy y próximos 7 días ----------

    #[Test]
    public function la_agenda_abre_en_hoy_y_ofrece_los_accesos_hoy_y_proximos_7_dias(): void
    {
        $this->cita('11:00');

        $this->actingAs($this->usuarioMedico)->get('/agenda')->assertOk()
            ->assertSee('value="2035-03-04"', false)->assertSee('Hoy')->assertSee('Próximos 7 días')
            ->assertSee(e(route('agenda.index', ['medico' => $this->medico->id, 'desde' => '2035-03-04', 'hasta' => '2035-03-10'])), false)
            ->assertSee(e(route('agenda.index', ['medico' => $this->medico->id, 'desde' => '2035-03-04'])), false);

        $this->actingAs($this->recepcion)->get(route('agenda.index', ['medico' => $this->medico->id]))->assertOk()
            ->assertSee(e(route('agenda.index', ['medico' => $this->medico->id, 'desde' => '2035-03-04', 'hasta' => '2035-03-10'])), false);
    }
}
