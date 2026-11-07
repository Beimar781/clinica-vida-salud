<?php

namespace Tests\Feature;

use App\Models\BloqueoAgenda;
use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\GestionCitas\Compartido\AgendarCitaService;
use App\Modules\GestionCitas\Compartido\Disponibilidad;
use App\Modules\GestionCitas\Compartido\DisponibilidadService;
use App\Modules\GestionCitas\Compartido\HorarioOcupadoException;
use App\Modules\GestionCitas\CU10_ReprogramarCitaMedica\ReprogramarCitaService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/** CU-10: Reprogramar cita médica. Los métodos paso_N son las filas del plan de pruebas 8.1 (v30: incluye la inasistencia pagada). */
class CU10_ReprogramarCitaMedicaTest extends SistemaTestCase
{
    private Usuario $recepcion;

    private Especialidad $especialidad;

    private Medico $medico;

    private Paciente $paciente;

    private string $lunes;

    private string $otroLunes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recepcion = $this->crearUsuario('t-recep-rep', [Rol::RECEPCIONISTA]);
        $this->especialidad = $this->crearEspecialidad('T-Reprogramar');
        $this->medico = $this->crearMedico([], [$this->especialidad]);
        $this->crearHorario($this->medico, 1, '08:00', '12:00', 30);
        $this->paciente = $this->crearPaciente(['nombres' => 'Reprog', 'apellidos' => 'Tprueba']);
        $this->lunes = $this->proximaFecha(1);
        // El lunes siguiente al primero (si hay un feriado de por medio, el primer lunes libre posterior).
        $this->otroLunes = $this->proximaFecha(1, (int) Carbon::today()->diffInDays(Carbon::parse($this->lunes)) + 1);
    }

    /** Paso 1: reprogramar una cita a un horario libre (cita Programada y nuevo horario libre del mismo médico) → la cita cambia de fecha y hora, conserva su código y el horario anterior queda libre. */
    #[Test]
    public function paso_1_reprogramar_una_cita_a_un_horario_libre(): void
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');
        $codigo = $cita->codigo;

        $this->actingAs($this->recepcion)->put(route('citas.actualizar', $cita), $this->datos())
            ->assertRedirect(route('citas.reprogramada', $cita))
            ->assertSessionHas('exito')
            ->assertSessionHas('reprogramacion.motivo', 'El paciente pidió otro día');

        $cita->refresh();
        $this->assertSame($codigo, $cita->codigo);
        $this->assertSame($this->otroLunes, $cita->fecha->toDateString());
        $this->assertSame('10:00', $cita->hora_inicio_corta);
        $this->assertSame('10:30', $cita->hora_fin_corta);
        $this->assertSame(Cita::PROGRAMADA, $cita->estado);
        $this->assertDatabaseHas('bitacora', ['accion' => 'CITA_REPROGRAMADA', 'usuario_id' => $this->recepcion->id]);

        // El horario anterior queda libre y el nuevo ocupado.
        $disponibilidad = app(DisponibilidadService::class);
        $this->assertNotNull($disponibilidad->calcularLibres($this->medico, $this->lunes)->libre('09:00'));
        $this->assertNull($disponibilidad->calcularLibres($this->medico, $this->otroLunes)->libre('10:00'));

        // El comprobante actualizado muestra el horario anterior y el motivo.
        $this->get(route('citas.reprogramada', $cita))->assertOk()
            ->assertSee($codigo)->assertSee('10:00 – 10:30')->assertSee('Horario anterior')->assertSee('El paciente pidió otro día');
    }

    /** Paso 2: reprogramar una cita con inasistencia ya pagada (cita en estado Inasistencia con pago registrado y nuevo horario libre) → la cita se reprograma sin un nuevo cobro y conserva su pago. */
    #[Test]
    public function paso_2_reprogramar_una_cita_con_inasistencia_ya_pagada(): void
    {
        Http::fake(); // sin nuevo cobro: no se habla con Stripe
        $cita = $this->citaConInasistenciaPagada();
        $pagoAntes = $cita->only(['monto', 'metodo_pago', 'estado_pago', 'referencia_pago']) + ['fecha_pago' => $cita->fecha_pago->toDateTimeString()];
        $codigo = $cita->codigo;

        $this->actingAs($this->recepcion)->put(route('citas.actualizar', $cita), $this->datos())
            ->assertRedirect(route('citas.reprogramada', $cita))
            ->assertSessionHas('exito')
            ->assertSessionHas('reprogramacion.anterior.pago_conservado', true);

        $cita->refresh();
        $this->assertSame($codigo, $cita->codigo, 'Conserva su código.');
        $this->assertSame(Cita::PROGRAMADA, $cita->estado, 'La cita vuelve a Programada.');
        $this->assertSame($this->otroLunes, $cita->fecha->toDateString());
        $this->assertSame('10:00', $cita->hora_inicio_corta);
        $this->assertSame($pagoAntes, $cita->only(['monto', 'metodo_pago', 'estado_pago', 'referencia_pago']) + ['fecha_pago' => $cita->fecha_pago->toDateTimeString()], 'El pago se conserva tal cual: sin nuevo cobro.');
        $this->assertSame(Cita::PAGO_PAGADO, $cita->estado_pago);
        Http::assertNothingSent();

        // El horario anterior (el de la inasistencia) queda libre y el nuevo ocupado.
        $disponibilidad = app(DisponibilidadService::class);
        $this->assertNotNull($disponibilidad->calcularLibres($this->medico, $this->lunes)->libre('09:00'));
        $this->assertNull($disponibilidad->calcularLibres($this->medico, $this->otroLunes)->libre('10:00'));

        // La bitácora guarda el motivo, el horario anterior y el nuevo.
        $this->assertDatabaseHas('bitacora', [
            'accion' => 'CITA_REPROGRAMADA', 'usuario_id' => $this->recepcion->id,
            'detalle' => "Cita {$codigo} (inasistencia pagada, sin nuevo cobro) reprogramada de ".Carbon::parse($this->lunes)->format('d/m/Y').' 09:00 – 09:30 a '
                .Carbon::parse($this->otroLunes)->format('d/m/Y').' 10:00 – 10:30. Motivo: El paciente pidió otro día',
        ]);

        // El comprobante actualizado muestra el pago conservado.
        $this->get(route('citas.reprogramada', $cita))->assertOk()
            ->assertSee('no se realizó un nuevo cobro')->assertSee('Bs 100,00')->assertSee('Tarjeta (Stripe)')->assertSee('Pagado')->assertSee('pi_inasistencia_1')
            ->assertSee('La cita estaba con inasistencia y volvió a Programada')->assertSee('Programada');
    }

    /** Paso 3: reprogramar una cita con inasistencia sin pago (cita en estado Inasistencia con pago Pendiente) → el sistema no permite reprogramarla y sugiere registrar una nueva cita. */
    #[Test]
    public function paso_3_reprogramar_una_cita_con_inasistencia_sin_pago(): void
    {
        $this->assertNoSePuedeReprogramar(Cita::INASISTENCIA, 'La cita está con inasistencia y sin pago: no se puede reprogramar. Registre una cita nueva.');
    }

    /** Paso 4: reprogramar a un horario ocupado (nuevo horario ya ocupado por otra cita) → el sistema rechaza el cambio y muestra los horarios libres. */
    #[Test]
    public function paso_4_reprogramar_a_un_horario_ocupado(): void
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');
        $this->crearCita($this->medico, $this->crearPaciente(), $this->especialidad, $this->otroLunes, '10:00', '10:30');

        $this->actingAs($this->recepcion)->put(route('citas.actualizar', $cita), $this->datos())
            ->assertRedirect(route('citas.reprogramar', ['cita' => $cita->id, 'fecha' => $this->otroLunes]))
            ->assertSessionHas('error', HorarioOcupadoException::MENSAJE);

        $cita->refresh();
        $this->assertSame($this->lunes, $cita->fecha->toDateString());
        $this->assertSame('09:00', $cita->hora_inicio_corta);

        $this->get(route('citas.reprogramar', ['cita' => $cita->id, 'fecha' => $this->otroLunes]))->assertOk()
            ->assertSee('El horario fue ocupado por otro usuario')->assertSee('10:30 – 11:00')->assertDontSee('10:00 – 10:30');
    }

    /** Paso 5: reprogramar una cita atendida (cita en estado Atendida) → el sistema no permite reprogramarla y sugiere registrar una nueva cita. */
    #[Test]
    public function paso_5_reprogramar_una_cita_atendida(): void
    {
        $this->assertNoSePuedeReprogramar(Cita::ATENDIDA, 'La cita está atendida: no se puede reprogramar. Registre una cita nueva.');
    }

    /** Paso 6: reprogramar una cita cancelada (cita en estado Cancelada) → el sistema no permite reprogramarla y sugiere registrar una nueva cita. */
    #[Test]
    public function paso_6_reprogramar_una_cita_cancelada(): void
    {
        $this->assertNoSePuedeReprogramar(Cita::CANCELADA, 'La cita está cancelada: no se puede reprogramar. Registre una cita nueva.');
    }

    #[Test]
    public function una_cita_confirmada_se_reprograma_y_vuelve_a_programada(): void
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30', Cita::CONFIRMADA);

        $this->actingAs($this->recepcion)->put(route('citas.actualizar', $cita), $this->datos())->assertRedirect(route('citas.reprogramada', $cita));

        $this->assertSame(Cita::PROGRAMADA, $cita->fresh()->estado);
    }

    #[Test]
    public function el_motivo_del_cambio_es_obligatorio(): void
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');

        $this->actingAs($this->recepcion)->put(route('citas.actualizar', $cita), $this->datos(['motivo_cambio' => '']))
            ->assertSessionHasErrors(['motivo_cambio' => 'Ingrese el motivo del cambio.']);
        $this->put(route('citas.actualizar', $cita), ['fecha' => 'x', 'hora_inicio' => ''])
            ->assertSessionHasErrors(['fecha' => 'La fecha no es válida.', 'hora_inicio' => 'Seleccione uno de los horarios libres.']);

        $this->assertSame($this->lunes, $cita->fresh()->fecha->toDateString());
    }

    #[Test]
    public function no_se_reprograma_a_una_fecha_pasada_a_un_feriado_ni_fuera_del_horario(): void
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');
        $this->actingAs($this->recepcion);

        $this->put(route('citas.actualizar', $cita), $this->datos(['fecha' => Carbon::yesterday()->toDateString()]))
            ->assertSessionHas('error', DisponibilidadService::MENSAJE_FECHA_PASADA);

        $this->crearBloqueo(['fecha' => $this->otroLunes, 'motivo' => 'T-Feriado', 'estado' => true]);
        $this->put(route('citas.actualizar', $cita), $this->datos())->assertSessionHas('error', fn ($m) => str_contains($m, 'feriado o día sin atención'));
        BloqueoAgenda::whereDate('fecha', $this->otroLunes)->delete();

        $this->put(route('citas.actualizar', $cita), $this->datos(['hora_inicio' => '15:00']))
            ->assertSessionHas('error', 'La hora elegida está fuera del horario de atención del médico.');
        $this->put(route('citas.actualizar', $cita), $this->datos(['fecha' => $this->proximaFecha(2)]))
            ->assertSessionHas('error', DisponibilidadService::MENSAJE_NO_ATIENDE);

        $this->assertSame($this->lunes, $cita->fresh()->fecha->toDateString());
    }

    #[Test]
    public function no_se_reprograma_al_mismo_horario_que_ya_tiene(): void
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');

        // Su propio horario aparece libre para ella misma (al reprogramar no choca consigo), pero igual se pide otro.
        $this->actingAs($this->recepcion)->put(route('citas.actualizar', $cita), $this->datos(['fecha' => $this->lunes, 'hora_inicio' => '09:00']))
            ->assertSessionHas('error', 'El nuevo horario es el mismo que ya tiene la cita. Elija otro.');
    }

    #[Test]
    public function se_puede_mover_dentro_del_mismo_dia_a_un_bloque_que_solapa_con_su_horario_actual(): void
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');

        $this->actingAs($this->recepcion)->put(route('citas.actualizar', $cita), $this->datos(['fecha' => $this->lunes, 'hora_inicio' => '09:30']))
            ->assertRedirect(route('citas.reprogramada', $cita));

        $this->assertSame('09:30', $cita->fresh()->hora_inicio_corta);
    }

    #[Test]
    public function el_paciente_no_puede_quedar_con_dos_citas_a_la_misma_hora(): void
    {
        $otroMedico = $this->crearMedico([], [$this->especialidad]);
        $this->crearHorario($otroMedico, 1, '08:00', '12:00', 30);
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');
        $this->crearCita($otroMedico, $this->paciente, $this->especialidad, $this->otroLunes, '10:00', '10:30');

        $this->actingAs($this->recepcion)->put(route('citas.actualizar', $cita), $this->datos())
            ->assertSessionHas('error', 'El paciente ya tiene una cita activa a esa hora.');
        $this->assertSame($this->lunes, $cita->fresh()->fecha->toDateString());
    }

    #[Test]
    public function la_base_de_datos_rechaza_la_superposicion_aunque_el_calculo_la_deje_pasar(): void
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');
        $this->crearCita($this->medico, $this->crearPaciente(), $this->especialidad, $this->otroLunes, '10:00', '10:30');
        $this->app->instance(DisponibilidadService::class, new class extends DisponibilidadService
        {
            public function calcularLibres(Medico $medico, Carbon|string $fecha, ?int $ignorarCitaId = null, ?Carbon $ahora = null): Disponibilidad
            {
                $bloque = ['inicio' => '10:00', 'fin' => '10:30'];

                return new Disponibilidad([$bloque], [$bloque], null, null);
            }
        });

        $this->expectException(HorarioOcupadoException::class);
        app(AgendarCitaService::class)->mover($cita, $this->otroLunes, '10:00');
    }

    #[Test]
    public function no_se_reprograma_con_un_medico_inactivo(): void
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');
        $this->medico->update(['estado' => false]);

        $this->actingAs($this->recepcion)->put(route('citas.actualizar', $cita), $this->datos())
            ->assertSessionHas('error', DisponibilidadService::MENSAJE_MEDICO_INACTIVO);
    }

    #[Test]
    public function la_pantalla_muestra_la_cita_y_los_horarios_libres_del_mismo_medico(): void
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');
        $this->actingAs($this->recepcion);

        $this->get(route('citas.reprogramar', $cita))->assertOk()
            ->assertSee($cita->codigo)->assertSee('Reprog Tprueba')->assertSee('Nueva fecha con '.$this->medico->nombre_completo)->assertDontSee('Confirmar reprogramación');

        $this->get(route('citas.reprogramar', ['cita' => $cita->id, 'fecha' => $this->otroLunes]))->assertOk()
            ->assertSee('Horarios libres (8)')->assertSee('Motivo del cambio')->assertSee('Confirmar reprogramación');

        $this->get(route('citas.reprogramar', ['cita' => $cita->id, 'fecha' => $this->proximaFecha(2)]))->assertOk()
            ->assertSee('El médico no atiende en esa fecha')->assertDontSee('Confirmar reprogramación');
    }

    #[Test]
    public function busca_la_cita_por_codigo_ci_o_nombre_del_paciente(): void
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');
        $otra = $this->crearCita($this->medico, $this->crearPaciente(['nombres' => 'Otra', 'apellidos' => 'Tdistinta']), $this->especialidad, $this->lunes, '10:00', '10:30', Cita::ATENDIDA);
        $this->actingAs($this->recepcion);

        $this->get(route('citas.index', ['q' => $cita->codigo]))->assertOk()->assertSee('Reprog Tprueba')->assertSee('Reprogramar</a>', false)->assertDontSee('Otra Tdistinta');
        $this->get(route('citas.index', ['q' => $this->paciente->ci]))->assertOk()->assertSee($cita->codigo)->assertDontSee($otra->codigo);
        $this->get(route('citas.index', ['q' => 'tdistinta']))->assertOk()->assertSee($otra->codigo)->assertDontSee($cita->codigo);
        $this->get(route('citas.index', ['q' => 'no-existe-xyz']))->assertOk()->assertSee('No se encontraron citas');

        // Una cita atendida aparece en la búsqueda pero sin botón «Reprogramar».
        $this->get(route('citas.index', ['q' => $otra->codigo]))->assertOk()->assertSee($otra->codigo)->assertDontSee('Reprogramar</a>', false);
    }

    #[Test]
    public function sin_texto_el_listado_muestra_las_citas_vigentes_desde_hoy(): void
    {
        $vigente = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');
        $cancelada = $this->crearCita($this->medico, $this->crearPaciente(), $this->especialidad, $this->lunes, '10:00', '10:30', Cita::CANCELADA);
        $pasada = $this->crearCita($this->medico, $this->crearPaciente(), $this->especialidad, '2020-02-03', '10:00', '10:30');

        $this->actingAs($this->recepcion)->get(route('citas.index', ['page' => 1]))->assertOk()->assertSee('Citas vigentes');
        $codigos = app(ReprogramarCitaService::class)->buscar(null)->getCollection()->pluck('codigo');
        $this->assertTrue($codigos->contains($vigente->codigo) || $codigos->count() === 10, 'La cita vigente debe estar entre las próximas.');
        $this->assertFalse($codigos->contains($cancelada->codigo));
        $this->assertFalse($codigos->contains($pasada->codigo));
    }

    #[Test]
    public function solo_la_recepcionista_reprograma_citas(): void
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');
        $this->get(route('citas.index'))->assertRedirect('/login');

        // El Administrador hereda las funciones de recepción y también puede abrir estas pantallas.
        $admin = $this->crearUsuario('t-admin-rep', [Rol::ADMINISTRADOR]);
        $this->actingAs($admin)->get(route('citas.index'))->assertOk();
        $this->actingAs($admin)->get(route('citas.reprogramar', $cita))->assertOk();

        foreach ([Rol::MEDICO, Rol::PACIENTE] as $rol) {
            $usuario = $this->crearUsuario('t-sin-permiso-rep-'.strtolower(substr($rol, 0, 3)), [$rol]);
            $this->actingAs($usuario)->get(route('citas.index'))->assertForbidden();
            $this->actingAs($usuario)->get(route('citas.reprogramar', $cita))->assertForbidden();
            $this->actingAs($usuario)->put(route('citas.actualizar', $cita), $this->datos())->assertForbidden();
        }
        $this->assertSame($this->lunes, $cita->fresh()->fecha->toDateString());
    }

    // ------------------------------------------------------------------ Reglas del v30: inasistencia pagada y pago conservado

    #[Test]
    public function se_pueden_reprogramar_programada_confirmada_e_inasistencia_pagada_y_nada_mas(): void
    {
        $casos = [
            // [estado, pago, ¿se puede?]
            [Cita::PROGRAMADA, 'PENDIENTE', true], [Cita::PROGRAMADA, 'PAGADO', true],
            [Cita::CONFIRMADA, 'PENDIENTE', true], [Cita::CONFIRMADA, 'PAGADO', true],
            [Cita::INASISTENCIA, 'PAGADO', true], [Cita::INASISTENCIA, 'PENDIENTE', false],
            [Cita::ATENDIDA, 'PAGADO', false], [Cita::ATENDIDA, 'PENDIENTE', false],
            [Cita::CANCELADA, 'PAGADO', false], [Cita::CANCELADA, 'PENDIENTE', false],
        ];

        foreach ($casos as [$estado, $pago, $esperado]) {
            $cita = (new Cita)->forceFill(['estado' => $estado, 'estado_pago' => $pago]);
            $this->assertSame($esperado, AgendarCitaService::sePuedeReprogramar($cita), "{$estado} / {$pago}");
            $this->assertSame($esperado, app(ReprogramarCitaService::class)->puedeReprogramarse($cita), "{$estado} / {$pago} (servicio)");
        }
    }

    #[Test]
    public function una_inasistencia_pagada_en_efectivo_en_la_clinica_tambien_se_reprograma(): void
    {
        $cita = $this->citaConInasistenciaPagada(['metodo_pago' => 'EFECTIVO', 'referencia_pago' => null]);

        $this->actingAs($this->recepcion)->put(route('citas.actualizar', $cita), $this->datos())->assertRedirect(route('citas.reprogramada', $cita));

        $cita->refresh();
        $this->assertSame(Cita::PROGRAMADA, $cita->estado);
        $this->assertSame('EFECTIVO', $cita->metodo_pago);
        $this->assertSame(Cita::PAGO_PAGADO, $cita->estado_pago);
        $this->assertNull($cita->referencia_pago);
    }

    #[Test]
    public function la_pantalla_de_una_inasistencia_pagada_avisa_que_se_reprograma_sin_nuevo_cobro_y_ofrece_los_horarios(): void
    {
        $cita = $this->citaConInasistenciaPagada();

        $this->actingAs($this->recepcion)->get(route('citas.reprogramar', ['cita' => $cita->id, 'fecha' => $this->otroLunes]))->assertOk()
            ->assertSee('Inasistencia')->assertSee('sin un nuevo cobro')->assertSee('Confirmar reprogramación')->assertSee('10:00 – 10:30')
            ->assertDontSee('Registrar una cita nueva');
    }

    #[Test]
    public function una_inasistencia_pagada_a_un_horario_ocupado_se_rechaza_y_conserva_el_estado_y_el_pago(): void
    {
        $cita = $this->citaConInasistenciaPagada();
        $this->crearCita($this->medico, $this->crearPaciente(), $this->especialidad, $this->otroLunes, '10:00', '10:30');

        $this->actingAs($this->recepcion)->put(route('citas.actualizar', $cita), $this->datos())
            ->assertRedirect(route('citas.reprogramar', ['cita' => $cita->id, 'fecha' => $this->otroLunes]))
            ->assertSessionHas('error', HorarioOcupadoException::MENSAJE);

        $cita->refresh();
        $this->assertSame(Cita::INASISTENCIA, $cita->estado);
        $this->assertSame(Cita::PAGO_PAGADO, $cita->estado_pago);
        $this->assertSame($this->lunes, $cita->fecha->toDateString());
    }

    #[Test]
    public function al_reprogramar_la_cita_vuelve_a_recibir_su_recordatorio_y_conserva_el_pago(): void
    {
        foreach ([Cita::PROGRAMADA, Cita::CONFIRMADA] as $i => $estado) {
            $cita = $this->crearCita($this->medico, $this->crearPaciente(), $this->especialidad, $this->lunes, '0'.(8 + $i).':00', '0'.(8 + $i).':30', $estado);
            $cita->update(['recordatorio_enviado' => true, 'monto' => 100, 'metodo_pago' => 'TARJETA', 'estado_pago' => 'PAGADO', 'referencia_pago' => 'pi_r_'.$i, 'fecha_pago' => now()]);

            $this->actingAs($this->recepcion)->put(route('citas.actualizar', $cita), $this->datos(['hora_inicio' => '1'.$i.':00']))->assertSessionHasNoErrors();

            $cita->refresh();
            $this->assertFalse($cita->recordatorio_enviado, "Una cita {$estado} reprogramada recibe el recordatorio de la nueva fecha.");
            $this->assertSame('pi_r_'.$i, $cita->referencia_pago);
            $this->assertSame(Cita::PAGO_PAGADO, $cita->estado_pago);
        }
    }

    #[Test]
    public function el_motivo_del_cambio_admite_150_caracteres_y_queda_completo_en_la_bitacora(): void
    {
        $cita = $this->citaConInasistenciaPagada();
        $motivo = str_repeat('m', 150);

        $this->actingAs($this->recepcion)->put(route('citas.actualizar', $cita), $this->datos(['motivo_cambio' => str_repeat('x', 151)]))
            ->assertSessionHasErrors(['motivo_cambio' => 'El motivo del cambio no puede superar los 150 caracteres.']);
        $this->assertSame(Cita::INASISTENCIA, $cita->fresh()->estado);

        $this->put(route('citas.actualizar', $cita), $this->datos(['motivo_cambio' => $motivo]))->assertSessionHasNoErrors();

        $detalle = DB::table('bitacora')->where('accion', 'CITA_REPROGRAMADA')->where('usuario_id', $this->recepcion->id)->orderByDesc('id')->value('detalle');
        $this->assertStringEndsWith('Motivo: '.$motivo, $detalle, 'El motivo no se corta aunque el detalle lleve el aviso de inasistencia pagada.');
        $this->assertLessThanOrEqual(300, mb_strlen($detalle));
    }

    #[Test]
    public function el_listado_sin_texto_incluye_las_inasistencias_pagadas_con_su_boton_y_no_las_sin_pago(): void
    {
        $pagada = $this->citaConInasistenciaPagada();
        $sinPago = $this->crearCita($this->medico, $this->crearPaciente(), $this->especialidad, '2020-02-03', '10:00', '10:30', Cita::INASISTENCIA);

        $this->actingAs($this->recepcion)->get(route('citas.index', ['q' => $pagada->codigo]))->assertOk()
            ->assertSee('Inasistencia')->assertSee('Pagado')->assertSee('Reprogramar</a>', false);
        $this->get(route('citas.index', ['q' => $sinPago->codigo]))->assertOk()
            ->assertSee('Inasistencia')->assertSee('Pendiente')->assertDontSee('Reprogramar</a>', false);

        $codigos = app(ReprogramarCitaService::class)->buscar(null)->getCollection()->pluck('codigo');
        $this->assertTrue($codigos->contains($pagada->codigo) || $codigos->count() === ReprogramarCitaService::POR_PAGINA, 'La inasistencia pagada espera su nueva fecha en el listado.');
        $this->assertFalse($codigos->contains($sinPago->codigo));
        $this->get(route('citas.index'))->assertOk()->assertSee('inasistencias pagadas por reprogramar');
    }

    /** Cita con inasistencia ya pagada con tarjeta (Bs 100) a las 09:00 del lunes, con su recordatorio ya enviado. */
    private function citaConInasistenciaPagada(array $cambios = []): Cita
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30', Cita::INASISTENCIA);
        $cita->update($cambios + [
            'monto' => 100, 'metodo_pago' => 'TARJETA', 'estado_pago' => 'PAGADO', 'referencia_pago' => 'pi_inasistencia_1',
            'fecha_pago' => now()->subDay(), 'recordatorio_enviado' => true,
        ]);

        return $cita->fresh();
    }

    /** La cita en ese estado no se reprograma: ni la pantalla ni el envío lo permiten y se sugiere registrar una cita nueva. */
    private function assertNoSePuedeReprogramar(string $estado, string $mensaje): void
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30', $estado);

        $this->actingAs($this->recepcion)->get(route('citas.reprogramar', ['cita' => $cita->id, 'fecha' => $this->otroLunes]))
            ->assertOk()->assertSee($mensaje)->assertSee('Registrar una cita nueva')->assertDontSee('Confirmar reprogramación');

        $this->put(route('citas.actualizar', $cita), $this->datos())->assertSessionHas('error', $mensaje);

        $cita->refresh();
        $this->assertSame($estado, $cita->estado);
        $this->assertSame($this->lunes, $cita->fecha->toDateString());
        $this->assertSame('09:00', $cita->hora_inicio_corta);
    }

    #[Test]
    public function el_listado_de_citas_muestra_el_monto_y_el_estado_de_pago(): void
    {
        $pagada = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');
        $pagada->update(['monto' => 150, 'metodo_pago' => 'TARJETA', 'estado_pago' => 'PAGADO', 'referencia_pago' => 'pi_t_1', 'fecha_pago' => now()]);
        $pendiente = $this->crearCita($this->medico, $this->crearPaciente(['nombres' => 'Otra', 'apellidos' => 'Tpendiente']), $this->especialidad, $this->lunes, '10:00', '10:30');
        $pendiente->update(['monto' => 80]);

        $this->actingAs($this->recepcion)->get(route('citas.index', ['q' => $pagada->codigo]))->assertOk()
            ->assertSee('Monto')->assertSee('Bs 150,00')->assertSee('Pagado')->assertSee('Tarjeta');
        $this->get(route('citas.index', ['q' => $pendiente->codigo]))->assertOk()
            ->assertSee('Bs 80,00')->assertSee('Pendiente')->assertSee('Efectivo');
    }

    /** Datos válidos para pasar la cita al otro lunes a las 10:00. */
    private function datos(array $cambios = []): array
    {
        return $cambios + ['fecha' => $this->otroLunes, 'hora_inicio' => '10:00', 'motivo_cambio' => 'El paciente pidió otro día'];
    }
}
