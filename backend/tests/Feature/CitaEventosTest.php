<?php

namespace Tests\Feature;

use App\Models\Cita;
use App\Models\CitaEvento;
use App\Models\Rol;
use App\Modules\GestionCitas\Compartido\ReglaCitaException;
use App\Modules\GestionCitas\CU09_RegistrarCitaMedica\RegistrarCitaService;
use App\Modules\GestionCitas\CU10_ReprogramarCitaMedica\ReprogramarCitaService;
use App\Modules\GestionCitas\CU11_ReservarCitaEnLinea\ReservarCitaService;
use App\Modules\GestionCitas\CU12_CancelarCitaMedica\CancelarCitaService;
use App\Support\EventosDeCitasDesdeBitacora;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * CU-10, CU-12, CU-13 y CU-15 (extra aprobado): historial de cambios de cada cita (CitaEvento), contadores por estado en «Mis citas»
 * e insignia «Reprogramada» con «Ver cambios».
 */
class CitaEventosTest extends SistemaTestCase
{
    private $recepcion;

    private $admin;

    private $usuarioPaciente;

    private $paciente;

    private $medico;

    private $especialidad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recepcion = $this->crearUsuario('t-evt-recep', [Rol::RECEPCIONISTA]);
        $this->admin = $this->crearUsuario('t-evt-admin', [Rol::ADMINISTRADOR]);
        $this->usuarioPaciente = $this->crearUsuario('t-evt-pac', [Rol::PACIENTE]);
        $this->paciente = $this->crearPaciente(['usuario_id' => $this->usuarioPaciente->id]);
        $this->especialidad = $this->crearEspecialidad('T-Evt', true, 'CONSULTA', 50);
        $this->medico = $this->crearMedico([], [$this->especialidad]);
        $this->crearHorario($this->medico, 1, '08:00', '12:00', 30);
    }

    private function cita(string $estado = Cita::PROGRAMADA, string $fecha = '2026-01-12', string $inicio = '09:00', string $fin = '09:30'): Cita
    {
        return $this->crearCita($this->medico, $this->paciente, $this->especialidad, $fecha, $inicio, $fin, $estado);
    }

    private function ultimoEvento(Cita $cita): CitaEvento
    {
        return CitaEvento::where('cita_id', $cita->id)->orderByDesc('id')->firstOrFail();
    }

    // ---------- Cada operación deja su evento ----------

    #[Test]
    public function registrar_y_reservar_en_linea_crean_el_evento_registrada(): void
    {
        $lunes = $this->proximaFecha(1);
        $cita = app(RegistrarCitaService::class)->registrar($this->paciente, $this->especialidad, $this->medico, $lunes, '08:00', 'PRESENCIAL', null, $this->recepcion);
        $e = $this->ultimoEvento($cita);
        $this->assertSame([CitaEvento::REGISTRADA, $this->recepcion->id, $lunes, '08:00:00', Cita::PROGRAMADA], [$e->tipo, $e->usuario_id, $e->fecha_nueva->toDateString(), $e->hora_nueva, $e->estado_nuevo]);

        $enLinea = app(ReservarCitaService::class)->reservar($this->paciente, $this->especialidad, $this->medico, $lunes, '08:30', 'PRESENCIAL', null, $this->usuarioPaciente);
        $e = $this->ultimoEvento($enLinea);
        $this->assertSame([CitaEvento::REGISTRADA, $this->usuarioPaciente->id, '08:30:00'], [$e->tipo, $e->usuario_id, $e->hora_nueva]);
    }

    #[Test]
    public function confirmar_inasistencia_y_atendida_crean_su_evento_con_los_estados(): void
    {
        $cita = $this->cita();
        $this->actingAs($this->recepcion)->post(route('citas.estado', $cita), ['accion' => 'confirmar']);
        $e = $this->ultimoEvento($cita);
        $this->assertSame([CitaEvento::CONFIRMADA, Cita::PROGRAMADA, Cita::CONFIRMADA, $this->recepcion->id], [$e->tipo, $e->estado_anterior, $e->estado_nuevo, $e->usuario_id]);

        $this->post(route('citas.estado', $cita), ['accion' => 'inasistencia']);
        $e = $this->ultimoEvento($cita);
        $this->assertSame([CitaEvento::INASISTENCIA, Cita::CONFIRMADA, Cita::INASISTENCIA], [$e->tipo, $e->estado_anterior, $e->estado_nuevo]);

        $usuarioMedico = $this->crearUsuario('t-evt-med', [Rol::MEDICO]);
        $this->medico->update(['usuario_id' => $usuarioMedico->id, 'puede_marcar_atendida' => true]);
        $otra = $this->cita(Cita::CONFIRMADA, '2026-01-12', '10:00', '10:30');
        $this->actingAs($usuarioMedico)->post(route('citas.estado', $otra), ['accion' => 'atendida']);
        $e = $this->ultimoEvento($otra);
        $this->assertSame([CitaEvento::ATENDIDA, Cita::CONFIRMADA, Cita::ATENDIDA, $usuarioMedico->id], [$e->tipo, $e->estado_anterior, $e->estado_nuevo, $e->usuario_id]);
    }

    #[Test]
    public function corregir_el_estado_guarda_la_justificacion_en_el_evento(): void
    {
        $cita = $this->cita(Cita::ATENDIDA);

        $this->actingAs($this->admin)->post(route('citas.estado', $cita), ['accion' => 'corregir', 'estado_nuevo' => 'CANCELADA', 'justificacion' => 'Se marcó por error']);

        $e = $this->ultimoEvento($cita);
        $this->assertSame([CitaEvento::ESTADO_CORREGIDO, Cita::ATENDIDA, Cita::CANCELADA, 'Se marcó por error', $this->admin->id], [$e->tipo, $e->estado_anterior, $e->estado_nuevo, $e->motivo, $e->usuario_id]);
    }

    #[Test]
    public function reprogramar_guarda_fecha_y_hora_anteriores_y_nuevas_con_el_motivo(): void
    {
        $lunes = $this->proximaFecha(1);
        $cita = $this->cita(Cita::INASISTENCIA, $lunes, '09:00', '09:30');
        $cita->update(['monto' => 50, 'metodo_pago' => 'EFECTIVO', 'estado_pago' => 'PAGADO', 'fecha_pago' => now()]);
        $nuevo = Carbon::parse($lunes)->addWeek()->toDateString();

        app(ReprogramarCitaService::class)->reprogramar($cita, $nuevo, '08:00', 'El paciente pidió otro día', $this->recepcion);

        $e = $this->ultimoEvento($cita);
        $this->assertSame(
            [CitaEvento::REPROGRAMADA, $lunes, '09:00:00', $nuevo, '08:00:00', Cita::INASISTENCIA, Cita::PROGRAMADA, 'El paciente pidió otro día', $this->recepcion->id],
            [$e->tipo, $e->fecha_anterior->toDateString(), $e->hora_anterior, $e->fecha_nueva->toDateString(), $e->hora_nueva, $e->estado_anterior, $e->estado_nuevo, $e->motivo, $e->usuario_id]
        );
    }

    #[Test]
    public function una_reprogramacion_rechazada_no_deja_evento(): void
    {
        $cita = $this->cita(Cita::ATENDIDA);

        try {
            app(ReprogramarCitaService::class)->reprogramar($cita, $this->proximaFecha(1), '08:00', 'x', $this->recepcion);
            $this->fail('Debía rechazarse.');
        } catch (ReglaCitaException) {
        }

        $this->assertSame(0, CitaEvento::where('cita_id', $cita->id)->count());
    }

    #[Test]
    public function cancelar_guarda_el_motivo_y_quien_cancelo(): void
    {
        $cita = $this->cita(Cita::PROGRAMADA, $this->proximaFecha(1));

        app(CancelarCitaService::class)->cancelar($cita, $this->usuarioPaciente, 'Viajo ese día', false);

        $e = $this->ultimoEvento($cita);
        $this->assertSame([CitaEvento::CANCELADA, Cita::PROGRAMADA, Cita::CANCELADA, 'Viajo ese día', $this->usuarioPaciente->id], [$e->tipo, $e->estado_anterior, $e->estado_nuevo, $e->motivo, $e->usuario_id]);
    }

    #[Test]
    public function si_la_transaccion_falla_no_queda_un_evento_sin_su_cambio(): void
    {
        $cita = $this->cita();

        try {
            DB::transaction(function () use ($cita) {
                CitaEvento::registrar($cita, CitaEvento::CONFIRMADA, $this->recepcion->id, ['estado_nuevo' => Cita::CONFIRMADA]);
                throw new \RuntimeException('falla');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, CitaEvento::where('cita_id', $cita->id)->count());
    }

    // ---------- «Mis citas»: contadores, cinco estados, insignia y «Ver cambios» ----------

    #[Test]
    public function sin_filtro_el_paciente_ve_las_citas_de_los_cinco_estados_y_los_contadores(): void
    {
        $citas = [];
        foreach ([Cita::PROGRAMADA, Cita::CONFIRMADA, Cita::ATENDIDA, Cita::CANCELADA, Cita::INASISTENCIA] as $i => $estado) {
            $citas[$estado] = $this->cita($estado, '2026-02-0'.($i + 1));
        }

        $pantalla = $this->actingAs($this->usuarioPaciente)->get(route('historial.index'))->assertOk()
            ->assertSee('Todas 5')->assertSee('Pendientes 2')->assertSee('Atendidas 1')->assertSee('Canceladas 1')->assertSee('Inasistencias 1');
        foreach ($citas as $cita) {
            $pantalla->assertSee($cita->codigo);
        }

        // Los enlaces aplican el filtro.
        $this->get(route('historial.index', ['estado' => 'canceladas']))->assertOk()->assertSee($citas[Cita::CANCELADA]->codigo)->assertDontSee($citas[Cita::ATENDIDA]->codigo);
        $this->get(route('historial.index'))->assertSee(route('historial.index', ['estado' => 'pendientes']), false);
    }

    #[Test]
    public function la_cita_reprogramada_muestra_la_insignia_y_ver_cambios_sin_nombres_del_personal(): void
    {
        $cita = $this->cita(Cita::PROGRAMADA, '2026-12-17', '08:00', '08:30');
        $crear = fn (string $tipo, array $d, $usuario, string $cuando) => CitaEvento::create(['cita_id' => $cita->id, 'tipo' => $tipo, 'usuario_id' => $usuario->id, 'created_at' => $cuando] + $d);
        $crear('REGISTRADA', ['estado_nuevo' => 'PROGRAMADA'], $this->usuarioPaciente, '2026-11-13 08:31:00');
        $crear('CONFIRMADA', ['estado_anterior' => 'PROGRAMADA', 'estado_nuevo' => 'CONFIRMADA'], $this->recepcion, '2026-11-13 09:00:00');
        $crear('INASISTENCIA', ['estado_anterior' => 'CONFIRMADA', 'estado_nuevo' => 'INASISTENCIA'], $this->recepcion, '2026-11-13 15:00:00');
        $crear('REPROGRAMADA', ['fecha_anterior' => '2026-11-13', 'hora_anterior' => '14:00', 'fecha_nueva' => '2026-11-17', 'hora_nueva' => '08:00', 'motivo' => 'Cambio de turno'], $this->recepcion, '2026-11-13 16:00:00');
        $crear('CANCELADA', ['estado_nuevo' => 'CANCELADA', 'motivo' => 'Ya no lo necesito'], $this->usuarioPaciente, '2026-11-14 10:00:00');
        $cita->update(['estado_pago' => 'PAGADO', 'monto' => 50, 'metodo_pago' => 'TARJETA']);

        $this->actingAs($this->usuarioPaciente)->get(route('historial.index'))->assertOk()
            ->assertSee('Reprogramada')->assertSee('Ver cambios')
            ->assertSeeInOrder(['Reservada', 'Confirmada', 'Inasistencia registrada · pago conservado', 'Reprogramada: de 13/11 14:00 a 17/11 08:00', 'motivo: Cambio de turno', 'Cancelada', 'por usted'])
            ->assertDontSee('t-evt-recep')->assertDontSee('t-evt-pac');

        // El personal ve los mismos cambios con el nombre de usuario.
        $this->actingAs($this->recepcion)->get(route('historial.index', ['paciente' => $this->paciente->id]))->assertOk()
            ->assertSee('Ver cambios')->assertSee('por t-evt-recep');
    }

    #[Test]
    public function una_cita_con_un_solo_evento_no_muestra_ver_cambios(): void
    {
        $cita = $this->cita();
        CitaEvento::registrar($cita, CitaEvento::REGISTRADA, $this->usuarioPaciente->id, ['estado_nuevo' => 'PROGRAMADA']);

        $this->actingAs($this->usuarioPaciente)->get(route('historial.index'))->assertOk()->assertSee($cita->codigo)->assertDontSee('Ver cambios')->assertDontSee('etiqueta-reprogramada', false);
    }

    #[Test]
    public function un_paciente_no_ve_los_cambios_de_la_cita_de_otro(): void
    {
        $otroUsuario = $this->crearUsuario('t-evt-otro', [Rol::PACIENTE]);
        $otro = $this->crearPaciente(['usuario_id' => $otroUsuario->id]);
        $ajena = $this->crearCita($this->medico, $otro, $this->especialidad, '2026-03-02', '09:00', '09:30');
        CitaEvento::registrar($ajena, CitaEvento::REGISTRADA, $otroUsuario->id, ['estado_nuevo' => 'PROGRAMADA']);
        CitaEvento::registrar($ajena, CitaEvento::CANCELADA, $otroUsuario->id, ['estado_nuevo' => 'CANCELADA', 'motivo' => 'Motivo secreto del otro paciente']);

        $this->actingAs($this->usuarioPaciente)->get(route('historial.index', ['paciente' => $otro->id]))->assertForbidden();
        $this->get(route('historial.index'))->assertOk()->assertDontSee($ajena->codigo)->assertDontSee('Motivo secreto del otro paciente');
    }

    // ---------- Relleno desde la bitácora ----------

    #[Test]
    public function el_relleno_desde_la_bitacora_reconstruye_el_caso_cm_0028_y_es_idempotente(): void
    {
        $cita = $this->cita(Cita::PROGRAMADA, '2026-11-17', '08:00', '08:30');
        $cita->update(['codigo' => 'CM-9928']);
        $bitacora = fn (string $accion, string $detalle, string $cuando) => DB::table('bitacora')->insert(['usuario_id' => $this->recepcion->id, 'accion' => $accion, 'entidad' => 'Cita', 'detalle' => $detalle, 'fecha_hora' => $cuando]);
        $bitacora('CITA_RESERVADA', 'Cita CM-9928 reservada en línea por Ana con Dr. X el 13/11/2026 14:00 (PRESENCIAL); pago: Tarjeta', '2026-11-13 08:31:00');
        $bitacora('CITA_CONFIRMADA', 'Cita CM-9928 confirmada (antes Programada)', '2026-11-13 09:00:00');
        $bitacora('INASISTENCIA_REGISTRADA', 'Inasistencia registrada en la cita CM-9928 (pago conservado: puede reprogramarse)', '2026-11-13 15:00:00');
        $bitacora('CITA_REPROGRAMADA', 'Cita CM-9928 (inasistencia pagada, sin nuevo cobro) reprogramada de 13/11/2026 14:00 – 14:30 a 17/11/2026 08:00 – 08:30. Motivo: Pidió otro día', '2026-11-13 16:00:00');
        $bitacora('CITA_CANCELADA', 'Cita CM-9999 cancelada. Motivo: no existe la cita', '2026-11-13 17:00:00'); // código sin cita: se omite
        $bitacora('CITA_REPROGRAMADA', 'Cita CM-9928 reprogramada con un texto raro', '2026-11-13 18:00:00'); // no coincide: se omite

        EventosDeCitasDesdeBitacora::ejecutar();

        $eventos = CitaEvento::where('cita_id', $cita->id)->orderBy('id')->get();
        $this->assertSame(['REGISTRADA', 'CONFIRMADA', 'INASISTENCIA', 'REPROGRAMADA'], $eventos->pluck('tipo')->all());
        $r = $eventos->last();
        $this->assertSame(['2026-11-13', '14:00:00', '2026-11-17', '08:00:00', 'Pidió otro día', Cita::INASISTENCIA], [$r->fecha_anterior->toDateString(), $r->hora_anterior, $r->fecha_nueva->toDateString(), $r->hora_nueva, $r->motivo, $r->estado_anterior]);

        $this->assertSame(0, EventosDeCitasDesdeBitacora::ejecutar(), 'Una segunda ejecución no repite eventos.');
        $this->assertSame(4, CitaEvento::where('cita_id', $cita->id)->count());
    }
}
