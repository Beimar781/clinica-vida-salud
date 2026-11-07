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
use App\Modules\GestionCitas\Compartido\PagoCitaService;
use App\Modules\GestionCitas\Compartido\PagoException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/** CU-09: Registrar cita médica. Los métodos paso_N son las filas del plan de pruebas 8.1; el pago con tarjeta usa Http::fake() (nunca llama a Stripe). */
class CU09_RegistrarCitaMedicaTest extends SistemaTestCase
{
    private Usuario $recepcion;

    private Especialidad $especialidad;

    private Medico $medico;

    private Paciente $paciente;

    private string $lunes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recepcion = $this->crearUsuario('t-recep-cita', [Rol::RECEPCIONISTA]);
        $this->especialidad = $this->crearEspecialidad('T-Citas', precio: 150);
        $this->medico = $this->crearMedico([], [$this->especialidad]);
        $this->crearHorario($this->medico, 1, '08:00', '12:00', 30);
        $this->paciente = $this->crearPaciente(['nombres' => 'Cita', 'apellidos' => 'Tprueba']);
        $this->lunes = $this->proximaFecha(1);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Paso 1: programar una cita en un horario disponible (paciente, especialidad, médico, fecha, horario libre y modalidad) → la cita queda registrada con código, fecha de creación y estado Programada. */
    #[Test]
    public function paso_1_programar_una_cita_en_un_horario_disponible(): void
    {
        $respuesta = $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos());

        $cita = Cita::where('paciente_id', $this->paciente->id)->firstOrFail();
        $respuesta->assertRedirect(route('citas.comprobante', $cita));

        $this->assertMatchesRegularExpression('/^CM-\d{4}$/', $cita->codigo);
        $this->assertSame(Cita::PROGRAMADA, $cita->estado);
        $this->assertSame(Cita::PRESENCIAL, $cita->modalidad);
        $this->assertSame($this->lunes, $cita->fecha->toDateString());
        $this->assertSame('09:00', $cita->hora_inicio_corta);
        $this->assertSame('09:30', $cita->hora_fin_corta);
        $this->assertSame($this->recepcion->id, $cita->creado_por);
        $this->assertNotNull($cita->created_at);
        $this->assertDatabaseHas('bitacora', ['accion' => 'CITA_REGISTRADA', 'usuario_id' => $this->recepcion->id]);

