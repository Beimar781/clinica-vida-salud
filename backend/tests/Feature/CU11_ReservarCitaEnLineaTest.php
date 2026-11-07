<?php

namespace Tests\Feature;

use App\Models\BloqueoAgenda;
use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\GestionCitas\Compartido\DisponibilidadService;
use App\Modules\GestionCitas\Compartido\HorarioOcupadoException;
use App\Modules\GestionCitas\Compartido\PagoException;
use App\Modules\GestionCitas\CU11_ReservarCitaEnLinea\ReservarCitaService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/** CU-11: Reservar cita en línea. Los métodos paso_N son las filas del plan de pruebas 8.1; el pago con tarjeta usa Http::fake() (nunca llama a Stripe). */
class CU11_ReservarCitaEnLineaTest extends SistemaTestCase
{
    private Usuario $usuario;

    private Paciente $paciente;

    private Especialidad $especialidad;

    private Medico $medico;

    private string $lunes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usuario = $this->crearUsuario('t-paciente-res', [Rol::PACIENTE]);
        $this->paciente = $this->crearPaciente(['usuario_id' => $this->usuario->id, 'nombres' => 'Reserva', 'apellidos' => 'Tprueba']);
        $this->especialidad = $this->crearEspecialidad('T-Reservas', precio: 120);
        $this->medico = $this->crearMedico(['ubicacion' => 'T-Sede Norte'], [$this->especialidad]);
        $this->crearHorario($this->medico, 1, '08:00', '12:00', 30);
        $this->lunes = $this->proximaFecha(1);
    }

    /** Paso 1: reservar una cita en línea (paciente, especialidad, médico y horario libre) → la cita queda registrada con código y estado Programada. */
    #[Test]
    public function paso_1_reservar_una_cita_en_linea(): void
    {
        $respuesta = $this->actingAs($this->usuario)->post(route('reservas.guardar'), $this->datos());

        $cita = Cita::where('medico_id', $this->medico->id)->firstOrFail();
        $respuesta->assertRedirect(route('reservas.comprobante', $cita))->assertSessionHas('exito');

        $this->assertMatchesRegularExpression('/^CM-\d{4}$/', $cita->codigo);
        $this->assertSame(Cita::PROGRAMADA, $cita->estado);
        $this->assertSame($this->paciente->id, $cita->paciente_id, 'La cita queda a nombre del paciente autenticado.');
        $this->assertSame($this->usuario->id, $cita->creado_por);
        $this->assertSame('09:00', $cita->hora_inicio_corta);
        $this->assertSame(Cita::PRESENCIAL, $cita->modalidad);
        $this->assertDatabaseHas('bitacora', ['accion' => 'CITA_RESERVADA', 'usuario_id' => $this->usuario->id]);

        $this->get(route('reservas.comprobante', $cita))->assertOk()
            ->assertSee($cita->codigo)->assertSee('Reserva Tprueba')->assertSee('09:00 – 09:30')->assertSee('Control de rutina')->assertSee('Programada');
    }

    /** Paso 2: reservar y pagar con tarjeta (reserva en línea con una tarjeta de prueba aprobada) → la cita queda Programada con el pago Pagado y la referencia de Stripe. */
    #[Test]
    public function paso_2_reservar_y_pagar_con_tarjeta_stripe(): void
    {
        $this->activarStripe();
        $this->fingirStripe();

        // El paciente confirma con tarjeta: el servidor valida y lo manda a la página de pago de Stripe (no se crea la cita todavía).
        $this->actingAs($this->usuario)->post(route('reservas.guardar'), $this->datos(['metodo_pago' => 'TARJETA']))
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_123');
        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());
        $this->assertSame('CU-11', session('pago_cita.cu'));

        // Vuelve de Stripe con el pago autorizado: el servidor lo comprueba, captura el pago y agenda.
        $respuesta = $this->get(route('pagos.stripe.retorno', ['session_id' => 'cs_test_123']));

        $cita = Cita::where('paciente_id', $this->paciente->id)->firstOrFail();
        $respuesta->assertRedirect(route('reservas.comprobante', $cita))
            ->assertSessionHas('exito', "Pago con tarjeta aprobado. Su cita {$cita->codigo} fue reservada correctamente.");
        $this->assertSame(Cita::PROGRAMADA, $cita->estado);
        $this->assertSame(Cita::METODO_TARJETA, $cita->metodo_pago);
        $this->assertSame(Cita::PAGO_PAGADO, $cita->estado_pago);
        $this->assertSame('pi_test_123', $cita->referencia_pago, 'Se guarda solo la referencia del pago de Stripe.');
        $this->assertNotNull($cita->fecha_pago);
        $this->assertEquals(120, $cita->monto);
        $this->assertSame($this->paciente->id, $cita->paciente_id);
        $this->assertSame(1, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/payment_intents/pi_test_123/capture'))->count());
        $this->assertDatabaseHas('bitacora', ['accion' => 'CITA_RESERVADA', 'usuario_id' => $this->usuario->id, 'detalle' => $this->detalleEsperado($cita, 'Tarjeta (Stripe), Pagado, Bs 120,00').' [pi_test_123]']);

        $this->get(route('reservas.comprobante', $cita))->assertOk()
            ->assertSee('Bs 120,00')->assertSee('Tarjeta (Stripe)')->assertSee('Pagado')->assertSee('pi_test_123');
    }

    /** Paso 3: reservar con una tarjeta rechazada (tarjeta de prueba rechazada por Stripe) → la cita no se registra y el paciente puede elegir otro método de pago. */
    #[Test]
    public function paso_3_reservar_con_una_tarjeta_rechazada(): void
    {
        $this->activarStripe();
        $this->fingirStripe(['estado' => 'requires_payment_method']); // Stripe no autorizó el pago (tarjeta rechazada)
        $this->actingAs($this->usuario)->post(route('reservas.guardar'), $this->datos(['metodo_pago' => 'TARJETA', 'motivo' => 'Dolor de cabeza']));

        $this->get(route('pagos.stripe.retorno', ['session_id' => 'cs_test_123']))
            ->assertRedirect($this->urlReservar())
            ->assertSessionHas('error', PagoException::MENSAJE_NO_AUTORIZADO)
            ->assertSessionHasInput('motivo', 'Dolor de cabeza')->assertSessionHasInput('metodo_pago', 'EFECTIVO');

        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count(), 'La cita no se registra.');
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/capture'));
        $this->assertDatabaseHas('bitacora', ['accion' => 'PAGO_TARJETA_FALLIDO', 'usuario_id' => $this->usuario->id]);

        // Puede elegir otro método de pago: efectivo.
        $this->post(route('reservas.guardar'), $this->datos(['metodo_pago' => 'EFECTIVO']))->assertSessionMissing('error');
        $this->assertSame(Cita::PAGO_PENDIENTE, Cita::where('paciente_id', $this->paciente->id)->firstOrFail()->estado_pago);
    }

    /** Paso 4: reservar eligiendo pagar en efectivo (reserva en línea con método Efectivo) → la cita queda Programada con el pago Pendiente. */
    #[Test]
    public function paso_4_reservar_eligiendo_pagar_en_efectivo(): void
    {
        Http::fake(); // el efectivo no habla con Stripe

        $this->actingAs($this->usuario)->post(route('reservas.guardar'), $this->datos(['metodo_pago' => 'EFECTIVO']))->assertRedirect();

        $cita = Cita::where('paciente_id', $this->paciente->id)->firstOrFail();
        $this->assertSame(Cita::PROGRAMADA, $cita->estado);
        $this->assertSame(Cita::METODO_EFECTIVO, $cita->metodo_pago);
        $this->assertSame(Cita::PAGO_PENDIENTE, $cita->estado_pago);
        $this->assertEquals(120, $cita->monto);
        $this->assertNull($cita->referencia_pago);
        $this->assertNull($cita->fecha_pago);
        Http::assertNothingSent();

        $this->get(route('reservas.comprobante', $cita))->assertOk()
            ->assertSee('Bs 120,00')->assertSee('Efectivo en la clínica')->assertSee('Pendiente');
        $this->assertDatabaseHas('bitacora', ['accion' => 'CITA_RESERVADA', 'detalle' => $this->detalleEsperado($cita, 'Efectivo en la clínica, Pendiente, Bs 120,00')]);
    }

    /** Paso 5: reservar un horario ocupado (horario tomado por otro usuario) → el sistema rechaza la reserva y muestra los horarios libres. */
    #[Test]
    public function paso_5_reservar_un_horario_ocupado(): void
    {
        $this->crearCita($this->medico, $this->crearPaciente(), $this->especialidad, $this->lunes, '09:00', '09:30');

        $this->actingAs($this->usuario)->post(route('reservas.guardar'), $this->datos())
            ->assertRedirect($this->urlReservar())
            ->assertSessionHas('error', HorarioOcupadoException::MENSAJE);

        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());
        $this->get($this->urlReservar())->assertOk()
            ->assertSee('El horario fue ocupado por otro usuario')->assertSee('09:30 – 10:00')->assertDontSee('09:00 – 09:30');
    }

    /** Paso 6: reservar con una cita en la misma hora (paciente con otra cita activa a esa hora) → el sistema no permite la reserva. */
    #[Test]
    public function paso_6_reservar_con_una_cita_en_la_misma_hora(): void
    {
        $otroMedico = $this->crearMedico([], [$this->especialidad]);
        $this->crearHorario($otroMedico, 1, '08:00', '12:00', 30);
        $this->crearCita($otroMedico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');

        $this->actingAs($this->usuario)->post(route('reservas.guardar'), $this->datos())
            ->assertSessionHas('error', 'Ya tiene una cita activa a esa hora. Elija otro horario.');

        $this->assertSame(0, Cita::where('medico_id', $this->medico->id)->count());
    }

    /** Paso 7: buscar médicos por especialidad y ubicación (especialidad y ubicación) → se listan solo los médicos activos que coinciden. */
    #[Test]
    public function paso_7_buscar_medicos_por_especialidad_y_ubicacion(): void
    {
        $this->crearMedico(['nombres' => 'Zona', 'apellidos' => 'Tsur', 'ubicacion' => 'T-Sede Sur'], [$this->especialidad]);
        $this->crearMedico(['nombres' => 'Baja', 'apellidos' => 'Tinactivo', 'ubicacion' => 'T-Sede Norte', 'estado' => false], [$this->especialidad]);
        $otra = $this->crearEspecialidad('T-Otra Especialidad');
        $this->crearMedico(['nombres' => 'Otra', 'apellidos' => 'Tespecialidad', 'ubicacion' => 'T-Sede Norte'], [$otra]);
        $this->actingAs($this->usuario);

        $this->get(route('reservas.crear', ['especialidad' => $this->especialidad->id, 'ubicacion' => 'T-Sede Norte']))->assertOk()
            ->assertSee($this->medico->nombre_completo)
            ->assertDontSee('Zona Tsur')->assertDontSee('Baja Tinactivo')->assertDontSee('Otra Tespecialidad');

        // Sin ubicación: todos los médicos activos de la especialidad.
        $this->get(route('reservas.crear', ['especialidad' => $this->especialidad->id]))->assertOk()
            ->assertSee($this->medico->nombre_completo)->assertSee('Zona Tsur')->assertDontSee('Baja Tinactivo');
    }

    #[Test]
    public function si_ningun_medico_coincide_lo_indica_y_permite_cambiar_la_busqueda(): void
    {
        $this->actingAs($this->usuario)->get(route('reservas.crear', ['especialidad' => $this->especialidad->id, 'ubicacion' => 'T-Sede Inexistente']))
            ->assertOk()->assertSee(ReservarCitaService::MENSAJE_SIN_MEDICOS)->assertSee('Buscar médicos');
    }

    #[Test]
    public function la_busqueda_muestra_el_proximo_horario_libre_de_cada_medico(): void
    {
        // El primer lunes que le queda libre al médico dentro de los próximos 14 días (puede ser antes de la fecha de las otras pruebas).
        $proximo = app(DisponibilidadService::class)->proximoHorarioLibre($this->medico);
        $this->assertNotNull($proximo);

        $this->actingAs($this->usuario)->get(route('reservas.crear', ['especialidad' => $this->especialidad->id]))
            ->assertOk()->assertSee('Próximo horario libre')->assertSee(Carbon::parse($proximo['fecha'])->format('d/m/Y').' · '.$proximo['inicio']);

        // Un médico sin horarios libres próximos lo dice.
        $sinHorario = $this->crearMedico(['nombres' => 'Sin', 'apellidos' => 'Thorario'], [$this->especialidad]);
        $this->get(route('reservas.crear', ['especialidad' => $this->especialidad->id]))->assertOk()->assertSee('Sin horarios libres en los próximos 14 días');
        $this->assertNull(app(DisponibilidadService::class)->proximoHorarioLibre($sinHorario));
    }

    #[Test]
    public function la_cita_siempre_queda_a_nombre_del_paciente_autenticado_aunque_se_envie_otro(): void
    {
        $otro = $this->crearPaciente(['nombres' => 'Ajeno', 'apellidos' => 'Tpaciente']);

        $this->actingAs($this->usuario)->post(route('reservas.guardar'), $this->datos(['paciente_id' => $otro->id]))->assertRedirect();

        $this->assertSame(1, Cita::where('paciente_id', $this->paciente->id)->count());
        $this->assertSame(0, Cita::where('paciente_id', $otro->id)->count());
    }

    #[Test]
    public function una_cuenta_sin_ficha_de_paciente_o_con_ficha_inactiva_no_puede_reservar(): void
    {
        $sinFicha = $this->crearUsuario('t-pac-sin-ficha', [Rol::PACIENTE]);

        $this->actingAs($sinFicha)->get(route('reservas.crear'))->assertOk()->assertSee(ReservarCitaService::MENSAJE_SIN_FICHA);
        $this->post(route('reservas.guardar'), $this->datos())->assertSessionHas('error', ReservarCitaService::MENSAJE_SIN_FICHA);

        $this->paciente->update(['estado' => false]);
        $this->actingAs($this->usuario)->post(route('reservas.guardar'), $this->datos())->assertSessionHas('error', ReservarCitaService::MENSAJE_FICHA_INACTIVA);
        $this->assertSame(0, Cita::where('medico_id', $this->medico->id)->count());
    }

    #[Test]
    public function no_se_reserva_en_fecha_pasada_ni_en_feriado_ni_fuera_del_horario(): void
    {
        $this->actingAs($this->usuario);

        $this->post(route('reservas.guardar'), $this->datos(['fecha' => Carbon::yesterday()->toDateString()]))
            ->assertSessionHas('error', DisponibilidadService::MENSAJE_FECHA_PASADA);

        $this->crearBloqueo(['fecha' => $this->lunes, 'motivo' => 'T-Feriado', 'estado' => true]);
        $this->post(route('reservas.guardar'), $this->datos())->assertSessionHas('error', fn ($m) => str_contains($m, 'feriado o día sin atención'));
        BloqueoAgenda::whereDate('fecha', $this->lunes)->delete();

        $this->post(route('reservas.guardar'), $this->datos(['hora_inicio' => '15:00']))
            ->assertSessionHas('error', 'La hora elegida está fuera del horario de atención del médico.');
        $this->post(route('reservas.guardar'), $this->datos(['fecha' => $this->proximaFecha(2)]))
            ->assertSessionHas('error', DisponibilidadService::MENSAJE_NO_ATIENDE);

        $this->assertSame(0, Cita::where('medico_id', $this->medico->id)->count());
    }

    #[Test]
    public function dos_pacientes_reservan_el_mismo_horario_una_gana_y_la_otra_ve_los_horarios_actualizados(): void
    {
        $usuario2 = $this->crearUsuario('t-paciente-res-2', [Rol::PACIENTE]);
        $this->crearPaciente(['usuario_id' => $usuario2->id, 'nombres' => 'Segunda', 'apellidos' => 'Tpaciente']);

        $this->actingAs($this->usuario)->get($this->urlReservar())->assertOk()->assertSee('09:00 – 09:30');
        $this->actingAs($usuario2)->get($this->urlReservar())->assertOk()->assertSee('09:00 – 09:30');

        $this->actingAs($this->usuario)->post(route('reservas.guardar'), $this->datos())->assertRedirect();
        $this->actingAs($usuario2)->post(route('reservas.guardar'), $this->datos())->assertSessionHas('error', HorarioOcupadoException::MENSAJE);

        $this->assertSame(1, Cita::where('medico_id', $this->medico->id)->where('estado', '<>', Cita::CANCELADA)->count());
        $this->get($this->urlReservar())->assertOk()->assertDontSee('09:00 – 09:30')->assertSee('09:30 – 10:00');
    }

    #[Test]
    public function el_formulario_se_valida(): void
    {
        $this->actingAs($this->usuario)->post(route('reservas.guardar'), ['modalidad' => 'X', 'fecha' => 'hoy', 'hora_inicio' => '9'])
            ->assertSessionHasErrors([
                'especialidad_id' => 'Seleccione la especialidad.', 'medico_id' => 'Seleccione el médico.', 'fecha' => 'La fecha no es válida.',
                'hora_inicio' => 'El horario seleccionado no es válido.', 'modalidad' => 'La modalidad debe ser presencial o virtual.',
                'metodo_pago' => 'Elija el método de pago: efectivo en la clínica o tarjeta.',
            ]);
    }

    #[Test]
    public function una_cita_virtual_queda_con_su_modalidad(): void
    {
        $this->actingAs($this->usuario)->post(route('reservas.guardar'), $this->datos(['modalidad' => 'VIRTUAL']))->assertRedirect();

        $this->assertSame(Cita::VIRTUAL, Cita::where('paciente_id', $this->paciente->id)->firstOrFail()->modalidad);
    }

    #[Test]
    public function la_pantalla_de_reserva_muestra_al_medico_los_horarios_y_el_formulario(): void
    {
        $this->actingAs($this->usuario)->get($this->urlReservar())->assertOk()
            ->assertSee($this->medico->nombre_completo)->assertSee('T-Sede Norte')->assertSee('Horarios libres (8)')->assertSee('Confirmar reserva');

        $this->get(route('reservas.reservar', ['especialidad' => $this->especialidad->id, 'medico' => $this->medico->id, 'fecha' => $this->proximaFecha(2)]))
            ->assertOk()->assertSee('El médico no atiende en esa fecha')->assertDontSee('Confirmar reserva');

        // Un médico inactivo o desconocido lleva de vuelta a la búsqueda.
        $this->medico->update(['estado' => false]);
        $this->get($this->urlReservar())->assertRedirect(route('reservas.crear'));
    }

    #[Test]
    public function cada_paciente_solo_ve_el_comprobante_de_sus_citas(): void
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');
        $otro = $this->crearUsuario('t-paciente-ajeno', [Rol::PACIENTE]);

        $this->actingAs($this->usuario)->get(route('reservas.comprobante', $cita))->assertOk()->assertSee($cita->codigo);
        $this->actingAs($otro)->get(route('reservas.comprobante', $cita))->assertForbidden();
    }

    #[Test]
    public function un_medico_que_tambien_es_paciente_puede_reservar(): void
    {
        $medicoPaciente = $this->crearUsuario('t-medico-paciente', [Rol::MEDICO, Rol::PACIENTE]);
        $this->crearPaciente(['usuario_id' => $medicoPaciente->id]);

        $this->actingAs($medicoPaciente)->post(route('reservas.guardar'), $this->datos())->assertRedirect();

        $this->assertSame(1, Cita::where('medico_id', $this->medico->id)->count());
    }

    #[Test]
    public function solo_el_paciente_reserva_en_linea(): void
    {
        $this->get(route('reservas.crear'))->assertRedirect('/login');

        foreach ([Rol::ADMINISTRADOR, Rol::RECEPCIONISTA, Rol::MEDICO] as $rol) {
            $usuario = $this->crearUsuario('t-sin-permiso-res-'.strtolower(substr($rol, 0, 3)), [$rol]);
            $this->actingAs($usuario)->get(route('reservas.crear'))->assertForbidden();
            $this->actingAs($usuario)->post(route('reservas.guardar'), $this->datos())->assertForbidden();
        }
        $this->assertSame(0, Cita::where('medico_id', $this->medico->id)->count());
    }

    // ------------------------------------------------------------------ Pago: efectivo y tarjeta (Stripe, siempre simulado)

    #[Test]
    public function el_metodo_de_pago_es_obligatorio_y_solo_admite_efectivo_o_tarjeta(): void
    {
        $sinMetodo = $this->datos();
        unset($sinMetodo['metodo_pago']);

        $this->actingAs($this->usuario)->post(route('reservas.guardar'), $sinMetodo)
            ->assertSessionHasErrors(['metodo_pago' => 'Elija el método de pago: efectivo en la clínica o tarjeta.']);
        $this->post(route('reservas.guardar'), $this->datos(['metodo_pago' => 'CHEQUE']))
            ->assertSessionHasErrors(['metodo_pago' => 'El método de pago debe ser efectivo en la clínica o tarjeta.']);
        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());
    }

    #[Test]
    public function el_formulario_muestra_el_monto_y_los_dos_metodos_de_pago(): void
    {
        $this->activarStripe();

        $this->actingAs($this->usuario)->get($this->urlReservar())->assertOk()
            ->assertSee('Método de pago')->assertSee('Monto a pagar')->assertSee('Bs 120,00')
            ->assertSee('Efectivo en la clínica')->assertSee('Tarjeta (Stripe)');
    }

    #[Test]
    public function sin_la_clave_de_stripe_la_tarjeta_esta_deshabilitada_con_aviso_y_el_efectivo_sigue_funcionando(): void
    {
        Http::fake();

        $pantalla = $this->actingAs($this->usuario)->get($this->urlReservar())->assertOk()
            ->assertSee('El pago con tarjeta no está disponible por ahora');
        $this->assertMatchesRegularExpression('/value="TARJETA"\s+disabled/', $pantalla->getContent());

        $this->post(route('reservas.guardar'), $this->datos(['metodo_pago' => 'TARJETA']))
            ->assertRedirect($this->urlReservar())->assertSessionHas('error', PagoException::MENSAJE_NO_DISPONIBLE);
        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());
        Http::assertNothingSent();

        $this->post(route('reservas.guardar'), $this->datos())->assertSessionMissing('error');
        $this->assertSame(1, Cita::where('paciente_id', $this->paciente->id)->count());
    }

    #[Test]
    public function si_el_paciente_cancela_el_pago_en_stripe_la_cita_no_se_registra_y_puede_elegir_otro_metodo(): void
    {
        $this->activarStripe();
        $this->fingirStripe();
        $this->actingAs($this->usuario)->post(route('reservas.guardar'), $this->datos(['metodo_pago' => 'TARJETA']));

        $this->get(route('pagos.stripe.cancelado'))
            ->assertRedirect($this->urlReservar())
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'No se cobró nada') && str_contains($m, 'pagar en efectivo'))
            ->assertSessionHasInput('hora_inicio', '09:00');

        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());
        $this->assertNull(session('pago_cita'));
        $this->assertDatabaseHas('bitacora', ['accion' => 'PAGO_TARJETA_FALLIDO', 'usuario_id' => $this->usuario->id]);
    }

    #[Test]
    public function si_el_horario_se_ocupa_durante_el_pago_se_cancela_la_autorizacion_y_no_se_cobra_nada(): void
    {
        $this->activarStripe();
        $this->fingirStripe();
        $this->actingAs($this->usuario)->post(route('reservas.guardar'), $this->datos(['metodo_pago' => 'TARJETA']));

        $this->crearCita($this->medico, $this->crearPaciente(), $this->especialidad, $this->lunes, '09:00', '09:30');

        $this->get(route('pagos.stripe.retorno', ['session_id' => 'cs_test_123']))
            ->assertRedirect($this->urlReservar())
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'ocupado por otro usuario') && str_contains($m, 'No se cobró nada'));

        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/capture'));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/payment_intents/pi_test_123/cancel');
        $this->get($this->urlReservar())->assertOk()->assertDontSee('09:00 – 09:30')->assertSee('09:30 – 10:00');
    }

    #[Test]
    public function si_el_paciente_ya_tiene_una_cita_a_esa_hora_no_se_manda_a_stripe(): void
    {
        $this->activarStripe();
        $this->fingirStripe();
        $otroMedico = $this->crearMedico([], [$this->especialidad]);
        $this->crearHorario($otroMedico, 1, '08:00', '12:00', 30);
        $this->crearCita($otroMedico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');

        $this->actingAs($this->usuario)->post(route('reservas.guardar'), $this->datos(['metodo_pago' => 'TARJETA']))
            ->assertSessionHas('error', 'Ya tiene una cita activa a esa hora. Elija otro horario.');

        Http::assertNothingSent();
    }

    #[Test]
    public function si_stripe_no_responde_se_muestra_un_aviso_claro_y_no_se_registra_la_cita(): void
    {
        $this->activarStripe();
        $this->fingirStripe(['crear' => 500]);

        $this->actingAs($this->usuario)->post(route('reservas.guardar'), $this->datos(['metodo_pago' => 'TARJETA']))
            ->assertRedirect($this->urlReservar())->assertSessionHas('error', 'No se pudo procesar el pago con tarjeta. Elija otro método de pago.');

        foreach (['Stripe', 'sk_test', 'api_error', 'SQLSTATE'] as $tecnico) {
            $this->assertStringNotContainsString($tecnico, session('error'));
        }
        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());
    }

    #[Test]
    public function si_la_captura_falla_la_cita_no_queda_registrada_y_se_cancela_la_autorizacion(): void
    {
        $this->activarStripe();
        $this->fingirStripe(['captura' => 402]);
        $this->actingAs($this->usuario)->post(route('reservas.guardar'), $this->datos(['metodo_pago' => 'TARJETA']));

        $this->get(route('pagos.stripe.retorno', ['session_id' => 'cs_test_123']))
            ->assertRedirect($this->urlReservar())->assertSessionHas('error', PagoException::MENSAJE_GENERAL);

        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/payment_intents/pi_test_123/cancel'));
    }

    #[Test]
    public function el_monto_enviado_a_stripe_es_el_precio_convertido_y_la_cita_guarda_siempre_bolivianos(): void
    {
        $this->activarStripe(6.96, 'usd');
        $this->fingirStripe();
        $this->actingAs($this->usuario)->post(route('reservas.guardar'), $this->datos(['metodo_pago' => 'TARJETA']));

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/checkout/sessions'
            && $r['line_items'][0]['price_data']['currency'] === 'usd'
            && (int) $r['line_items'][0]['price_data']['unit_amount'] === 1724); // Bs 120 / 6,96 = USD 17,24

        $this->get(route('pagos.stripe.retorno', ['session_id' => 'cs_test_123']));
        $this->assertEquals(120, Cita::where('paciente_id', $this->paciente->id)->firstOrFail()->monto);
    }

    #[Test]
    public function el_retorno_no_confia_en_la_url_la_sesion_de_pago_debe_ser_la_de_la_reserva(): void
    {
        $this->activarStripe();
        $this->fingirStripe();
        $this->actingAs($this->usuario)->post(route('reservas.guardar'), $this->datos(['metodo_pago' => 'TARJETA']));

        $this->get(route('pagos.stripe.retorno', ['session_id' => 'cs_otra_999']))->assertSessionHas('error', PagoException::MENSAJE_SESION_PERDIDA);
        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());

        $this->post(route('reservas.guardar'), $this->datos(['metodo_pago' => 'TARJETA']));
        $this->fingirStripe(['token' => 'token-de-otra-reserva']);
        $this->get(route('pagos.stripe.retorno', ['session_id' => 'cs_test_123']))->assertSessionHas('error', PagoException::MENSAJE_SESION_PERDIDA);
        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/capture'));
    }

    #[Test]
    public function el_pago_siempre_queda_a_nombre_de_la_ficha_de_la_cuenta_aunque_la_sesion_guarde_otra(): void
    {
        $this->activarStripe();
        $this->fingirStripe();
        $ajeno = $this->crearPaciente(['nombres' => 'Ajeno', 'apellidos' => 'Tficha']);

        $this->actingAs($this->usuario)->withSession(['pago_cita' => [
            'cu' => 'CU-11', 'token' => 'tk', 'session_id' => 'cs_test_123', 'actor_id' => $this->usuario->id,
            'paciente_id' => $ajeno->id, 'especialidad_id' => $this->especialidad->id, 'medico_id' => $this->medico->id,
            'fecha' => $this->lunes, 'hora_inicio' => '09:00', 'modalidad' => 'PRESENCIAL', 'motivo' => null, 'monto' => '120.00',
        ]]);
        $this->fingirStripe(['token' => 'tk', 'centavos' => 12000]);

        $this->get(route('pagos.stripe.retorno', ['session_id' => 'cs_test_123']))->assertSessionHas('error', PagoException::MENSAJE_SESION_PERDIDA);

        $this->assertSame(0, Cita::where('paciente_id', $ajeno->id)->count());
        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/capture'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/payment_intents/pi_test_123/cancel')); // no se cobra nada
    }

    #[Test]
    public function recargar_la_pagina_de_retorno_lleva_al_comprobante_de_la_reserva_sin_cobrar_otra_vez(): void
    {
        $this->activarStripe();
        $this->fingirStripe();
        $this->actingAs($this->usuario)->post(route('reservas.guardar'), $this->datos(['metodo_pago' => 'TARJETA']));
        $this->get(route('pagos.stripe.retorno', ['session_id' => 'cs_test_123']));
        $cita = Cita::where('paciente_id', $this->paciente->id)->firstOrFail();

        $this->get(route('pagos.stripe.retorno', ['session_id' => 'cs_test_123']))->assertRedirect(route('reservas.comprobante', $cita));

        $this->assertSame(1, Cita::where('paciente_id', $this->paciente->id)->count());
        $this->assertSame(1, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/capture'))->count());
    }

    #[Test]
    public function una_cuenta_sin_ficha_de_paciente_no_puede_pagar_con_tarjeta(): void
    {
        $this->activarStripe();
        $this->fingirStripe();
        $sinFicha = $this->crearUsuario('t-pac-sin-ficha-pago', [Rol::PACIENTE]);

        $this->actingAs($sinFicha)->post(route('reservas.guardar'), $this->datos(['metodo_pago' => 'TARJETA']))
            ->assertSessionHas('error', ReservarCitaService::MENSAJE_SIN_FICHA);

        Http::assertNothingSent();
    }

    #[Test]
    public function el_comprobante_de_la_reserva_muestra_el_monto_y_el_estado_del_pago(): void
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');
        $cita->update(['monto' => 120, 'metodo_pago' => 'TARJETA', 'estado_pago' => 'PAGADO', 'referencia_pago' => 'pi_ejemplo_1', 'fecha_pago' => now()]);

        $this->actingAs($this->usuario)->get(route('reservas.comprobante', $cita))->assertOk()
            ->assertSee('Monto')->assertSee('Bs 120,00')->assertSee('Tarjeta (Stripe)')->assertSee('Pagado')->assertSee('pi_ejemplo_1');
    }

    private function detalleEsperado(Cita $cita, string $pago): string
    {
        return "Cita {$cita->codigo} reservada en línea por {$this->paciente->nombre_completo} con {$this->medico->nombre_completo} el "
            .$cita->fecha->format('d/m/Y').' 09:00 (PRESENCIAL); pago: '.$pago;
    }

    /** Datos válidos de la reserva de las 09:00 del lunes; cada prueba cambia lo que necesita. */
    private function datos(array $cambios = []): array
    {
        return $cambios + [
            'especialidad_id' => $this->especialidad->id, 'medico_id' => $this->medico->id, 'fecha' => $this->lunes,
            'hora_inicio' => '09:00', 'modalidad' => 'PRESENCIAL', 'motivo' => 'Control de rutina', 'metodo_pago' => 'EFECTIVO',
        ];
    }

    private function urlReservar(): string
    {
        return route('reservas.reservar', ['especialidad' => $this->especialidad->id, 'medico' => $this->medico->id, 'fecha' => $this->lunes]);
    }
}