        // El comprobante muestra paciente, especialidad, médico, fecha, horario y motivo; el horario deja de estar disponible.
        $this->get(route('citas.comprobante', $cita))->assertOk()
            ->assertSee($cita->codigo)->assertSee('Cita Tprueba')->assertSee('T-Citas')->assertSee($this->medico->nombre_completo)
            ->assertSee('09:00 – 09:30')->assertSee('Control de rutina')->assertSee('Bs 150,00');
        $this->assertNull(app(DisponibilidadService::class)->calcularLibres($this->medico, $this->lunes)->libre('09:00'));
    }

    /** Paso 2: programar una cita superpuesta para el mismo médico (mismo médico, fecha y horario ya ocupado) → el sistema impide el conflicto y muestra un mensaje explicativo. */
    #[Test]
    public function paso_2_programar_una_cita_superpuesta_para_el_mismo_medico(): void
    {
        $this->crearCita($this->medico, $this->crearPaciente(), $this->especialidad, $this->lunes, '09:00', '09:30');

        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos())
            ->assertRedirect($this->urlRegistrar())
            ->assertSessionHas('error', HorarioOcupadoException::MENSAJE);

        $this->assertSame(1, Cita::where('medico_id', $this->medico->id)->count());
        // Vuelve a mostrar los horarios libres: ya no aparece el de las 09:00.
        $this->get($this->urlRegistrar())->assertOk()
            ->assertSee('El horario fue ocupado por otro usuario')->assertSee('09:30 – 10:00')->assertDontSee('09:00 – 09:30');
    }

    /** Paso 3: intentar reservar fuera del horario del médico (hora fuera de sus bloques de atención) → el sistema rechaza la cita. */
    #[Test]
    public function paso_3_intentar_reservar_fuera_del_horario_del_medico(): void
    {
        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos(['hora_inicio' => '13:00']))
            ->assertSessionHas('error', 'La hora elegida está fuera del horario de atención del médico.');

        // Una hora que no es el inicio de un bloque también se rechaza.
        $this->post(route('citas.guardar'), $this->datos(['hora_inicio' => '09:10']))
            ->assertSessionHas('error', 'La hora elegida está fuera del horario de atención del médico.');

        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());
    }

    /** Paso 4: intentar reservar en fecha pasada (fecha anterior a hoy) → el sistema rechaza la cita. */
    #[Test]
    public function paso_4_intentar_reservar_en_fecha_pasada(): void
    {
        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos(['fecha' => Carbon::yesterday()->toDateString()]))
            ->assertSessionHas('error', DisponibilidadService::MENSAJE_FECHA_PASADA);

        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());
    }

    /**
     * Paso 5: dos usuarios reservan el mismo horario a la vez (dos confirmaciones simultáneas) → solo una se registra y la otra es rechazada.
     * Aquí: las dos recepcionistas ven libre el mismo horario y la segunda confirma tarde. La simultaneidad real (dos procesos
     * y la restricción de la base de datos) está en CU09_RegistrarCitaMedicaConcurrenciaTest.
     */
    #[Test]
    public function paso_5_dos_usuarios_reservan_el_mismo_horario_a_la_vez(): void
    {
        $otraRecepcion = $this->crearUsuario('t-recep-cita-2', [Rol::RECEPCIONISTA]);
        $otroPaciente = $this->crearPaciente(['nombres' => 'Otro', 'apellidos' => 'Tpaciente']);

        // Las dos pantallas muestran el horario de las 09:00 como libre.
        $this->actingAs($this->recepcion)->get($this->urlRegistrar())->assertOk()->assertSee('09:00 – 09:30');
        $this->actingAs($otraRecepcion)->get($this->urlRegistrar($otroPaciente))->assertOk()->assertSee('09:00 – 09:30');

        // La primera confirma y gana; la segunda recibe el aviso y ve los horarios libres actualizados.
        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos())->assertRedirect();
        $this->actingAs($otraRecepcion)->post(route('citas.guardar'), $this->datos(['paciente_id' => $otroPaciente->id]))
            ->assertRedirect($this->urlRegistrar($otroPaciente))
            ->assertSessionHas('error', HorarioOcupadoException::MENSAJE);

        $this->assertSame(1, Cita::where('medico_id', $this->medico->id)->where('estado', '<>', Cita::CANCELADA)->count());
        $this->get($this->urlRegistrar($otroPaciente))->assertOk()->assertDontSee('09:00 – 09:30')->assertSee('09:30 – 10:00');
    }

    /** Paso 6: programar una cita virtual (cita con modalidad Virtual) → la cita queda registrada con su modalidad Virtual y su fecha de creación. */
    #[Test]
    public function paso_6_programar_una_cita_virtual(): void
    {
        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos(['modalidad' => 'VIRTUAL']))->assertRedirect();

        $cita = Cita::where('paciente_id', $this->paciente->id)->firstOrFail();
        $this->assertSame(Cita::VIRTUAL, $cita->modalidad);
        $this->assertNotNull($cita->created_at);
        $this->get(route('citas.comprobante', $cita))->assertOk()->assertSee('Virtual');
    }

    /** Paso 7: provocar un error interno al guardar (falla simulada de la base de datos) → el usuario ve un mensaje claro sin detalles técnicos y el error queda registrado internamente. */
    #[Test]
    public function paso_7_provocar_un_error_interno_al_guardar(): void
    {
        Exceptions::fake();
        $this->mock(AgendarCitaService::class, function ($mock) {
            $mock->shouldReceive('crear')->andThrow(new QueryException('pgsql', 'insert into "citas" (codigo) values (?)', ['CM-9999'], new \Exception('SQLSTATE[08006]: conexión perdida con el servidor 10.0.0.5')));
        });

        $respuesta = $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos());

        $respuesta->assertRedirect($this->urlRegistrar())->assertSessionHas('error', 'No se pudo registrar la cita por un problema interno. Intente nuevamente; si el problema continúa, avise al administrador.');
        $mensaje = session('error');
        foreach (['SQLSTATE', 'insert', 'citas', '10.0.0.5', 'pgsql'] as $tecnico) {
            $this->assertStringNotContainsString($tecnico, $mensaje, "El mensaje no debe mostrar detalles técnicos ({$tecnico}).");
        }
        Exceptions::assertReported(QueryException::class);
        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());
    }

    /** Paso 8: registrar una cita con pago en efectivo (cita nueva y método de pago Efectivo) → la cita queda Programada con el pago Pendiente hasta el cobro en la clínica. */
    #[Test]
    public function paso_8_registrar_una_cita_con_pago_en_efectivo(): void
    {
        Http::fake(); // el efectivo no habla con Stripe

        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos(['metodo_pago' => 'EFECTIVO']))->assertRedirect();

        $cita = Cita::where('paciente_id', $this->paciente->id)->firstOrFail();
        $this->assertSame(Cita::PROGRAMADA, $cita->estado);
        $this->assertSame(Cita::METODO_EFECTIVO, $cita->metodo_pago);
        $this->assertSame(Cita::PAGO_PENDIENTE, $cita->estado_pago, 'El pago queda Pendiente hasta que la recepción registre el cobro (CU-13).');
        $this->assertEquals(150, $cita->monto, 'El monto es el precio de la especialidad.');
        $this->assertNull($cita->referencia_pago);
        $this->assertNull($cita->fecha_pago);
        Http::assertNothingSent();

        $this->get(route('citas.comprobante', $cita))->assertOk()
            ->assertSee('Bs 150,00')->assertSee('Efectivo en la clínica')->assertSee('Pendiente')->assertSee('se cobra en efectivo en la clínica');
        $this->assertDatabaseHas('bitacora', ['accion' => 'CITA_REGISTRADA', 'usuario_id' => $this->recepcion->id, 'detalle' => $this->detalleEsperado($cita, 'Efectivo en la clínica, Pendiente, Bs 150,00')]);
    }

    #[Test]
    public function la_base_de_datos_rechaza_la_superposicion_aunque_el_calculo_la_deje_pasar(): void
    {
        // Se simula que el cálculo de disponibilidad no ve la cita (como si la otra transacción aún no hubiera confirmado):
        // la restricción EXCLUDE de PostgreSQL es la última barrera (SQLSTATE 23P01) y el servicio la traduce al aviso.
        $this->crearCita($this->medico, $this->crearPaciente(), $this->especialidad, $this->lunes, '09:00', '09:30');
        $this->app->instance(DisponibilidadService::class, new class extends DisponibilidadService
        {
            public function calcularLibres(Medico $medico, Carbon|string $fecha, ?int $ignorarCitaId = null, ?Carbon $ahora = null): Disponibilidad
            {
                $bloque = ['inicio' => '09:00', 'fin' => '09:30'];

                return new Disponibilidad([$bloque], [$bloque], null, null);
            }
        });

        try {
            app(AgendarCitaService::class)->crear($this->paciente, $this->medico, $this->especialidad, $this->lunes, '09:00', Cita::PRESENCIAL, null, $this->recepcion->id);
            $this->fail('La restricción EXCLUDE debió rechazar la cita superpuesta.');
        } catch (HorarioOcupadoException $e) {
            $this->assertSame(HorarioOcupadoException::MENSAJE, $e->getMessage());
        }
        $this->assertSame(1, Cita::where('medico_id', $this->medico->id)->count());
    }

    #[Test]
    public function no_se_registra_la_cita_de_un_paciente_inactivo(): void
    {
        $this->paciente->update(['estado' => false]);

        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos())
            ->assertSessionHas('error', 'El paciente está inactivo: no se le pueden registrar citas.');
        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());

        $this->get(route('citas.registrar', ['paciente' => $this->paciente->id]))->assertOk()->assertSee('El paciente está inactivo');
    }

    #[Test]
    public function el_paciente_no_puede_tener_dos_citas_activas_a_la_misma_hora(): void
    {
        $otroMedico = $this->crearMedico([], [$this->especialidad]);
        $this->crearHorario($otroMedico, 1, '08:00', '12:00', 30);
        $this->crearCita($otroMedico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');

        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos())
            ->assertSessionHas('error', 'El paciente ya tiene una cita activa a esa hora.');

        $this->assertSame(0, Cita::where('medico_id', $this->medico->id)->count());

        // Si la otra cita está cancelada, sí se puede.
        Cita::where('medico_id', $otroMedico->id)->update(['estado' => Cita::CANCELADA]);
        $this->post(route('citas.guardar'), $this->datos())->assertSessionMissing('error');
        $this->assertSame(1, Cita::where('medico_id', $this->medico->id)->count());
    }

    #[Test]
    public function no_se_registra_con_un_medico_inactivo_ni_en_un_dia_sin_atencion_ni_con_otra_especialidad(): void
    {
        $this->actingAs($this->recepcion);

        $otraEspecialidad = $this->crearEspecialidad('T-Otra');
        $this->post(route('citas.guardar'), $this->datos(['especialidad_id' => $otraEspecialidad->id]))
            ->assertSessionHas('error', 'El médico no atiende la especialidad elegida.');

        $this->crearBloqueo(['fecha' => $this->lunes, 'motivo' => 'T-Feriado', 'estado' => true]);
        $this->post(route('citas.guardar'), $this->datos())->assertSessionHas('error', fn ($m) => str_contains($m, 'feriado o día sin atención'));
        BloqueoAgenda::whereDate('fecha', $this->lunes)->delete();

        $this->medico->update(['estado' => false]);
        $this->post(route('citas.guardar'), $this->datos())->assertSessionHas('error', DisponibilidadService::MENSAJE_MEDICO_INACTIVO);
        $this->assertSame(0, Cita::where('medico_id', $this->medico->id)->count());
    }

    #[Test]
    public function hoy_no_se_puede_reservar_un_horario_que_ya_paso(): void
    {
        Carbon::setTestNow(Carbon::parse($this->lunes.' 10:15:00'));

        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos(['hora_inicio' => '09:00']))
            ->assertSessionHas('error', 'Ese horario ya pasó. Elija uno de los horarios libres.');
        $this->post(route('citas.guardar'), $this->datos(['hora_inicio' => '10:30']))->assertSessionMissing('error');
        $this->assertSame(1, Cita::where('paciente_id', $this->paciente->id)->count());
    }

    #[Test]
    public function los_datos_del_formulario_se_validan(): void
    {
        $this->actingAs($this->recepcion)->post(route('citas.guardar'), ['modalidad' => 'TELEFONICA', 'fecha' => 'ayer', 'hora_inicio' => '9', 'motivo' => str_repeat('a', 201)])
            ->assertSessionHasErrors([
                'paciente_id' => 'Seleccione al paciente.', 'especialidad_id' => 'Seleccione la especialidad.', 'medico_id' => 'Seleccione el médico.',
                'fecha' => 'La fecha no es válida.', 'hora_inicio' => 'El horario seleccionado no es válido.',
                'modalidad' => 'La modalidad debe ser presencial o virtual.', 'motivo' => 'El motivo no puede superar los 200 caracteres.',
                'metodo_pago' => 'Elija el método de pago: efectivo en la clínica o tarjeta.',
            ]);
    }

    #[Test]
    public function cada_cita_recibe_un_codigo_distinto_de_la_secuencia(): void
    {
        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos());
        $this->post(route('citas.guardar'), $this->datos(['hora_inicio' => '09:30']));

        $codigos = Cita::where('paciente_id', $this->paciente->id)->orderBy('id')->pluck('codigo')->all();
        $this->assertCount(2, $codigos);
        $this->assertCount(2, array_unique($codigos));
        $this->assertGreaterThan((int) substr($codigos[0], 3), (int) substr($codigos[1], 3));
    }

    #[Test]
    public function la_pantalla_busca_al_paciente_y_ofrece_los_medicos_de_la_especialidad_y_sus_horarios(): void
    {
        $this->actingAs($this->recepcion);

        $this->get(route('citas.registrar', ['q' => $this->paciente->ci]))->assertOk()->assertSee('Cita Tprueba')->assertSee('Seleccionar');
        $this->get(route('citas.registrar', ['q' => 'tprueba']))->assertOk()->assertSee('Cita Tprueba');
        $this->get(route('citas.registrar', ['q' => 'no-existe-xyz']))->assertOk()->assertSee('No se encontraron pacientes');

        $this->get(route('citas.registrar', ['paciente' => $this->paciente->id, 'especialidad' => $this->especialidad->id]))
            ->assertOk()->assertSee($this->medico->nombre_completo);

        $this->get($this->urlRegistrar())->assertOk()->assertSee('Horarios libres (8)')->assertSee('Confirmar cita');

        // Un día en que el médico no atiende: se indica y no se muestran horarios.
        $this->get(route('citas.registrar', ['paciente' => $this->paciente->id, 'especialidad' => $this->especialidad->id, 'medico' => $this->medico->id, 'fecha' => $this->proximaFecha(2)]))
            ->assertOk()->assertSee('El médico no atiende en esa fecha')->assertDontSee('Confirmar cita');
    }

    #[Test]
    public function un_paciente_inactivo_aparece_en_la_busqueda_pero_no_se_puede_seleccionar(): void
    {
        $inactivo = $this->crearPaciente(['nombres' => 'Baja', 'apellidos' => 'Tinactivo', 'estado' => false]);

        $this->actingAs($this->recepcion)->get(route('citas.registrar', ['q' => $inactivo->ci]))
            ->assertOk()->assertSee('Baja Tinactivo')->assertSee('No se puede registrar cita');
    }

    #[Test]
    public function solo_la_recepcionista_registra_citas(): void
    {
        $this->get(route('citas.registrar'))->assertRedirect('/login');

        // El Administrador hereda las funciones de recepción y también puede abrir la pantalla.
        $admin = $this->crearUsuario('t-admin-cita', [Rol::ADMINISTRADOR]);
        $this->actingAs($admin)->get(route('citas.registrar'))->assertOk();

        foreach ([Rol::MEDICO, Rol::PACIENTE] as $rol) {
            $usuario = $this->crearUsuario('t-sin-permiso-cita-'.strtolower(substr($rol, 0, 3)), [$rol]);
            $this->actingAs($usuario)->get(route('citas.registrar'))->assertForbidden();
            $this->actingAs($usuario)->post(route('citas.guardar'), $this->datos())->assertForbidden();
        }
        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());
    }

    // ------------------------------------------------------------------ Pago: efectivo y tarjeta (Stripe, siempre simulado)

    #[Test]
    public function el_metodo_de_pago_es_obligatorio_y_solo_admite_efectivo_o_tarjeta(): void
    {
        $sinMetodo = $this->datos();
        unset($sinMetodo['metodo_pago']);

        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $sinMetodo)
            ->assertSessionHasErrors(['metodo_pago' => 'Elija el método de pago: efectivo en la clínica o tarjeta.']);
        $this->post(route('citas.guardar'), $this->datos(['metodo_pago' => 'CHEQUE']))
            ->assertSessionHasErrors(['metodo_pago' => 'El método de pago debe ser efectivo en la clínica o tarjeta.']);
        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());
    }

    #[Test]
    public function el_formulario_muestra_el_monto_y_los_dos_metodos_de_pago(): void
    {
        $this->activarStripe();

        $this->actingAs($this->recepcion)->get($this->urlRegistrar())->assertOk()
            ->assertSee('Método de pago')->assertSee('Monto a pagar')->assertSee('Bs 150,00')
            ->assertSee('Efectivo en la clínica')->assertSee('Tarjeta (Stripe)')
            ->assertSee('value="EFECTIVO"', false)->assertSee('value="TARJETA"', false);
    }

    #[Test]
    public function sin_la_clave_de_stripe_la_tarjeta_esta_deshabilitada_con_aviso_y_el_efectivo_sigue_funcionando(): void
    {
        Http::fake();

        $pantalla = $this->actingAs($this->recepcion)->get($this->urlRegistrar())->assertOk()
            ->assertSee('El pago con tarjeta no está disponible por ahora');
        $this->assertMatchesRegularExpression('/value="TARJETA"\s+disabled/', $pantalla->getContent());

        $this->post(route('citas.guardar'), $this->datos(['metodo_pago' => 'TARJETA']))
            ->assertRedirect($this->urlRegistrar())->assertSessionHas('error', PagoException::MENSAJE_NO_DISPONIBLE);
        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());
        Http::assertNothingSent();

        $this->post(route('citas.guardar'), $this->datos())->assertRedirect()->assertSessionMissing('error');
        $this->assertSame(1, Cita::where('paciente_id', $this->paciente->id)->count());
    }

    #[Test]
    public function con_tarjeta_se_valida_la_reserva_y_se_manda_a_la_pagina_de_pago_de_stripe_sin_crear_la_cita(): void
    {
        $this->activarStripe();
        $this->fingirStripe();

        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos(['metodo_pago' => 'TARJETA']))
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_123');

        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count(), 'La cita se registra recién al volver del pago.');
        $this->assertSame('150.00', session('pago_cita.monto'));
        $this->assertSame('CU-09', session('pago_cita.cu'));

        Http::assertSent(function (Request $r) {
            return $r->url() === 'https://api.stripe.com/v1/checkout/sessions'
                && $r->hasHeader('Authorization', 'Bearer sk_test_clave_de_prueba')
                && $r['mode'] === 'payment'
                && $r['payment_intent_data']['capture_method'] === 'manual'
                && $r['line_items'][0]['price_data']['currency'] === 'bob'
                && (int) $r['line_items'][0]['price_data']['unit_amount'] === 15000
                && str_contains($r['success_url'], '/pagos/stripe/retorno?session_id={CHECKOUT_SESSION_ID}')
                && str_ends_with($r['cancel_url'], '/pagos/stripe/cancelado')
                && $r['metadata']['fecha'] === $this->lunes && $r['metadata']['hora_inicio'] === '09:00'
                && (int) $r['metadata']['medico_id'] === $this->medico->id && (int) $r['metadata']['paciente_id'] === $this->paciente->id;
        });
    }

    #[Test]
    public function tarjeta_aprobada_el_retorno_consulta_a_stripe_captura_el_pago_y_registra_la_cita_pagada_con_la_referencia(): void
    {
        $this->activarStripe();
        $this->fingirStripe();
        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos(['metodo_pago' => 'TARJETA']));

        $respuesta = $this->get(route('pagos.stripe.retorno', ['session_id' => 'cs_test_123']));

        $cita = Cita::where('paciente_id', $this->paciente->id)->firstOrFail();
        $respuesta->assertRedirect(route('citas.comprobante', $cita))->assertSessionHas('exito', "Pago con tarjeta aprobado. Cita {$cita->codigo} registrada correctamente.");

        $this->assertSame(Cita::PROGRAMADA, $cita->estado);
        $this->assertSame(Cita::METODO_TARJETA, $cita->metodo_pago);
        $this->assertSame(Cita::PAGO_PAGADO, $cita->estado_pago);
        $this->assertSame('pi_test_123', $cita->referencia_pago);
        $this->assertNotNull($cita->fecha_pago);
        $this->assertEquals(150, $cita->monto);
        $this->assertSame($this->recepcion->id, $cita->creado_por);
        $this->assertNull(session('pago_cita'), 'La reserva se procesa una sola vez.');

        $this->assertSame(1, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/payment_intents/pi_test_123/capture'))->count(), 'El pago se captura una sola vez.');
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/cancel'));

        $this->assertDatabaseHas('bitacora', ['accion' => 'CITA_REGISTRADA', 'usuario_id' => $this->recepcion->id, 'detalle' => $this->detalleEsperado($cita, 'Tarjeta (Stripe), Pagado, Bs 150,00').' [pi_test_123]']);
        $this->get(route('citas.comprobante', $cita))->assertOk()
            ->assertSee('Bs 150,00')->assertSee('Tarjeta (Stripe)')->assertSee('Pagado')->assertSee('pi_test_123');
    }

    #[Test]
    public function si_el_pago_se_cancela_en_stripe_no_se_registra_la_cita_y_se_puede_elegir_otro_metodo(): void
    {
        $this->activarStripe();
        $this->fingirStripe();
        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos(['metodo_pago' => 'TARJETA', 'motivo' => 'Dolor de espalda']));

        $this->get(route('pagos.stripe.cancelado'))
            ->assertRedirect($this->urlRegistrar())
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'No se cobró nada') && str_contains($m, 'pagar en efectivo'))
            ->assertSessionHasInput('hora_inicio', '09:00')->assertSessionHasInput('motivo', 'Dolor de espalda')->assertSessionHasInput('metodo_pago', 'EFECTIVO');

        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());
        $this->assertNull(session('pago_cita'));
        $this->assertDatabaseHas('bitacora', ['accion' => 'PAGO_TARJETA_FALLIDO', 'usuario_id' => $this->recepcion->id]);

        // Elige efectivo y la cita se registra.
        $this->post(route('citas.guardar'), $this->datos(['metodo_pago' => 'EFECTIVO']))->assertSessionMissing('error');
        $this->assertSame(Cita::PAGO_PENDIENTE, Cita::where('paciente_id', $this->paciente->id)->firstOrFail()->estado_pago);
    }

    #[Test]
    public function si_stripe_no_autoriza_el_pago_no_se_captura_nada_ni_se_registra_la_cita(): void
    {
        $this->activarStripe();
        $this->fingirStripe(['estado' => 'requires_payment_method']); // la tarjeta fue rechazada
        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos(['metodo_pago' => 'TARJETA']));

        $this->get(route('pagos.stripe.retorno', ['session_id' => 'cs_test_123']))
            ->assertRedirect($this->urlRegistrar())->assertSessionHas('error', PagoException::MENSAJE_NO_AUTORIZADO);

        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/capture'));
        $this->assertDatabaseHas('bitacora', ['accion' => 'PAGO_TARJETA_FALLIDO']);
    }

    #[Test]
    public function si_el_horario_se_ocupa_durante_el_pago_se_cancela_la_autorizacion_y_no_se_cobra_nada(): void
    {
        $this->activarStripe();
        $this->fingirStripe();
        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos(['metodo_pago' => 'TARJETA']));

        // Mientras la persona paga en Stripe, otra cita toma el horario.
        $this->crearCita($this->medico, $this->crearPaciente(), $this->especialidad, $this->lunes, '09:00', '09:30');

        $this->get(route('pagos.stripe.retorno', ['session_id' => 'cs_test_123']))
            ->assertRedirect($this->urlRegistrar())
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'ocupado por otro usuario') && str_contains($m, 'No se cobró nada'));

        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/capture'));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/payment_intents/pi_test_123/cancel');
        $this->assertDatabaseHas('bitacora', ['accion' => 'PAGO_TARJETA_FALLIDO', 'usuario_id' => $this->recepcion->id]);

        // Vuelve al paso 4 con los horarios libres actualizados.
        $this->get($this->urlRegistrar())->assertOk()->assertDontSee('09:00 – 09:30')->assertSee('09:30 – 10:00');
    }

    #[Test]
    public function si_el_horario_ya_esta_ocupado_antes_de_pagar_no_se_manda_a_stripe(): void
    {
        $this->activarStripe();
        $this->fingirStripe();
        $this->crearCita($this->medico, $this->crearPaciente(), $this->especialidad, $this->lunes, '09:00', '09:30');

        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos(['metodo_pago' => 'TARJETA']))
            ->assertRedirect($this->urlRegistrar())->assertSessionHas('error', HorarioOcupadoException::MENSAJE);

        Http::assertNothingSent();
        $this->assertNull(session('pago_cita'));
    }

    #[Test]
    public function con_tarjeta_tambien_se_respetan_las_reglas_del_paciente_y_la_fecha(): void
    {
        $this->activarStripe();
        $this->fingirStripe();
        $this->actingAs($this->recepcion);

        $this->post(route('citas.guardar'), $this->datos(['metodo_pago' => 'TARJETA', 'fecha' => Carbon::yesterday()->toDateString()]))
            ->assertSessionHas('error', DisponibilidadService::MENSAJE_FECHA_PASADA);

        $this->paciente->update(['estado' => false]);
        $this->post(route('citas.guardar'), $this->datos(['metodo_pago' => 'TARJETA']))
            ->assertSessionHas('error', 'El paciente está inactivo: no se le pueden registrar citas.');

        Http::assertNothingSent();
    }

    #[Test]
    public function si_stripe_no_responde_se_muestra_un_aviso_claro_sin_detalles_tecnicos_y_no_se_registra_la_cita(): void
    {
        $this->activarStripe();
        $this->fingirStripe(['crear' => 500]);

        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos(['metodo_pago' => 'TARJETA']))
            ->assertRedirect($this->urlRegistrar())->assertSessionHas('error', 'No se pudo procesar el pago con tarjeta. Elija otro método de pago.');

        foreach (['Stripe', 'sk_test', 'api_error', 'Error simulado', 'SQLSTATE'] as $tecnico) {
            $this->assertStringNotContainsString($tecnico, session('error'));
        }
        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());
        $this->assertDatabaseHas('bitacora', ['accion' => 'PAGO_TARJETA_FALLIDO']);

        // Sin conexión a Stripe (tiempo agotado): el mismo aviso, nunca un error 500.
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timeout'));
        $this->post(route('citas.guardar'), $this->datos(['metodo_pago' => 'TARJETA']))
            ->assertRedirect($this->urlRegistrar())->assertSessionHas('error', 'No se pudo procesar el pago con tarjeta. Elija otro método de pago.');
        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());
    }

    #[Test]
    public function si_la_captura_falla_la_cita_no_queda_registrada_y_se_cancela_la_autorizacion(): void
    {
        $this->activarStripe();
        $this->fingirStripe(['captura' => 402]);
        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos(['metodo_pago' => 'TARJETA']));

        $this->get(route('pagos.stripe.retorno', ['session_id' => 'cs_test_123']))
            ->assertRedirect($this->urlRegistrar())->assertSessionHas('error', PagoException::MENSAJE_GENERAL);

        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count(), 'La transacción deshace la cita si el cobro no se concreta.');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/payment_intents/pi_test_123/cancel'));
    }

    #[Test]
    public function si_el_precio_cambia_mientras_se_paga_no_se_cobra_y_se_cancela_la_autorizacion(): void
    {
        $this->activarStripe();
        $this->fingirStripe();
        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos(['metodo_pago' => 'TARJETA']));

        $this->especialidad->update(['precio' => 200]); // el administrador cambia el precio mientras tanto

        $this->get(route('pagos.stripe.retorno', ['session_id' => 'cs_test_123']))
            ->assertRedirect($this->urlRegistrar())
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'cambió mientras se realizaba el pago') && str_contains($m, 'No se cobró nada'));

        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/capture'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/payment_intents/pi_test_123/cancel'));
    }

    #[Test]
    public function el_monto_enviado_a_stripe_es_el_precio_convertido_y_la_cita_guarda_siempre_bolivianos(): void
    {
        $this->activarStripe(6.96, 'usd'); // si la cuenta de Stripe rechazara BOB: STRIPE_MONEDA=usd y TIPO_CAMBIO=6.96
        $this->fingirStripe();
        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos(['metodo_pago' => 'TARJETA']));

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/checkout/sessions'
            && $r['line_items'][0]['price_data']['currency'] === 'usd'
            && (int) $r['line_items'][0]['price_data']['unit_amount'] === 2155); // Bs 150 / 6,96 = USD 21,55

        $this->get(route('pagos.stripe.retorno', ['session_id' => 'cs_test_123']));

        $cita = Cita::where('paciente_id', $this->paciente->id)->firstOrFail();
        $this->assertEquals(150, $cita->monto);
        $this->assertSame(Cita::PAGO_PAGADO, $cita->estado_pago);
        $this->get(route('citas.comprobante', $cita))->assertSee('Bs 150,00');
    }

    #[Test]
    public function si_stripe_informa_otro_monto_u_otra_moneda_se_cancela_y_no_se_registra(): void
    {
        $this->activarStripe();
        $this->fingirStripe(['centavos' => 100]);
        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos(['metodo_pago' => 'TARJETA']));

        $this->get(route('pagos.stripe.retorno', ['session_id' => 'cs_test_123']))->assertSessionHas('error', PagoException::MENSAJE_GENERAL);

        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/payment_intents/pi_test_123/cancel'));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/capture'));
    }

    #[Test]
    public function el_retorno_no_confia_en_la_url_ni_en_una_sesion_de_pago_ajena(): void
    {
        $this->activarStripe();
        $this->fingirStripe();
        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos(['metodo_pago' => 'TARJETA']));

        // Otra sesión de Checkout (otro identificador) distinta de la que se guardó para esta reserva.
        $this->get(route('pagos.stripe.retorno', ['session_id' => 'cs_otra_999']))
            ->assertRedirect()->assertSessionHas('error', PagoException::MENSAJE_SESION_PERDIDA);
        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());

        // La misma sesión pero con los datos de otra reserva (token distinto): se rechaza y se cancela la autorización.
        $this->post(route('citas.guardar'), $this->datos(['metodo_pago' => 'TARJETA']));
        $this->fingirStripe(['token' => 'token-de-otra-reserva']);
        $this->get(route('pagos.stripe.retorno', ['session_id' => 'cs_test_123']))->assertSessionHas('error', PagoException::MENSAJE_SESION_PERDIDA);
        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/payment_intents/pi_test_123/cancel'));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/capture'));
    }

    #[Test]
    public function una_reserva_guardada_por_otra_persona_no_se_puede_completar(): void
    {
        $this->activarStripe();
        $this->fingirStripe();
        $otra = $this->crearUsuario('t-recep-otra', [Rol::RECEPCIONISTA]);

        $this->actingAs($otra)->withSession(['pago_cita' => [
            'cu' => 'CU-09', 'token' => 'x', 'session_id' => 'cs_test_123', 'actor_id' => $this->recepcion->id,
            'paciente_id' => $this->paciente->id, 'especialidad_id' => $this->especialidad->id, 'medico_id' => $this->medico->id,
            'fecha' => $this->lunes, 'hora_inicio' => '09:00', 'modalidad' => 'PRESENCIAL', 'motivo' => null, 'monto' => '150.00',
        ]])->get(route('pagos.stripe.retorno', ['session_id' => 'cs_test_123']))
            ->assertSessionHas('error', PagoException::MENSAJE_SESION_PERDIDA);

        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());
    }

    #[Test]
    public function si_se_perdio_la_sesion_del_servidor_avisa_y_cancela_la_autorizacion_sin_error(): void
    {
        $this->activarStripe();
        $this->fingirStripe();

        $this->actingAs($this->recepcion)->get(route('pagos.stripe.retorno', ['session_id' => 'cs_test_123']))
            ->assertRedirect(route('citas.registrar'))->assertSessionHas('error', PagoException::MENSAJE_SESION_PERDIDA);

        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/capture'));
    }

    #[Test]
    public function recargar_la_pagina_de_retorno_lleva_al_comprobante_de_la_misma_cita_sin_cobrar_otra_vez(): void
    {
        $this->activarStripe();
        $this->fingirStripe();
        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos(['metodo_pago' => 'TARJETA']));
        $this->get(route('pagos.stripe.retorno', ['session_id' => 'cs_test_123']));
        $cita = Cita::where('paciente_id', $this->paciente->id)->firstOrFail();

        $this->get(route('pagos.stripe.retorno', ['session_id' => 'cs_test_123']))->assertRedirect(route('citas.comprobante', $cita));

        $this->assertSame(1, Cita::where('paciente_id', $this->paciente->id)->count());
        $this->assertSame(1, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/capture'))->count());
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/cancel'));
    }

    #[Test]
    public function una_especialidad_sin_costo_no_se_cobra_con_tarjeta(): void
    {
        $this->activarStripe();
        $this->fingirStripe();
        $gratis = $this->crearEspecialidad('T-Sin costo', precio: 0);
        $this->medico->especialidades()->attach($gratis->id);

        $pantalla = $this->actingAs($this->recepcion)->get(route('citas.registrar', ['paciente' => $this->paciente->id, 'especialidad' => $gratis->id, 'medico' => $this->medico->id, 'fecha' => $this->lunes]))
            ->assertOk()->assertSee('no tiene costo');
        $this->assertMatchesRegularExpression('/value="TARJETA"\s+disabled/', $pantalla->getContent());

        $this->post(route('citas.guardar'), $this->datos(['especialidad_id' => $gratis->id, 'metodo_pago' => 'TARJETA']))
            ->assertSessionHas('error', PagoCitaService::MENSAJE_SIN_COSTO);
        Http::assertNothingSent();
    }

    #[Test]
    public function ningun_dato_de_tarjeta_se_pide_ni_se_guarda_en_el_sistema(): void
    {
        $this->activarStripe();
        $this->fingirStripe();

        // Las pantallas no tienen campos de tarjeta ni cargan scripts de Stripe: la tarjeta se escribe en la página de Stripe.
        $pantalla = $this->actingAs($this->recepcion)->get($this->urlRegistrar())->assertOk()->assertDontSee('js.stripe.com');
        foreach (['card', 'cardnumber', 'cc-number', 'cvc', 'cvv', 'exp-month', 'tarjeta_numero'] as $campo) {
            $pantalla->assertDontSee('name="'.$campo.'"', false);
        }

        // Lo que se envía a Stripe no lleva datos de tarjeta, y la cita solo guarda la referencia del pago.
        $this->post(route('citas.guardar'), $this->datos(['metodo_pago' => 'TARJETA', 'numero_tarjeta' => '4242424242424242', 'cvc' => '123']));
        $this->get(route('pagos.stripe.retorno', ['session_id' => 'cs_test_123']));

        Http::assertNotSent(fn (Request $r) => str_contains($r->body(), '4242') || str_contains($r->body(), 'cvc'));
        $cita = Cita::where('paciente_id', $this->paciente->id)->firstOrFail();
        $this->assertStringNotContainsString('4242', json_encode($cita->getAttributes()));
        $this->assertDatabaseMissing('bitacora', ['detalle' => '4242424242424242']);
        $this->assertSame('pi_test_123', $cita->referencia_pago);
    }

    #[Test]
    public function solo_el_personal_de_recepcion_o_el_paciente_usan_las_rutas_de_retorno_del_pago(): void
    {
        $this->get(route('pagos.stripe.retorno'))->assertRedirect('/login');
        $this->get(route('pagos.stripe.cancelado'))->assertRedirect('/login');

        $medico = $this->crearUsuario('t-medico-pago', [Rol::MEDICO]);
        $this->actingAs($medico)->get(route('pagos.stripe.retorno'))->assertForbidden();
        $this->actingAs($medico)->get(route('pagos.stripe.cancelado'))->assertForbidden();
    }

    private function detalleEsperado(Cita $cita, string $pago): string
    {
        return "Cita {$cita->codigo} registrada: {$this->paciente->nombre_completo} con {$this->medico->nombre_completo} el "
            .$cita->fecha->format('d/m/Y').' 09:00 (PRESENCIAL); pago: '.$pago;
    }

    /** Datos válidos de la cita de las 09:00 del lunes; cada prueba cambia lo que necesita. */
    private function datos(array $cambios = []): array
    {
        return $cambios + [
            'paciente_id' => $this->paciente->id, 'especialidad_id' => $this->especialidad->id, 'medico_id' => $this->medico->id,
            'fecha' => $this->lunes, 'hora_inicio' => '09:00', 'modalidad' => 'PRESENCIAL', 'motivo' => 'Control de rutina',
            'metodo_pago' => 'EFECTIVO',
        ];
    }

    private function urlRegistrar(?Paciente $paciente = null): string
    {
        return route('citas.registrar', [
            'paciente' => ($paciente ?? $this->paciente)->id, 'especialidad' => $this->especialidad->id,
            'medico' => $this->medico->id, 'fecha' => $this->lunes,
        ]);
    }
}
